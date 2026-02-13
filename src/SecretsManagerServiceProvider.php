<?php

declare(strict_types=1);

namespace Beginly\SecretsManager;

use Illuminate\Support\ServiceProvider;

/**
 * Registers SecretsManagerService as a singleton.
 *
 * See README.md for usage instructions.
 */
class SecretsManagerServiceProvider extends ServiceProvider
{
    /**
     * Register services
     */
    public function register(): void
    {
        // Register SecretsManagerService as singleton
        $this->app->singleton(SecretsManagerService::class, function () {
            return new SecretsManagerService();
        });
    }
}
