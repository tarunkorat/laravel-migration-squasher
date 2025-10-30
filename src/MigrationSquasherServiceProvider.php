<?php

namespace TarunKorat\LaravelMigrationSquasher;

use Illuminate\Support\ServiceProvider;
use TarunKorat\LaravelMigrationSquasher\Commands\SquashMigrationsCommand;
use TarunKorat\LaravelMigrationSquasher\Contracts\SchemaInspectorInterface;
use TarunKorat\LaravelMigrationSquasher\Services\SchemaInspector;

class MigrationSquasherServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/migration-squasher.php',
            'migration-squasher'
        );

        // Bind schema inspector interface
        $this->app->singleton(SchemaInspectorInterface::class, SchemaInspector::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            // Publish config
            $this->publishes([
                __DIR__.'/../config/migration-squasher.php' => config_path('migration-squasher.php'),
            ], 'migration-squasher-config');

            // Register commands
            $this->commands([
                SquashMigrationsCommand::class,
            ]);
        }
    }
}
