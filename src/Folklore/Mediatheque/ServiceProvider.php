<?php
namespace Folklore\Mediatheque;

use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;
use Illuminate\Database\Eloquent\Model;
use Folklore\Mediatheque\Jobs\Handler;
use InvalidArgumentException;

class ServiceProvider extends BaseServiceProvider
{
    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = false;

    /**
     * Bootstrap the application events.
     *
     * @return void
     */
    public function boot()
    {
        $this->bootPublishes();
        $this->bootEvents();
        $this->bootRouter();
    }

    public function bootPublishes()
    {
        // Config file path
        $configPath = __DIR__ . '/../../config/config.php';
        $migrationsPath = __DIR__ . '/../../migrations';
        $routesPath = __DIR__ . '/../../routes.php';

        // Merge files
        $this->mergeConfigFrom($configPath, 'mediatheque');

        // Migrations
        $this->loadMigrationsFrom($migrationsPath);

        // Publish
        $this->publishes(
            [
                $migrationsPath => base_path('database/migrations'),
            ],
            'migrations'
        );

        $this->publishes(
            [
                $configPath => config_path('mediatheque.php'),
            ],
            'config'
        );

        $this->publishes(
            [
                $routesPath => base_path('routes/mediatheque.php'),
            ],
            'routes'
        );
    }

    public function bootEvents()
    {
        $this->app['events']->listen(
            \Folklore\Mediatheque\Events\FileAttached::class,
            \Folklore\Mediatheque\Observers\FileObserver::class . '@attached'
        );
        $this->app['events']->listen(
            \Folklore\Mediatheque\Events\FileDetached::class,
            \Folklore\Mediatheque\Observers\FileObserver::class . '@detached'
        );
    }

    public function bootRouter()
    {
        $app = $this->app;

        $this->app['router']->macro('mediatheque', function ($opts = []) use ($app) {
            return $app['mediatheque.router']->mediatheque($opts);
        });

        $map = $this->app['config']->get('mediatheque.routes.map');
        if (!is_null($map)) {
            $this->loadRoutesFrom(file_exists($map) ? $map : __DIR__ . '/../../routes.php');
        }
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->registerTypeManager();
        $this->registerPipelineManager();
        $this->registerMetadataManager();
        $this->registerSourceManager();
        $this->registerModels();
        $this->registerServices();
        $this->registerRouter();
        $this->registerMediatheque();
        $this->registerMimeTypesGuesser();
    }

    /**
     * Register the type manager
     *
     * @return void
     */
    public function registerTypeManager()
    {
        $this->app->singleton('mediatheque.types', function ($app) {
            return new TypeManager($app);
        });
        $this->app->bind(\Folklore\Mediatheque\Contracts\Type\Factory::class, 'mediatheque.types');
    }

    /**
     * Register the pipeline manager
     *
     * @return void
     */
    public function registerPipelineManager()
    {
        $this->app->singleton('mediatheque.pipelines', function ($app) {
            return new PipelineManager($app);
        });
        $this->app->bind(
            \Folklore\Mediatheque\Contracts\Pipeline\Factory::class,
            'mediatheque.pipelines'
        );
    }

    /**
     * Register the source manager
     *
     * @return void
     */
    public function registerSourceManager()
    {
        $this->app->singleton('mediatheque.sources', function ($app) {
            return new SourceManager($app, $app['files']);
        });
        $this->app->bind(
            \Folklore\Mediatheque\Contracts\Source\Factory::class,
            'mediatheque.sources'
        );
    }

    /**
     * Register the source manager
     *
     * @return void
     */
    public function registerMetadataManager()
    {
        $this->app->singleton('mediatheque.metadatas', function ($app) {
            return new MetadataManager($app);
        });
        $this->app->bind(
            \Folklore\Mediatheque\Contracts\Metadata\Factory::class,
            'mediatheque.metadatas'
        );
    }

    /**
     * Register mediatheque
     *
     * @return void
     */
    public function registerMediatheque()
    {
        $this->app->singleton('mediatheque', function ($app) {
            return new Mediatheque($app, $app['mediatheque.types'], $app['mediatheque.pipelines']);
        });
    }

    /**
     * Register router
     *
     * @return void
     */
    public function registerRouter()
    {
        $this->app->singleton('mediatheque.router', function ($app) {
            $config = $app['config'];
            $router = new Router($app['router'], $app['mediatheque']);
            $router->setPrefix($config->get('mediatheque.routes.prefix'));
            $router->setNamePrefix(
                $config->get(
                    'mediatheque.routes.name_prefix',
                    preg_replace(
                        '#/#',
                        '.',
                        $config->get('mediatheque.routes.prefix', 'mediatheque')
                    ) . '.'
                )
            );
            $router->setMiddleware($config->get('mediatheque.routes.middleware'));
            return $router;
        });
    }

    /**
     * Register the mime type guesser
     *
     * @return void
     */
    public function registerMimeTypesGuesser()
    {
        $this->app->bind(\Symfony\Component\Mime\MimeTypeGuesserInterface::class, function () {
            return new \Symfony\Component\Mime\MimeTypes();
        });
    }

