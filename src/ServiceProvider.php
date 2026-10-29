<?php

namespace Folklore\Mediatheque;

use Folklore\Image\Contracts\SourceResolver;
use Folklore\Mediatheque\Compat\Deprecation;
use Folklore\Mediatheque\Compat\LegacyConfig;
use Folklore\Mediatheque\Console\InstallCommand;
use Folklore\Mediatheque\Console\PruneCommand;
use Folklore\Mediatheque\Console\StatusCommand;
use Folklore\Mediatheque\Console\UpgradeCommand;
use Folklore\Mediatheque\Contracts\Ingestor;
use Folklore\Mediatheque\Contracts\Mediatheque;
use Folklore\Mediatheque\Contracts\Prober;
use Folklore\Mediatheque\Contracts\Recorder;
use Folklore\Mediatheque\Contracts\UrlGenerator;
use Folklore\Mediatheque\Dashboard\Routes as DashboardRoutes;
use Folklore\Mediatheque\Images\MediaResolver;
use Folklore\Mediatheque\Ingest\MediaIngestor;
use Folklore\Mediatheque\Monitoring\Monitoring;
use Folklore\Mediatheque\Pipelines\EloquentRecorder;
use Folklore\Mediatheque\Probe\LocalProber;
use Folklore\Mediatheque\Support\ConfigMerger;
use Folklore\Mediatheque\Support\MediathequeConfig;
use Folklore\Mediatheque\Transcoders\TranscoderManager;
use Folklore\Mediatheque\Urls\DiskUrlGenerator;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        // Before any provider boots, once every config value is set
        $this->app->booting(fn () => $this->mergeConfig());

        $this->app->singleton(MediathequeConfig::class, fn () => new MediathequeConfig($this->app->make(Repository::class)));
        $this->app->singleton(Mediatheque::class, MediathequeManager::class);
        $this->app->singleton(UrlGenerator::class, DiskUrlGenerator::class);
        $this->app->singleton(Prober::class, LocalProber::class);
        $this->app->singleton(Ingestor::class, MediaIngestor::class);
        $this->app->singleton(Recorder::class, EloquentRecorder::class);
        $this->app->singleton(TranscoderManager::class);
        $this->app->singleton(Monitoring::class);
    }

    public function boot(): void
    {
        $this->registerImageResolver();

        // Registered by the application, inside its own middleware: Route::mediathequeDashboard()
        Router::macro('mediathequeDashboard', function (string $prefix = 'mediatheque') {
            /** @var Router $this */
            DashboardRoutes::register($this, $prefix);
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                PruneCommand::class,
                StatusCommand::class,
                UpgradeCommand::class,
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
     *
     * A config of the 1.x shape is read in the 2.0 shape first, and its
     * sources become disks (LegacyConfig), with a deprecation notice.
     */
    private function mergeConfig(): void
    {
        if ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached()) {
            return;
        }

        $config = $this->app->make(Repository::class);
        $defaults = require __DIR__.'/../config/mediatheque.php';
        $overrides = $config->get('mediatheque', []);
        $overrides = is_array($overrides) ? $overrides : [];

        if (LegacyConfig::isLegacy($overrides)) {
            $disks = $config->get('filesystems.disks', []);
            $conversion = LegacyConfig::convert($overrides, is_array($disks) ? $disks : []);
            $overrides = $conversion->config;

            foreach ($conversion->disks as $name => $disk) {
                $config->set('filesystems.disks.'.$name, $disk);
            }

            Deprecation::trigger('config/mediatheque.php has the 1.x shape: rewrite it with `php artisan mediatheque:upgrade --config`.');
        }

        $config->set('mediatheque', ConfigMerger::merge(is_array($defaults) ? $defaults : [], $overrides));
    }

    /**
     * When `laravel-image` 2 is installed, register the `media` resolver
     * (docs/decisions/0013) in its resolvers: once they are built, or now if
     * another provider already built them. Registering twice changes
     * nothing: resolvers are keyed by name.
     */
    private function registerImageResolver(): void
    {
        $registry = 'Folklore\\Image\\Sources\\SourceResolvers';

        if (! interface_exists(SourceResolver::class) || ! class_exists($registry)) {
            return;
        }

        $this->callAfterResolving($registry, function (object $resolvers) {
            if (method_exists($resolvers, 'register')) {
                $resolvers->register($this->app->make(MediaResolver::class));
            }
        });
    }
}
