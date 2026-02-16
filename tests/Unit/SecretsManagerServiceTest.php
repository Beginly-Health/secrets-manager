<?php

declare(strict_types=1);

namespace Beginly\SecretsManager\Tests\Unit;

use Aws\Exception\AwsException;
use Aws\SecretsManager\SecretsManagerClient;
use Beginly\SecretsManager\SecretsManagerService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Mockery;
use Orchestra\Testbench\TestCase;

class SecretsManagerServiceTest extends TestCase
{
    public function testGetSecretReturnsCachedValueWhenRotationNotImminent(): void
    {
        $secretName = 'test-secret';
        $expectedData = ['username' => 'testuser', 'password' => 'testpass'];

        // Set next rotation 60 days away (well beyond the 7-day buffer)
        $nextRotation = (new \DateTimeImmutable())->modify('+60 days')->format('Y-m-d H:i:s');
        $metadata = [
            'rotation_enabled' => true,
            'next_rotation' => $nextRotation,
            'last_checked' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ];

        Cache::shouldReceive('get')
            ->once()
            ->with("aws_secret:{$secretName}")
            ->andReturn($expectedData);

        Cache::shouldReceive('get')
            ->once()
            ->with("aws_secret_metadata:{$secretName}")
            ->andReturn($metadata);

        Log::shouldReceive('debug')
            ->once()
            ->with('AWS Secrets Manager: Using cached secret (rotation not imminent)', Mockery::type('array'));

        $service = new SecretsManagerService();
        $result = $service->getSecret($secretName);

        $this->assertSame($expectedData, $result);
    }

    public function testGetSecretFetchesFromAwsWhenNotCached(): void
    {
        $secretName = 'test-secret';
        $secretData = ['username' => 'testuser', 'password' => 'testpass'];
        $secretString = \json_encode($secretData);
        $nextRotationDate = (new \DateTimeImmutable())->modify('+60 days');

        Cache::shouldReceive('get')
            ->once()
            ->with("aws_secret:{$secretName}")
            ->andReturn(null);

        Cache::shouldReceive('get')
            ->once()
            ->with("aws_secret_metadata:{$secretName}")
            ->andReturn(null);

        // Cache will be called with secret data and metadata
        Cache::shouldReceive('put')
            ->once()
            ->with("aws_secret:{$secretName}", $secretData, Mockery::type('int'));

        Cache::shouldReceive('put')
            ->once()
            ->with("aws_secret_metadata:{$secretName}", Mockery::type('array'), Mockery::type('int'));

        Log::shouldReceive('info')
            ->once()
            ->with('AWS Secrets Manager: Fetching secret from AWS', ['secret' => $secretName]);

        Log::shouldReceive('info')
            ->once()
            ->with('AWS Secrets Manager: Successfully fetched and cached secret', Mockery::type('array'));

        // Mock the AWS client
        $mockClient = Mockery::mock(SecretsManagerClient::class);
        $mockClient->shouldReceive('getSecretValue')
            ->once()
            ->with(['SecretId' => $secretName])
            ->andReturn([
                'SecretString' => $secretString,
            ]);

        $mockClient->shouldReceive('describeSecret')
            ->once()
            ->with(['SecretId' => $secretName])
            ->andReturn([
                'RotationEnabled' => true,
                'NextRotationDate' => $nextRotationDate,
                'LastRotatedDate' => (new \DateTimeImmutable())->modify('-30 days'),
            ]);

        $service = new SecretsManagerService();

        // Inject mock client using reflection
        $reflection = new \ReflectionClass($service);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($service, $mockClient);

        $result = $service->getSecret($secretName);

        $this->assertSame($secretData, $result);
    }

