# Beginly Secrets Manager

AWS Secrets Manager integration for Laravel applications with environment-based credential rotation.

## Features

- **Environment-based credential fetching:** Works with AWS IAM roles or access keys
- **Generic secret fetching:** Returns entire secret as array - use any keys you need
- **Built-in caching:** 5-minute TTL reduces AWS API calls
- **Flexible structure:** Works with any secret structure - no validation, no restrictions
- **Laravel integration:** Register as singleton, use in any service provider

## Installation

### Step 1: Add Package to Your Laravel App

Add to your Laravel app's `composer.json`:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "./packages/beginly/secrets-manager"
        }
    ],
    "require": {
        "beginly/secrets-manager": "@dev"
    }
}
```

### Step 2: Install Package

```bash
composer update beginly/secrets-manager
```

### Step 3: Register Service Provider

Add to `bootstrap/providers.php`:

```php
return [
    App\Providers\AppServiceProvider::class,
    Beginly\SecretsManager\SecretsManagerServiceProvider::class,
];
```

## Configuration

### Environment Variables

Add to your `.env` file:

```env
# AWS Secrets Manager Configuration
AWS_SECRETS_REGION=us-east-1
AWS_SECRETS_CACHE_TTL=300

# Your secret names (example)
AWS_SECRETS_DB_NAME=your-app/database/production

# Connection details remain in .env
DB_HOST=your-db-host.com
DB_PORT=3306
DB_DATABASE=your_database
# DB_USERNAME and DB_PASSWORD will be fetched from Secrets Manager
```

### Laravel Services Config

Add to `config/services.php`:

```php
'aws' => [
    'key' => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
    'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),

    // Secrets Manager configuration
    'db_secret_name' => env('AWS_SECRETS_DB_NAME'),
    'secrets_region' => env('AWS_SECRETS_REGION', env('AWS_DEFAULT_REGION', 'us-east-1')),
    'secrets_cache_ttl' => env('AWS_SECRETS_CACHE_TTL', 300),
    'secrets_force_enabled' => env('AWS_SECRETS_FORCE_ENABLED', false),
],
```

## Secret Structure

Secrets in AWS Secrets Manager must contain username and password (extra fields are allowed but ignored):

### Minimum Required Structure
```json
{
    "username": "admin_user",
    "password": "rotating_password"
}
```

### RDS-Managed Secrets (Extra Fields Ignored)
```json
{
    "username": "admin_user",
    "password": "rotating_password",
    "engine": "postgres",
    "host": "example.rds.amazonaws.com",
    "port": 5432,
    "dbClusterIdentifier": "cluster-id"
}
```

**Note:** Only `username` and `password` are extracted and used. All other fields (host, port, engine, etc.) are ignored. This allows compatibility with both simple secrets and RDS-managed secrets with automatic rotation.

**Important:** Host, port, and database values remain in `.env` files. Only credentials rotate via Secrets Manager.

## Usage

### Basic Setup

**IMPORTANT:** This package does NOT automatically configure your databases. You must add configuration logic to your application's service provider.

### Configuring Database Credentials

Add to your `app/Providers/AppServiceProvider.php`:

```php
use Beginly\SecretsManager\SecretsManagerService;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;

