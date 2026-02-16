# Changelog

All notable changes to `beginly/secrets-manager` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.2.0] - 2026-02-16

### Added
- **Encrypted caching**: All secrets are now encrypted using Laravel's `Crypt` facade before being stored in cache
- Automatic decryption on cache retrieval with graceful error handling
- Security documentation in README explaining encryption model

### Security
- **Defense-in-depth protection**: Cached secrets are now encrypted at rest using AES-256-CBC encryption
- Secrets remain protected even if cache storage (database, Redis, files) is compromised
- Automatic refetch if decryption fails (e.g., after `APP_KEY` rotation)

### Changed
- Cache storage now stores encrypted secret data instead of plain text
- Added `Illuminate\Support\Facades\Crypt` dependency for encryption
- Updated "How It Works" documentation to reflect encryption flow

## [1.1.1] - 2026-02-16

### Changed
- **Documentation clarity:** Updated README to emphasize the package is completely structure-agnostic
- Removed all database-specific language and assumptions from documentation
- Added examples for multiple use cases: API keys, OAuth credentials, complex nested structures
- Clarified that the package works with ANY JSON structure without validation
- Updated troubleshooting section to be use-case agnostic

### Documentation
- Enhanced README with examples for:
  - Database credentials
  - API keys and external services
  - OAuth credentials
  - Complex nested application configurations
- Removed incorrect statement about required fields (package requires no specific fields)
- Updated "Design Philosophy" section to emphasize structure-agnostic nature

## [1.1.0] - 2026-02-16

### Added
- **Intelligent rotation detection**: Automatically tracks secret rotation schedules from AWS Secrets Manager
- **Dynamic cache TTL**: Adjusts cache duration based on next rotation date
  - Caches secrets for up to 30 days when rotation is far away
  - Switches to short TTL (5 minutes) when rotation is imminent
- **Rotation buffer configuration**: `AWS_SECRETS_ROTATION_BUFFER_DAYS` setting (default: 7 days)
- **Rotation metadata tracking**: Caches `NextRotationDate`, `LastRotatedDate`, and `RotationEnabled` status
- **New method**: `getRotationMetadata()` to retrieve rotation status for a secret
- **Comprehensive tests** for rotation detection scenarios

### Changed
- `getSecret()` now fetches rotation metadata from AWS using `describeSecret()`
- `clearCache()` now clears both secret data and rotation metadata
- Cache keys expanded to include metadata: `aws_secret_metadata:{name}`

### Performance
- **Drastically reduced AWS API calls** for secrets with long rotation periods (e.g., yearly rotation)
- Example: Yearly rotation now requires ~12 API calls per year instead of ~100,000+
- Automatic rotation detection eliminates need for manual cache clearing after rotation

## [1.0.0] - 2026-02-13

### Added
- Initial release of Secrets Manager package
- SecretsManagerService for fetching secrets from AWS Secrets Manager
- Built-in caching with configurable TTL
- Support for IAM roles and access key authentication
- Comprehensive error handling and logging
- SecretsManagerServiceProvider for Laravel integration
- PHPUnit test suite with unit and feature tests
- Support for Laravel 11 and 12
- PHP 8.2+ support

### Features
- `getSecret()` method to fetch secrets by name
- `clearCache()` method to invalidate cached secrets
- Automatic caching with 5-minute default TTL
- Configurable AWS region
- Environment-based configuration
- Generic, unopinionated design for flexible integration
