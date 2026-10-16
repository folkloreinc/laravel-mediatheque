<?php

namespace Folklore\Mediatheque;

use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/mediatheque.php', 'mediatheque');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/mediatheque.php' => $this->app->configPath('mediatheque.php'),
            ], 'mediatheque-config');
        }
    }
}
