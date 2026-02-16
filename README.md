# Beginly Secrets Manager

AWS Secrets Manager integration for Laravel applications with environment-based credential rotation.

## Features

- **Environment-based authentication:** Works with AWS IAM roles or access keys
- **Generic secret fetching:** Returns entire secret as array - use any keys you need for any purpose
- **Intelligent rotation detection:** Automatically detects rotation schedules and minimizes AWS API calls
- **Smart caching:** Dynamic TTL based on rotation schedule - caches for months when rotation is far away
- **Encrypted caching:** Secrets are encrypted using Laravel's encryption before caching
- **Structure-agnostic:** No validation or assumptions about secret contents - works with ANY JSON structure
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
AWS_SECRETS_ROTATION_BUFFER_DAYS=7

# Your secret names (examples for any use case)
AWS_SECRETS_DB_NAME=your-app/database/production
AWS_SECRETS_API_KEY=your-app/api-keys/production
AWS_SECRETS_OAUTH=your-app/oauth-credentials/production
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
    'secrets_rotation_buffer_days' => env('AWS_SECRETS_ROTATION_BUFFER_DAYS', 7),
    'secrets_force_enabled' => env('AWS_SECRETS_FORCE_ENABLED', false),
],
```

## Secret Structure

**The package is completely structure-agnostic.** Secrets can contain ANY valid JSON structure. The package simply:
1. Fetches the secret from AWS Secrets Manager
2. Parses the JSON
3. Returns the entire structure as an associative array
4. Your application decides what to do with the data

### Example Structures

**Database Credentials:**
```json
{
    "username": "admin_user",
    "password": "rotating_password"
}
```

**API Keys:**
```json
{
    "api_key": "sk-1234567890abcdef",
    "api_secret": "secret-key-here",
    "webhook_secret": "whsec_1234567890"
}
```

**OAuth Credentials:**
```json
{
    "client_id": "oauth-client-id",
    "client_secret": "oauth-client-secret",
    "redirect_uri": "https://example.com/callback"
}
```

**Complex Application Config:**
```json
{
    "database": {
        "username": "db_user",
        "password": "db_pass"
    },
    "redis": {
        "password": "redis_pass"
    },
    "smtp": {
        "username": "smtp_user",
        "password": "smtp_pass"
    }
}
```

**Any JSON structure works** - the package doesn't validate or require any specific fields.

## Usage

### Basic Concept

**IMPORTANT:** This package does NOT automatically configure anything in your application. It simply fetches secrets and returns them as arrays. You decide how to use the data.

### Example 1: Database Credentials

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

### Example 2: API Keys and External Services

```php
public function boot(): void
{
    if (App::environment() !== 'local') {
        $service = app(SecretsManagerService::class);

        // Fetch API credentials
        $apiSecrets = $service->getSecret(config('services.aws.api_secrets_name'));

        Config::set('services.stripe.key', $apiSecrets['stripe_key']);
        Config::set('services.stripe.secret', $apiSecrets['stripe_secret']);
        Config::set('services.openai.api_key', $apiSecrets['openai_key']);
    }
}
```

### Example 3: Multiple Credentials with Environment Detection

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
        return; // Use .env values
    }

    // Fetch from AWS
    $service = app(SecretsManagerService::class);

    // Database credentials
    $dbSecret = $service->getSecret(config('services.aws.db_secret_name'));
    Config::set('database.connections.pgsql.username', $dbSecret['username']);
    Config::set('database.connections.pgsql.password', $dbSecret['password']);

    // Application secrets
    $appSecrets = $service->getSecret(config('services.aws.app_secrets_name'));
    Config::set('app.key', $appSecrets['app_key']);
    Config::set('services.mailgun.secret', $appSecrets['mailgun_secret']);
}
```

### Example 4: Complex Nested Structures