    public function testGetSecretRefetchesWhenWithinRotationBuffer(): void
    {
        $secretName = 'test-secret';
        $oldData = ['username' => 'olduser', 'password' => 'oldpass'];
        $newData = ['username' => 'newuser', 'password' => 'newpass'];
        $secretString = \json_encode($newData);

        // Set next rotation 5 days away (within the 7-day buffer)
        $nextRotation = (new \DateTimeImmutable())->modify('+5 days')->format('Y-m-d H:i:s');
        $lastChecked = (new \DateTimeImmutable())->modify('-2 hours')->format('Y-m-d H:i:s');
        $metadata = [
            'rotation_enabled' => true,
            'next_rotation' => $nextRotation,
            'last_checked' => $lastChecked,
        ];

        Cache::shouldReceive('get')
            ->once()
            ->with("aws_secret:{$secretName}")
            ->andReturn($oldData);

        Cache::shouldReceive('get')
            ->once()
            ->with("aws_secret_metadata:{$secretName}")
            ->andReturn($metadata);

        // Should refetch because we're within buffer and more than 1 hour since last check
        Cache::shouldReceive('put')
            ->once()
            ->with("aws_secret:{$secretName}", $newData, Mockery::type('int'));

        Cache::shouldReceive('put')
            ->once()
            ->with("aws_secret_metadata:{$secretName}", Mockery::type('array'), Mockery::type('int'));

        Log::shouldReceive('info')
            ->once()
            ->with('AWS Secrets Manager: Rotation imminent or occurred, checking for updates', Mockery::type('array'));

        Log::shouldReceive('info')
            ->once()
            ->with('AWS Secrets Manager: Fetching secret from AWS', ['secret' => $secretName]);

        Log::shouldReceive('info')
            ->once()
            ->with('AWS Secrets Manager: Successfully fetched and cached secret', Mockery::type('array'));

        $mockClient = Mockery::mock(SecretsManagerClient::class);
        $mockClient->shouldReceive('getSecretValue')
            ->once()
            ->with(['SecretId' => $secretName])
            ->andReturn([
                'SecretString' => $secretString,
            ]);

        $mockClient->shouldReceive('describeSecret')
            ->once()
            ->with(['SecretId' => $secretName])
            ->andReturn([
                'RotationEnabled' => true,
                'NextRotationDate' => (new \DateTimeImmutable())->modify('+5 days'),
                'LastRotatedDate' => (new \DateTimeImmutable())->modify('-55 days'),
            ]);

        $service = new SecretsManagerService();

        $reflection = new \ReflectionClass($service);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($service, $mockClient);

        $result = $service->getSecret($secretName);

        $this->assertSame($newData, $result);
    }

    public function testGetSecretThrowsExceptionWhenSecretStringMissing(): void
    {
        $secretName = 'test-secret';

        Cache::shouldReceive('get')
            ->once()
            ->with("aws_secret:{$secretName}")
            ->andReturn(null);

        Cache::shouldReceive('get')
            ->once()
            ->with("aws_secret_metadata:{$secretName}")
            ->andReturn(null);

        Log::shouldReceive('info')
            ->once()
            ->with('AWS Secrets Manager: Fetching secret from AWS', ['secret' => $secretName]);

        $mockClient = Mockery::mock(SecretsManagerClient::class);
        $mockClient->shouldReceive('getSecretValue')
            ->once()
            ->with(['SecretId' => $secretName])
            ->andReturn([]);

        $service = new SecretsManagerService();

        $reflection = new \ReflectionClass($service);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($service, $mockClient);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Secret "test-secret" does not contain SecretString field');

        $service->getSecret($secretName);
    }

    public function testGetSecretThrowsExceptionOnInvalidJson(): void
    {
        $secretName = 'test-secret';

        Cache::shouldReceive('get')
            ->once()
            ->with("aws_secret:{$secretName}")
            ->andReturn(null);

        Cache::shouldReceive('get')
            ->once()
            ->with("aws_secret_metadata:{$secretName}")
            ->andReturn(null);

        Log::shouldReceive('info')
            ->once()
            ->with('AWS Secrets Manager: Fetching secret from AWS', ['secret' => $secretName]);

        Log::shouldReceive('error')
            ->once()
            ->with('AWS Secrets Manager: Invalid JSON in secret', Mockery::type('array'));

        $mockClient = Mockery::mock(SecretsManagerClient::class);
        $mockClient->shouldReceive('getSecretValue')
            ->once()
            ->with(['SecretId' => $secretName])
            ->andReturn([
                'SecretString' => 'invalid-json{',
            ]);

        $service = new SecretsManagerService();

        $reflection = new \ReflectionClass($service);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($service, $mockClient);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Secret "test-secret" contains invalid JSON/');

        $service->getSecret($secretName);
    }

