<?php

namespace EduLazaro\Larameili;

use EduLazaro\Larameili\Console\MakeMeilieCommand;
use EduLazaro\Larameili\Console\SyncCommand;
use Illuminate\Support\ServiceProvider;

class LarameiliServiceProvider extends ServiceProvider
{
    /**
     * Register the package configuration.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/larameili.php', 'larameili');
    }

    /**
     * Wire up publishing and the console commands.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/larameili.php' => config_path('larameili.php'),
            ], 'larameili-config');

            $this->commands([
                SyncCommand::class,
                MakeMeilieCommand::class,
            ]);
        }
    }
}