    /**
     * Register the models contracts
     *
     * @return void
     */
    public function registerModels()
    {
        $this->app->bind(
            \Folklore\Mediatheque\Contracts\Models\Media::class,
            \Folklore\Mediatheque\Models\Media::class
        );

        $this->app->bind(
            \Folklore\Mediatheque\Contracts\Models\Metadata::class,
            \Folklore\Mediatheque\Models\Metadata::class
        );

        $this->app->bind(
            \Folklore\Mediatheque\Contracts\Models\File::class,
            \Folklore\Mediatheque\Models\File::class
        );

        $this->app->bind(
            \Folklore\Mediatheque\Contracts\Models\Pipeline::class,
            \Folklore\Mediatheque\Models\Pipeline::class
        );

        $this->app->bind(
            \Folklore\Mediatheque\Contracts\Models\PipelineJob::class,
            \Folklore\Mediatheque\Models\PipelineJob::class
        );
    }

    /**
     * Register services
     *
     * @return void
     */
    public function registerServices()
    {
        $this->app->singleton(
            'mediatheque.services.metadata',
            \Folklore\Mediatheque\Services\Metadata::class
        );
        $this->app->singleton(
            'mediatheque.services.ffmpeg',
            \Folklore\Mediatheque\Services\FFMpeg::class
        );
        $this->app->singleton(
            'mediatheque.services.imagick',
            \Folklore\Mediatheque\Services\Imagick::class
        );
        $this->app->singleton(
            'mediatheque.services.audiowaveform',
            \Folklore\Mediatheque\Services\AudioWaveForm::class
        );
        $this->app->singleton(
            'mediatheque.services.otfinfo',
            \Folklore\Mediatheque\Services\OtfInfo::class
        );
        $this->app->singleton(
            'mediatheque.services.path_formatter',
            \Folklore\Mediatheque\Services\PathFormatter::class
        );
        $this->app->singleton(
            'mediatheque.services.color_extractor',
            \Folklore\Mediatheque\Services\ColorExtractor::class
        );
        $this->app->singleton(
            'mediatheque.services.gif',
            \Folklore\Mediatheque\Services\Gif::class
        );
        $this->app->singleton(
            'mediatheque.services.webp',
            \Folklore\Mediatheque\Services\Webp::class
        );
        $this->app->singleton(
            'mediatheque.services.animated_image',
            \Folklore\Mediatheque\Services\AnimatedImage::class
        );

        $services = [
            'mediatheque.services.animated_image' => [
                \Folklore\Mediatheque\Contracts\Services\AnimatedImage::class,
            ],
            'mediatheque.services.gif' => [
                \Folklore\Mediatheque\Contracts\Services\Gif::class,
            ],
            'mediatheque.services.webp' => [
                \Folklore\Mediatheque\Contracts\Services\Webp::class,
            ],
            'mediatheque.services.otfinfo' => [
                \Folklore\Mediatheque\Contracts\Services\FontFamilyName::class,
            ],
            'mediatheque.services.imagick' => [
                \Folklore\Mediatheque\Contracts\Services\ImageDimension::class,
                \Folklore\Mediatheque\Contracts\Services\PagesCount::class,
                \Folklore\Mediatheque\Contracts\Services\DocumentThumbnail::class,
                \Folklore\Mediatheque\Contracts\Services\ImageThumbnail::class,
            ],
            'mediatheque.services.metadata' => [
                \Folklore\Mediatheque\Contracts\Services\Dimension::class,
                \Folklore\Mediatheque\Contracts\Services\Duration::class,
                \Folklore\Mediatheque\Contracts\Services\Thumbnail::class,
                \Folklore\Mediatheque\Contracts\Services\Mime::class,
                \Folklore\Mediatheque\Contracts\Services\Extension::class,
                \Folklore\Mediatheque\Contracts\Services\Metadata::class,
            ],
            'mediatheque.services.ffmpeg' => [
                \Folklore\Mediatheque\Contracts\Services\VideoDimension::class,
                \Folklore\Mediatheque\Contracts\Services\AudioDuration::class,
                \Folklore\Mediatheque\Contracts\Services\VideoDuration::class,
                \Folklore\Mediatheque\Contracts\Services\VideoThumbnail::class,
            ],
            'mediatheque.services.audiowaveform' => [
                \Folklore\Mediatheque\Contracts\Services\AudioThumbnail::class,
            ],
            'mediatheque.services.path_formatter' => [
                \Folklore\Mediatheque\Contracts\Services\PathFormatter::class,
            ],
            'mediatheque.services.color_extractor' => [
                \Folklore\Mediatheque\Contracts\Services\Color::class,
                \Folklore\Mediatheque\Contracts\Services\Palette::class,
            ],
        ];
        foreach ($services as $key => $aliases) {
            foreach ($aliases as $alias) {
                $this->app->alias($key, $alias);
            }
        }
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return [];
    }
}