    public function testGetSecretThrowsExceptionOnAwsError(): void
    {
        $secretName = 'test-secret';

        Cache::shouldReceive('get')
            ->once()
            ->with("aws_secret:{$secretName}")
            ->andReturn(null);

        Cache::shouldReceive('get')
            ->once()
            ->with("aws_secret_metadata:{$secretName}")
            ->andReturn(null);

        Log::shouldReceive('info')
            ->once()
            ->with('AWS Secrets Manager: Fetching secret from AWS', ['secret' => $secretName]);

        Log::shouldReceive('error')
            ->once()
            ->with('AWS Secrets Manager: Failed to fetch secret', Mockery::type('array'));

        $awsException = Mockery::mock(AwsException::class);
        $awsException->shouldReceive('getAwsErrorCode')->andReturn('ResourceNotFoundException');
        $awsException->shouldReceive('getAwsErrorMessage')->andReturn('Secret not found');

        $mockClient = Mockery::mock(SecretsManagerClient::class);
        $mockClient->shouldReceive('getSecretValue')
            ->once()
            ->with(['SecretId' => $secretName])
            ->andThrow($awsException);

        $service = new SecretsManagerService();

        $reflection = new \ReflectionClass($service);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($service, $mockClient);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches(
            '/Failed to fetch secret "test-secret" from AWS Secrets Manager: \[ResourceNotFoundException\] Secret not found/'
        );

        $service->getSecret($secretName);
    }

    public function testClearCacheRemovesCachedSecretAndMetadata(): void
    {
        $secretName = 'test-secret';

        Cache::shouldReceive('forget')
            ->once()
            ->with("aws_secret:{$secretName}");

        Cache::shouldReceive('forget')
            ->once()
            ->with("aws_secret_metadata:{$secretName}");

        Log::shouldReceive('info')
            ->once()
            ->with('AWS Secrets Manager: Cleared cache and metadata', ['secret' => $secretName]);

        $service = new SecretsManagerService();
        $service->clearCache($secretName);

        // No exception means success
        $this->assertTrue(true);
    }

    public function testGetRotationMetadataReturnsMetadata(): void
    {
        $secretName = 'test-secret';
        $nextRotationDate = (new \DateTimeImmutable())->modify('+60 days');

        Cache::shouldReceive('get')
            ->once()
            ->with("aws_secret_metadata:{$secretName}")
            ->andReturn(null);

        $mockClient = Mockery::mock(SecretsManagerClient::class);
        $mockClient->shouldReceive('describeSecret')
            ->once()
            ->with(['SecretId' => $secretName])
            ->andReturn([
                'RotationEnabled' => true,
                'NextRotationDate' => $nextRotationDate,
                'LastRotatedDate' => (new \DateTimeImmutable())->modify('-30 days'),
            ]);

        $service = new SecretsManagerService();

        $reflection = new \ReflectionClass($service);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($service, $mockClient);

        $result = $service->getRotationMetadata($secretName);

        $this->assertTrue($result['rotation_enabled']);
        $this->assertArrayHasKey('next_rotation', $result);
        $this->assertArrayHasKey('last_rotated', $result);
        $this->assertArrayHasKey('last_checked', $result);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Mock facades
        Config::shouldReceive('get')
            ->with('services.aws.secrets_cache_ttl', 300)
            ->andReturn(300);

        Config::shouldReceive('get')
            ->with('services.aws.secrets_rotation_buffer_days', 7)
            ->andReturn(7);

        Config::shouldReceive('get')
            ->with('services.aws.secrets_region', 'us-east-1')
            ->andReturn('us-east-1');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
