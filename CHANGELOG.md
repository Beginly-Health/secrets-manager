# Changelog

All notable changes to `beginly/secrets-manager` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