public function boot(): void
{
    // Skip in local/testing environments
    if (App::environment() !== 'local' && App::environment() !== 'testing') {
        $service = app(SecretsManagerService::class);

        // Fetch secret and extract what you need
        $secret = $service->getSecret(config('services.aws.db_secret_name'));

        Config::set('database.connections.mysql.username', $secret['username']);
        Config::set('database.connections.mysql.password', $secret['password']);
    }

    // Your other boot logic...
}
```

### Example: Multiple Databases (beginly-admin)

```php
public function boot(): void
{
    if (App::environment() === 'production' || App::environment() === 'staging') {
        $service = app(SecretsManagerService::class);

        // Admin database
        $adminSecret = $service->getSecret(config('services.aws.admin_db_secret_name'));
        Config::set('database.connections.pgsql.username', $adminSecret['username']);
        Config::set('database.connections.pgsql.password', $adminSecret['password']);

        // Beginly database
        $beginlySecret = $service->getSecret(config('services.aws.beginly_db_secret_name'));
        Config::set('database.connections.beginly.username', $beginlySecret['username']);
        Config::set('database.connections.beginly.password', $beginlySecret['password']);
    }
}
```

### Example: Single Database with Force Enable for Local Testing

```php
public function boot(): void
{
    $environment = App::environment();
    $forceEnabled = config('services.aws.secrets_force_enabled', false);

    // Skip in testing, optionally skip in local
    if ($environment === 'testing') {
        return;
    }

    if ($environment === 'local' && !$forceEnabled) {
        return; // Use .env credentials
    }

    // Fetch from AWS
    $service = app(SecretsManagerService::class);
    $secret = $service->getSecret(config('services.aws.db_secret'));

    Config::set('database.connections.mysql.username', $secret['username']);
    Config::set('database.connections.mysql.password', $secret['password']);
}
```

### Testing Locally

To test Secrets Manager integration in local environment:

1. Set `AWS_SECRETS_FORCE_ENABLED=true` in `.env`
2. Configure AWS credentials (access keys)
3. Create test secrets in AWS
4. Update your AppServiceProvider to fetch when force enabled
5. Run application and verify credentials are fetched

## Design Philosophy

This package is designed as a **utility library**, not an opinionated framework.

**The package provides:**
- `SecretsManagerService` with `getSecret()` method - fetches and returns entire secret as array
- Built-in caching (5-minute TTL) and comprehensive error handling
- `clearCache()` method for manual cache invalidation

**The package does NOT provide:**
- Automatic database configuration
- Assumptions about your connection names
- Hardcoded environment detection logic

**Why?**
Every Laravel application has different needs:
- Different database connection names
- Different number of databases
- Different environment detection requirements
- Different secret naming conventions

By keeping the package generic, it works with ANY Laravel app's architecture.

### AWS IAM Permissions

Your EC2/ECS/Lambda instances need this IAM policy:

```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Effect": "Allow",
            "Action": "secretsmanager:GetSecretValue",
            "Resource": [
                "arn:aws:secretsmanager:REGION:ACCOUNT:secret:your-secret-name-*"
            ]
        }
    ]
}
```

## How It Works

1. **Package Provider Registers Service:** `SecretsManagerServiceProvider` registers `SecretsManagerService` as singleton
2. **Application Boot:** Your `AppServiceProvider::boot()` method runs
3. **Environment Check:** Your app detects `APP_ENV` value and decides whether to fetch secrets
4. **Fetch Secret:** Your app calls `SecretsManagerService::getSecret()`
5. **Service Fetches:** Service fetches from AWS (or returns cached value)
6. **Extract Values:** Your app extracts the values it needs from the secret (e.g., username, password)
7. **Update Config:** Your app sets `Config::set()` for database connections
8. **Cache:** Service stores secret for 5 minutes (configurable)
9. **Connect:** Laravel connects to database with fetched credentials

## Credential Rotation

- Credentials are cached for 5 minutes (configurable via `AWS_SECRETS_CACHE_TTL`)
- After rotation in AWS, new credentials take effect within cache TTL
- No application restart required
- Use `php artisan cache:clear` to force immediate credential refresh

## Troubleshooting

### Application Fails to Start

**Error:** "Failed to fetch admin database credentials from AWS Secrets Manager"

**Solutions:**
1. Verify `APP_ENV` is set correctly
2. Check IAM permissions (`secretsmanager:GetSecretValue`)
3. Verify secret names match `.env` configuration
4. Confirm AWS region is correct
5. Check application logs: `storage/logs/laravel.log`

### Missing Required Fields

**Error:** "Database secret is missing required fields: username" or "...password"

**Solution:** Ensure secret contains both `username` and `password` fields. Extra fields are allowed and will be ignored.

### Testing Mode Not Working

**Error:** Secrets Manager not fetching in local environment

**Solution:** Set `AWS_SECRETS_FORCE_ENABLED=true` in `.env` and configure AWS credentials.

## Development

### Running Tests

The package includes comprehensive unit and feature tests using PHPUnit with Orchestra Testbench for Laravel support.

#### Install Development Dependencies

From the package directory:

```bash
cd packages/beginly/secrets-manager
composer install
```

#### Run Test Suite

```bash
# Run all tests
./vendor/bin/phpunit

# Run only unit tests
./vendor/bin/phpunit --testsuite=Unit

# Run only feature tests
./vendor/bin/phpunit --testsuite=Feature

# Run with coverage (requires Xdebug)
./vendor/bin/phpunit --coverage-html coverage
```

#### Test Command

Test AWS Secrets Manager connectivity from your Laravel app:

```bash
# Test with configured secret name
sail artisan secrets:test

# Test with specific secret name
sail artisan secrets:test your-secret-name
```

The command will:
- Fetch the secret from AWS Secrets Manager
- Display available keys (values are hidden for security)
- Show database credentials if username/password keys are present
- Show troubleshooting tips on failure

## Reusing in Other Applications

To use this package in another Laravel application:

1. Copy entire `packages/beginly/secrets-manager/` directory
2. Add path repository to app's `composer.json`
3. Run `composer update beginly/secrets-manager`
4. Register service provider in `bootstrap/providers.php`
5. Configure secrets in `.env`
6. **Add configuration logic to your AppServiceProvider** (see Usage section above)

The package is designed to be generic and reusable across any Laravel application. Each application controls its own database configuration by calling the service from its own `AppServiceProvider::boot()` method.

## License

MIT
