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
    public function testGetSecretReturnsCachedValueWhenAvailable(): void
    {
        $secretName = 'test-secret';
        $expectedData = ['username' => 'testuser', 'password' => 'testpass'];

        Cache::shouldReceive('get')
            ->once()
            ->with("aws_secret:{$secretName}")
            ->andReturn($expectedData);

        Log::shouldReceive('debug')
            ->once()
            ->with('AWS Secrets Manager: Using cached secret', ['secret' => $secretName]);

        $service = new SecretsManagerService();
        $result = $service->getSecret($secretName);

        $this->assertSame($expectedData, $result);
    }

    public function testGetSecretFetchesFromAwsWhenNotCached(): void
    {
        $secretName = 'test-secret';
        $secretData = ['username' => 'testuser', 'password' => 'testpass'];
        $secretString = \json_encode($secretData);

        Cache::shouldReceive('get')
            ->once()
            ->with("aws_secret:{$secretName}")
            ->andReturn(null);

        Cache::shouldReceive('put')
            ->once()
            ->with("aws_secret:{$secretName}", $secretData, 300);

        Log::shouldReceive('info')
            ->once()
            ->with('AWS Secrets Manager: Fetching secret', ['secret' => $secretName]);

        Log::shouldReceive('info')
            ->once()
            ->with('AWS Secrets Manager: Successfully fetched and cached secret', [
                'secret' => $secretName,
                'cache_ttl' => 300,
            ]);

        // Mock the AWS client
        $mockClient = Mockery::mock(SecretsManagerClient::class);
        $mockClient->shouldReceive('getSecretValue')
            ->once()
            ->with(['SecretId' => $secretName])
            ->andReturn([
                'SecretString' => $secretString,
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

    public function testGetSecretThrowsExceptionWhenSecretStringMissing(): void
    {
        $secretName = 'test-secret';

        Cache::shouldReceive('get')
            ->once()
            ->with("aws_secret:{$secretName}")
            ->andReturn(null);

        Log::shouldReceive('info')
            ->once()
            ->with('AWS Secrets Manager: Fetching secret', ['secret' => $secretName]);

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

        Log::shouldReceive('info')
            ->once()
            ->with('AWS Secrets Manager: Fetching secret', ['secret' => $secretName]);

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

        Log::shouldReceive('info')
            ->once()
            ->with('AWS Secrets Manager: Fetching secret', ['secret' => $secretName]);

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

    public function testClearCacheRemovesCachedSecret(): void
    {
        $secretName = 'test-secret';

        Cache::shouldReceive('forget')
            ->once()
            ->with("aws_secret:{$secretName}");

        Log::shouldReceive('info')
            ->once()
            ->with('AWS Secrets Manager: Cleared cache', ['secret' => $secretName]);

        $service = new SecretsManagerService();
        $service->clearCache($secretName);

        // No exception means success
        $this->assertTrue(true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Mock facades
        Config::shouldReceive('get')
            ->with('services.aws.secrets_cache_ttl', 300)
            ->andReturn(300);

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
