<?php

namespace Folklore\Mediatheque;

use Folklore\Mediatheque\Console\InstallCommand;
use Folklore\Mediatheque\Support\ConfigMerger;
use Folklore\Mediatheque\Support\MediathequeConfig;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        // Before any provider boots, once every config value is set
        $this->app->booting(fn () => $this->mergeConfig());

        $this->app->singleton(MediathequeConfig::class, fn () => new MediathequeConfig($this->app->make(Repository::class)));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/mediatheque.php' => $this->app->configPath('mediatheque.php'),
            ], 'mediatheque-config');
        }
    }

    /**
     * Merge the defaults deeply with the application's config, so a
     * published config only needs its overrides (ConfigMerger). A cached
     * config is already merged.
     */
    private function mergeConfig(): void
    {
        if ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached()) {
            return;
        }

        $config = $this->app->make(Repository::class);
        $defaults = require __DIR__.'/../config/mediatheque.php';
        $overrides = $config->get('mediatheque', []);

        $config->set('mediatheque', ConfigMerger::merge(
            is_array($defaults) ? $defaults : [],
            is_array($overrides) ? $overrides : [],
        ));
    }
}