```php
public function boot(): void
{
    if (App::environment() !== 'local') {
        $service = app(SecretsManagerService::class);

        // Fetch complex secret with nested structure
        $secrets = $service->getSecret('production/all-credentials');

        // Access nested values
        Config::set('database.connections.pgsql.username', $secrets['database']['username']);
        Config::set('database.connections.pgsql.password', $secrets['database']['password']);
        Config::set('cache.stores.redis.password', $secrets['redis']['password']);
        Config::set('mail.password', $secrets['smtp']['password']);
    }
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
- Intelligent rotation detection with dynamic caching
- `clearCache()` method for manual cache invalidation
- `getRotationMetadata()` method for inspecting rotation schedules

**The package does NOT provide:**
- Automatic configuration of anything in your app
- Validation of secret structure or contents
- Assumptions about what the secret contains
- Hardcoded environment detection logic

**Why?**
Every Laravel application has different needs:
- Different types of secrets (DB, API keys, OAuth, certificates, etc.)
- Different secret structures
- Different environment detection requirements
- Different use cases beyond just databases

By keeping the package completely structure-agnostic, it works for ANY use case.

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

## Security

### Encrypted Caching

**All secrets are encrypted before being stored in Laravel's cache.** This provides defense-in-depth security:

- Secrets are encrypted using Laravel's `Crypt` facade (AES-256-CBC encryption)
- Encryption key is your application's `APP_KEY`
- Even if cache storage is compromised, secrets remain protected
- Automatic decryption on retrieval with error handling

**Cache Security Model:**
- ✅ Secrets encrypted at rest in cache (database, Redis, file, etc.)
- ✅ Rotation metadata is not encrypted (contains no sensitive data)
- ✅ Graceful handling of decryption failures (automatic refetch)
- ✅ Same encryption used throughout Laravel applications

**Important:** Protect your `APP_KEY` - it's used to encrypt cached secrets. If `APP_KEY` is rotated, cached secrets will be automatically refetched from AWS.

## How It Works

1. **Package Provider Registers Service:** `SecretsManagerServiceProvider` registers `SecretsManagerService` as singleton
2. **Application Boot:** Your `AppServiceProvider::boot()` method runs
3. **Environment Check:** Your app detects `APP_ENV` value and decides whether to fetch secrets
4. **Fetch Secret:** Your app calls `SecretsManagerService::getSecret()`
5. **Service Fetches:** Service fetches from AWS (or returns encrypted cached value)
6. **Decrypt & Return:** Service decrypts cached secrets before returning them
7. **Extract Values:** Your app extracts the values it needs from the secret (any structure)
8. **Update Config:** Your app sets `Config::set()` for whatever you need (DB, API keys, etc.)
9. **Intelligent Cache:** Service encrypts and caches based on rotation schedule (short-term during rotation, long-term when far away)
10. **Use Secrets:** Your application uses the configured values

## Intelligent Credential Rotation Detection

The package includes advanced rotation detection that minimizes AWS API calls while ensuring credentials are always current:

### How It Works

1. **Rotation Schedule Tracking**: Fetches `NextRotationDate` from AWS Secrets Manager
2. **Dynamic Cache TTL**: Automatically adjusts cache duration based on rotation schedule:
   - **Rotation far away** (> 7 days): Caches secret until rotation buffer period starts
   - **Rotation imminent** (≤ 7 days): Uses short TTL (5 minutes) for frequent checks
   - **Maximum cache**: Capped at 30 days to prevent excessive cache times

3. **Buffer Period**: Configurable period before rotation (default: 7 days) when checks become more frequent
4. **Automatic Updates**: Detects rotated credentials and updates cache automatically

### Configuration

```env
# Short TTL used during rotation buffer period (in seconds)
AWS_SECRETS_CACHE_TTL=300

# Days before rotation to start checking more frequently
AWS_SECRETS_ROTATION_BUFFER_DAYS=7
```

### Example: Long Rotation Schedule

If your secret rotates once per year (like your example: next rotation December 2026):

1. **Far from rotation** (Jan 2026 - Nov 2026):
   - Secret cached for months at a time
   - Minimal AWS API calls (1 call every 30 days)

2. **Entering buffer period** (7 days before rotation):
   - Cache TTL switches to 5 minutes
   - Checks every hour for rotation completion

3. **After rotation occurs**:
   - New credentials detected and cached immediately
   - Returns to long-term caching

### Benefits

- **Drastically reduced AWS API costs** for infrequent rotations
- **No manual cache clearing** needed after rotation
- **Automatic detection** of rotation completion
- **Seamless credential updates** without downtime
- **Configurable buffer period** to match your needs

### Manual Cache Control

```bash
# Force immediate credential refresh
php artisan cache:clear

# Or clear specific secret only
$service->clearCache('your-secret-name');
```

### Monitoring Rotation Status

```php
$service = app(SecretsManagerService::class);
$metadata = $service->getRotationMetadata('your-secret-name');

// Returns:
// [
//     'rotation_enabled' => true,
//     'next_rotation' => '2026-12-07 23:59:59',
//     'last_rotated' => '2026-01-05 09:37:09',
//     'last_checked' => '2026-02-16 10:30:00',
// ]
```

## Troubleshooting

### Application Fails to Start

**Error:** "Failed to fetch [secret-name] from AWS Secrets Manager"

**Solutions:**
1. Verify `APP_ENV` is set correctly
2. Check IAM permissions (`secretsmanager:GetSecretValue` and `secretsmanager:DescribeSecret`)
3. Verify secret names match `.env` configuration
4. Confirm AWS region is correct
5. Check application logs: `storage/logs/laravel.log`

### Invalid JSON in Secret

**Error:** "Secret contains invalid JSON"

**Solution:** Verify the secret in AWS Secrets Manager contains valid JSON. Use the AWS console to validate the JSON syntax.

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
- Display all keys in the secret (values are masked for security)
- Show the secret structure without exposing sensitive values
- Show troubleshooting tips on failure

## Reusing in Other Applications

To use this package in another Laravel application:

1. Copy entire `packages/beginly/secrets-manager/` directory
2. Add path repository to app's `composer.json`
3. Run `composer update beginly/secrets-manager`
4. Register service provider in `bootstrap/providers.php`
5. Configure secrets in `.env`
6. **Add configuration logic to your AppServiceProvider** (see Usage section above)

The package is designed to be generic and reusable across any Laravel application for ANY type of secret. Each application controls how it uses the secrets by calling the service from its own `AppServiceProvider::boot()` method.

## License

MIT
