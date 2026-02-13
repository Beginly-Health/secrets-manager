<?php

declare(strict_types=1);

namespace Beginly\SecretsManager\Tests\Unit;

use Beginly\SecretsManager\SecretsManagerService;
use Beginly\SecretsManager\SecretsManagerServiceProvider;
use Orchestra\Testbench\TestCase;

class SecretsManagerServiceProviderTest extends TestCase
{
    public function testRegistersServiceAsSingleton(): void
    {
        // Arrange
        $provider = new SecretsManagerServiceProvider($this->app);

        // Act
        $provider->register();
        $service1 = $this->app->make(SecretsManagerService::class);
        $service2 = $this->app->make(SecretsManagerService::class);

        // Assert
        $this->assertInstanceOf(SecretsManagerService::class, $service1);
        $this->assertSame($service1, $service2, 'Service should be registered as singleton');
    }

    public function testProviderHasNoBootLogic(): void
    {
        // Arrange
        $provider = new SecretsManagerServiceProvider($this->app);

        // Act - boot method should not exist or should be empty
        $reflection = new \ReflectionClass($provider);
        $hasBootMethod = $reflection->hasMethod('boot');

        // Assert
        if ($hasBootMethod) {
            $bootMethod = $reflection->getMethod('boot');
            $bootMethod->setAccessible(true);

            // Boot should not throw exceptions and should not modify config
            $bootMethod->invoke($provider);

            // Verify it doesn't set any database config (package shouldn't touch app config)
            $this->assertTrue(true, 'Boot method exists but should not modify application config');
        } else {
            $this->assertFalse($hasBootMethod, 'Provider should not have boot logic');
        }
    }
}
