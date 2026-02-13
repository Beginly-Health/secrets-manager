<?php

declare(strict_types=1);

namespace Beginly\SecretsManager;

use Aws\Exception\AwsException;
use Aws\SecretsManager\SecretsManagerClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * SecretsManagerService
 *
 * Fetches and caches secrets from AWS Secrets Manager.
 * Supports IAM role authentication (production) and access key authentication (local).
 */
class SecretsManagerService
{
    private SecretsManagerClient $client;

    private int $cacheTtl;

    public function __construct()
    {
        $this->cacheTtl = (int) \config('services.aws.secrets_cache_ttl', 300);

        $this->client = new SecretsManagerClient([
            'version' => 'latest',
            'region' => \config('services.aws.secrets_region', 'us-east-1'),
        ]);
    }

    /**
     * Get secret value from AWS Secrets Manager
     *
     * @param string $secretName The name/ARN of the secret
     * @return array<string, mixed> The secret value as associative array
     * @throws \RuntimeException If secret cannot be fetched or parsed
     */
    public function getSecret(string $secretName): array
    {
        $cacheKey = "aws_secret:{$secretName}";

        // Check cache first
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            Log::debug("AWS Secrets Manager: Using cached secret", ['secret' => $secretName]);

            return $cached;
        }

        try {
            Log::info("AWS Secrets Manager: Fetching secret", ['secret' => $secretName]);

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

            // Cache the secret
            Cache::put($cacheKey, $secretData, $this->cacheTtl);

            Log::info("AWS Secrets Manager: Successfully fetched and cached secret", [
                'secret' => $secretName,
                'cache_ttl' => $this->cacheTtl,
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
     * Clear cached secret
     *
     * @param string $secretName The name/ARN of the secret
     */
    public function clearCache(string $secretName): void
    {
        $cacheKey = "aws_secret:{$secretName}";
        Cache::forget($cacheKey);
        Log::info("AWS Secrets Manager: Cleared cache", ['secret' => $secretName]);
    }
}
