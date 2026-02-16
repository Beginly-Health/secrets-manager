<?php

declare(strict_types=1);

namespace Beginly\SecretsManager;

use Aws\Exception\AwsException;
use Aws\SecretsManager\SecretsManagerClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * SecretsManagerService
 *
 * Fetches and caches secrets from AWS Secrets Manager with intelligent rotation detection.
 * Supports IAM role authentication (production) and access key authentication (local).
 *
 * Rotation Detection:
 * - Tracks next rotation date from AWS Secrets Manager
 * - Only checks AWS when rotation is imminent (within buffer period)
 * - Minimizes API calls for long rotation schedules (e.g., yearly)
 */
class SecretsManagerService
{
    private SecretsManagerClient $client;

    private int $cacheTtl;

    private int $rotationBufferDays;

    public function __construct()
    {
        $this->cacheTtl = (int) \config('services.aws.secrets_cache_ttl', 300);
        $this->rotationBufferDays = (int) \config('services.aws.secrets_rotation_buffer_days', 7);

        $this->client = new SecretsManagerClient([
            'version' => 'latest',
            'region' => \config('services.aws.secrets_region', 'us-east-1'),
        ]);
    }

    /**
     * Get secret value from AWS Secrets Manager with intelligent rotation detection
     *
     * This method implements a multi-tier caching strategy:
     * 1. Short-term cache (default 5 minutes): Used when rotation is imminent
     * 2. Long-term cache (until rotation date): Used when rotation is far away
     * 3. Rotation metadata cache: Tracks next rotation date to minimize API calls
     *
     * @param string $secretName The name/ARN of the secret
     * @return array<string, mixed> The secret value as associative array
     * @throws \RuntimeException If secret cannot be fetched or parsed
     */
    public function getSecret(string $secretName): array
    {
        $cacheKey = "aws_secret:{$secretName}";
        $metadataKey = "aws_secret_metadata:{$secretName}";

        // Check if we have cached secret data
        $encryptedCached = Cache::get($cacheKey);
        $metadata = Cache::get($metadataKey);

        // Decrypt cached secret if it exists
        $cached = null;
        if ($encryptedCached !== null) {
            try {
                $cached = Crypt::decrypt($encryptedCached);
            } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
                Log::warning("AWS Secrets Manager: Failed to decrypt cached secret, refetching", [
                    'secret' => $secretName,
                    'error' => $e->getMessage(),
                ]);
                // Cache is corrupted, clear it and refetch
                Cache::forget($cacheKey);
                Cache::forget($metadataKey);
                return $this->fetchAndCacheSecret($secretName);
            }
        }

        // If we have cached secret, check if we need to validate rotation status
        if ($cached !== null && $metadata !== null) {
            $nextRotation = $metadata['next_rotation'] ?? null;
            $lastChecked = $metadata['last_checked'] ?? null;

            if ($nextRotation !== null && $lastChecked !== null) {
                $now = new \DateTimeImmutable();
                $rotationDate = new \DateTimeImmutable($nextRotation);
                $bufferDate = $rotationDate->modify("-{$this->rotationBufferDays} days");

                // If we're not yet in the rotation buffer period, use cached secret
                if ($now < $bufferDate) {
                    Log::debug("AWS Secrets Manager: Using cached secret (rotation not imminent)", [
                        'secret' => $secretName,
                        'next_rotation' => $nextRotation,
                        'buffer_start' => $bufferDate->format('Y-m-d H:i:s'),
                        'days_until_buffer' => $now->diff($bufferDate)->days,
                    ]);

                    return $cached;
                }

                // We're within rotation buffer period - check if rotation has occurred
                if ($now < $rotationDate) {
                    // Within buffer but before rotation date - check less frequently
                    $hoursSinceCheck = $now->getTimestamp() - (new \DateTimeImmutable($lastChecked))->getTimestamp();
                    $hoursSinceCheck = (int) ($hoursSinceCheck / 3600);

                    if ($hoursSinceCheck < 1) {
                        Log::debug("AWS Secrets Manager: Using cached secret (recently checked)", [
                            'secret' => $secretName,
                            'hours_since_check' => $hoursSinceCheck,
                            'next_rotation' => $nextRotation,
                        ]);

                        return $cached;
                    }
                }

                Log::info("AWS Secrets Manager: Rotation imminent or occurred, checking for updates", [
                    'secret' => $secretName,
                    'next_rotation' => $nextRotation,
                    'within_buffer' => $now >= $bufferDate,
                    'past_rotation' => $now >= $rotationDate,
                ]);
            }
        }

