# Changelog

All notable changes to `beginly/secrets-manager` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
