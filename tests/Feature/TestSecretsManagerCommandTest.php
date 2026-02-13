<?php

declare(strict_types=1);

namespace Beginly\SecretsManager\Tests\Feature;

use App\Console\Commands\TestSecretsManager;
use Beginly\SecretsManager\SecretsManagerService;
use Illuminate\Support\Facades\Config;
use Mockery;
use Orchestra\Testbench\TestCase;

class TestSecretsManagerCommandTest extends TestCase
{
    public function test_command_fails_when_no_secret_name_provided(): void
    {
        Config::set('services.aws.admin_db_secret_name', null);

        $this->artisan(TestSecretsManager::class)
            ->expectsOutput('No secret name provided and AWS_SECRETS_ADMIN_DB_NAME is not configured.')
            ->expectsOutput('Usage: sail artisan secrets:test <secret-name>')
            ->assertExitCode(TestSecretsManager::FAILURE);
    }

    public function test_command_uses_default_secret_name_from_config(): void
    {
        Config::set('services.aws.admin_db_secret_name', 'test-secret');
        Config::set('services.aws.secrets_cache_ttl', 300);
        Config::set('services.aws.secrets_region', 'us-east-1');

        $mockService = Mockery::mock(SecretsManagerService::class);
        $mockService->shouldReceive('getSecret')
            ->once()
            ->with('test-secret')
            ->andReturn(['username' => 'testuser', 'password' => 'testpass']);

        $this->app->instance(SecretsManagerService::class, $mockService);

        $this->artisan(TestSecretsManager::class)
            ->expectsOutput('Testing AWS Secrets Manager connectivity...')
            ->expectsOutput('Secret name: test-secret')
            ->expectsOutputToContain('Successfully fetched secret from AWS Secrets Manager')
            ->expectsOutputToContain('All tests passed!')
            ->assertExitCode(TestSecretsManager::SUCCESS);
    }

    public function test_command_accepts_secret_name_argument(): void
    {
        Config::set('services.aws.secrets_cache_ttl', 300);
        Config::set('services.aws.secrets_region', 'us-east-1');

        $mockService = Mockery::mock(SecretsManagerService::class);
        $mockService->shouldReceive('getSecret')
            ->once()
            ->with('custom-secret')
            ->andReturn(['username' => 'customuser', 'password' => 'custompass']);

        $this->app->instance(SecretsManagerService::class, $mockService);

        $this->artisan(TestSecretsManager::class, ['secret-name' => 'custom-secret'])
            ->expectsOutput('Testing AWS Secrets Manager connectivity...')
            ->expectsOutput('Secret name: custom-secret')
            ->expectsOutputToContain('Successfully fetched secret from AWS Secrets Manager')
            ->expectsOutputToContain('All tests passed!')
            ->assertExitCode(TestSecretsManager::SUCCESS);
    }

    public function test_command_displays_secret_keys(): void
    {
        Config::set('services.aws.secrets_cache_ttl', 300);
        Config::set('services.aws.secrets_region', 'us-east-1');

        $mockService = Mockery::mock(SecretsManagerService::class);
        $mockService->shouldReceive('getSecret')
            ->once()
            ->with('test-secret')
            ->andReturn([
                'username' => 'testuser',
                'password' => 'testpass',
                'extra_key' => 'extra_value',
            ]);

        $this->app->instance(SecretsManagerService::class, $mockService);

        $this->artisan(TestSecretsManager::class, ['secret-name' => 'test-secret'])
            ->expectsOutput('Testing AWS Secrets Manager connectivity...')
            ->expectsOutput('Secret name: test-secret')
            ->expectsOutputToContain('Successfully fetched secret from AWS Secrets Manager')
            ->expectsOutputToContain('Secret contains 3 keys:')
            ->expectsOutputToContain('- username')
            ->expectsOutputToContain('- password')
            ->expectsOutputToContain('- extra_key')
            ->assertExitCode(TestSecretsManager::SUCCESS);
    }

    public function test_command_validates_database_credentials_when_present(): void
    {
        Config::set('services.aws.secrets_cache_ttl', 300);
        Config::set('services.aws.secrets_region', 'us-east-1');

        $mockService = Mockery::mock(SecretsManagerService::class);
        $mockService->shouldReceive('getSecret')
            ->once()
            ->with('db-secret')
            ->andReturn(['username' => 'dbuser', 'password' => 'dbpass']);

        $this->app->instance(SecretsManagerService::class, $mockService);

        $this->artisan(TestSecretsManager::class, ['secret-name' => 'db-secret'])
            ->expectsOutputToContain('Database credentials detected:')
            ->expectsOutputToContain('Note: Host, port, and database should be configured in .env file')
            ->assertExitCode(TestSecretsManager::SUCCESS);
    }

    public function test_command_masks_password_in_output(): void
    {
        Config::set('services.aws.secrets_cache_ttl', 300);
        Config::set('services.aws.secrets_region', 'us-east-1');

        $mockService = Mockery::mock(SecretsManagerService::class);
        $mockService->shouldReceive('getSecret')
            ->once()
            ->with('db-secret')
            ->andReturn(['username' => 'dbuser', 'password' => 'supersecret']);

        $this->app->instance(SecretsManagerService::class, $mockService);

        $output = $this->artisan(TestSecretsManager::class, ['secret-name' => 'db-secret'])
            ->assertExitCode(TestSecretsManager::SUCCESS)
            ->execute();

        // Password should be masked and actual password should not appear in output
        $this->assertStringNotContainsString('supersecret', $output);
    }

    public function test_command_shows_error_and_troubleshooting_on_failure(): void
    {
        Config::set('services.aws.secrets_cache_ttl', 300);
        Config::set('services.aws.secrets_region', 'us-east-1');

        $mockService = Mockery::mock(SecretsManagerService::class);
        $mockService->shouldReceive('getSecret')
            ->once()
            ->with('bad-secret')
            ->andThrow(new \RuntimeException('Secret not found'));

        $this->app->instance(SecretsManagerService::class, $mockService);

        $this->artisan(TestSecretsManager::class, ['secret-name' => 'bad-secret'])
            ->expectsOutputToContain('Failed to fetch secret: Secret not found')
            ->expectsOutputToContain('Troubleshooting tips:')
            ->expectsOutputToContain('Verify environment:')
            ->expectsOutputToContain('Verify AWS credentials are configured')
            ->expectsOutputToContain('Check IAM permissions')
            ->expectsOutputToContain('Verify secret name is correct: bad-secret')
            ->assertExitCode(TestSecretsManager::FAILURE);
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     * @return array<int, class-string>
     *
     * @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
     */
    protected function getPackageProviders($app): array
    {
        return [];
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