        // Fetch secret from AWS
        return $this->fetchAndCacheSecret($secretName);
    }

    /**
     * Fetch secret from AWS and cache with rotation metadata
     *
     * @param string $secretName The name/ARN of the secret
     * @return array<string, mixed> The secret value as associative array
     * @throws \RuntimeException If secret cannot be fetched or parsed
     */
    private function fetchAndCacheSecret(string $secretName): array
    {
        $cacheKey = "aws_secret:{$secretName}";
        $metadataKey = "aws_secret_metadata:{$secretName}";

        try {
            Log::info("AWS Secrets Manager: Fetching secret from AWS", ['secret' => $secretName]);

            // Fetch secret value
            $result = $this->client->getSecretValue([
                'SecretId' => $secretName,
            ]);

            // Parse JSON secret string
            if (!isset($result['SecretString'])) {
                throw new \RuntimeException(
                    \sprintf('Secret "%s" does not contain SecretString field', $secretName)
                );
            }

            $secretData = \json_decode($result['SecretString'], true, 512, \JSON_THROW_ON_ERROR);

            if (!\is_array($secretData)) {
                throw new \RuntimeException(
                    \sprintf('Secret "%s" is not valid JSON or is not an object', $secretName)
                );
            }

            // Fetch rotation metadata
            $metadata = $this->fetchRotationMetadata($secretName);

            // Determine cache TTL based on rotation schedule
            $cacheTtl = $this->calculateCacheTtl($metadata['next_rotation'] ?? null);

            // Encrypt and cache the secret (metadata is not sensitive)
            $encryptedData = Crypt::encrypt($secretData);
            Cache::put($cacheKey, $encryptedData, $cacheTtl);
            Cache::put($metadataKey, $metadata, $cacheTtl);

            Log::info("AWS Secrets Manager: Successfully fetched and cached secret", [
                'secret' => $secretName,
                'cache_ttl_seconds' => $cacheTtl,
                'next_rotation' => $metadata['next_rotation'] ?? 'none',
                'rotation_enabled' => $metadata['rotation_enabled'] ?? false,
            ]);

            return $secretData;
        } catch (AwsException $e) {
            $errorCode = $e->getAwsErrorCode();
            $errorMessage = $e->getAwsErrorMessage();

            Log::error("AWS Secrets Manager: Failed to fetch secret", [
                'secret' => $secretName,
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
            ]);

            throw new \RuntimeException(
                \sprintf(
                    'Failed to fetch secret "%s" from AWS Secrets Manager: [%s] %s',
                    $secretName,
                    $errorCode,
                    $errorMessage
                ),
                0,
                $e
            );
        } catch (\JsonException $e) {
            Log::error("AWS Secrets Manager: Invalid JSON in secret", [
                'secret' => $secretName,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                \sprintf('Secret "%s" contains invalid JSON: %s', $secretName, $e->getMessage()),
                0,
                $e
            );
        }
    }

    /**
     * Fetch rotation metadata from AWS Secrets Manager
     *
     * @param string $secretName The name/ARN of the secret
     * @return array<string, mixed> Metadata including next rotation date
     */
    private function fetchRotationMetadata(string $secretName): array
    {
        try {
            $description = $this->client->describeSecret([
                'SecretId' => $secretName,
            ]);

            $rotationEnabled = $description['RotationEnabled'] ?? false;
            $nextRotationDate = $description['NextRotationDate'] ?? null;
            $lastRotatedDate = $description['LastRotatedDate'] ?? null;

            $metadata = [
                'rotation_enabled' => $rotationEnabled,
                'last_checked' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ];

            if ($nextRotationDate instanceof \DateTimeInterface) {
                $metadata['next_rotation'] = $nextRotationDate->format('Y-m-d H:i:s');
            }

            if ($lastRotatedDate instanceof \DateTimeInterface) {
                $metadata['last_rotated'] = $lastRotatedDate->format('Y-m-d H:i:s');
            }

            return $metadata;
        } catch (AwsException $e) {
            Log::warning("AWS Secrets Manager: Failed to fetch rotation metadata, using default cache TTL", [
                'secret' => $secretName,
                'error' => $e->getMessage(),
            ]);

            // Return minimal metadata if we can't fetch rotation info
            return [
                'rotation_enabled' => false,
                'last_checked' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ];
        }
    }

    /**
     * Calculate cache TTL based on rotation schedule
     *
     * Strategy:
     * - No rotation or no next date: Use default TTL (5 minutes)
     * - Rotation far away (> buffer period): Cache until buffer period starts
     * - Within buffer period: Use default TTL for more frequent checks
     *
     * @param string|null $nextRotationDate Next rotation date in Y-m-d H:i:s format
     * @return int Cache TTL in seconds
     */
    private function calculateCacheTtl(?string $nextRotationDate): int
    {
        if ($nextRotationDate === null) {
            return $this->cacheTtl;
        }

        try {
            $now = new \DateTimeImmutable();
            $rotationDate = new \DateTimeImmutable($nextRotationDate);
            $bufferDate = $rotationDate->modify("-{$this->rotationBufferDays} days");

            // If we're within the buffer period, use short TTL for frequent checks
            if ($now >= $bufferDate) {
                return $this->cacheTtl;
            }

            // Calculate seconds until buffer period starts
            $secondsUntilBuffer = $bufferDate->getTimestamp() - $now->getTimestamp();

            // Cap at 30 days to avoid excessive cache times
            $maxCacheTtl = 30 * 24 * 60 * 60;

            return \min($secondsUntilBuffer, $maxCacheTtl);
        } catch (\Exception $e) {
            Log::warning("AWS Secrets Manager: Failed to calculate dynamic TTL", [
                'error' => $e->getMessage(),
                'next_rotation' => $nextRotationDate,
            ]);

            return $this->cacheTtl;
        }
    }

    /**
     * Clear cached secret and rotation metadata
     *
     * @param string $secretName The name/ARN of the secret
     */
    public function clearCache(string $secretName): void
    {
        $cacheKey = "aws_secret:{$secretName}";
        $metadataKey = "aws_secret_metadata:{$secretName}";

        Cache::forget($cacheKey);
        Cache::forget($metadataKey);

        Log::info("AWS Secrets Manager: Cleared cache and metadata", ['secret' => $secretName]);
    }

    /**
     * Get rotation metadata for a secret
     *
     * Useful for debugging or displaying rotation status in admin interfaces
     *
     * @param string $secretName The name/ARN of the secret
     * @return array<string, mixed> Metadata including rotation status and dates
     */
    public function getRotationMetadata(string $secretName): array
    {
        $metadataKey = "aws_secret_metadata:{$secretName}";

        // Check cache first
        $cached = Cache::get($metadataKey);
        if ($cached !== null) {
            return $cached;
        }

        // Fetch fresh metadata
        return $this->fetchRotationMetadata($secretName);
    }
}
