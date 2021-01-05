<?php
namespace Folklore\Mediatheque;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Arr;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Bus\Dispatcher;
use Folklore\Mediatheque\Jobs\Handler;
use InvalidArgumentException;

class MediathequeServiceProvider extends ServiceProvider
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
        $this->bootDispatcher();
        $this->bootRouter();
    }

    public function bootPublishes()
    {
        // Config file path
        $configPath = __DIR__ . '/../../config/config.php';
        $migrationsPath = __DIR__ . '/../../migrations';

        // Merge files
        $this->mergeConfigFrom($configPath, 'mediatheque');

        // Migrations
        if (method_exists($this, 'loadMigrationsFrom')) {
            $this->loadMigrationsFrom($migrationsPath);
        }

        // Publish
        $this->publishes(
            [
                $migrationsPath => base_path('database/migrations')
            ],
            'migrations'
        );

        $this->publishes(
            [
                $configPath => config_path('mediatheque.php')
            ],
            'config'
        );
    }

    public function bootEvents()
    {
        // File attach and detach event
        $fileObserver = $this->app['config']->get('mediatheque.observers.file');
        $fileAttachedEvent = $this->app['config']->get(
            'mediatheque.events.file_attached',
            null
        );
        if (!is_null($fileAttachedEvent)) {
            $this->app['events']->listen(
                $fileAttachedEvent,
                $fileObserver . '@attached'
            );
        }

        $fileDetachedEvent = $this->app['config']->get(
            'mediatheque.events.file_detached',
            null
        );
        if (!is_null($fileDetachedEvent)) {
            $this->app['events']->listen(
                $fileDetachedEvent,
                $fileObserver . '@detached'
            );
        }
    }

    public function bootDispatcher()
    {
        $dispatcher = app(Dispatcher::class);
        if (method_exists($dispatcher, 'mapUsing')) {
            $dispatcher->mapUsing(function ($command) {
                // prettier-ignore
                if ($command instanceof \Folklore\Mediatheque\Jobs\RunPipeline ||
                    $command instanceof \Folklore\Mediatheque\Jobs\RunPipelineJob ||
                    $command instanceof \Folklore\Mediatheque\Support\PipelineJob
                ) {
                    return Handler::class . '@handle';
                }
                $className = get_class($command);
                throw new InvalidArgumentException(
                    "No handler registered for command [{$className}]"
                );
            });
        }
    }

    public function bootRouter()
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        $config = $this->app['config']->get('mediatheque.routes', []);
        $router = $this->app->bound('router') ? $this->app['router'] : $this->app;
        $groupConfig = Arr::only($config, [
            'middleware',
            'domain',
            'prefix',
            'namespace'
        ]);
        $router->group($groupConfig, function ($router) use ($config) {
            if (data_get($config, 'api', null) !== false) {
                require __DIR__ . '/../../routes/api.php';
            }
            if (data_get($config, 'upload', null) !== false) {
                require __DIR__ . '/../../routes/upload.php';
            }
        });
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
        $this->registerPipeline();
        $this->registerType();
        $this->registerServices();
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
        $this->app->bind(
            \Folklore\Mediatheque\Contracts\Type\Factory::class,
            'mediatheque.types'
        );
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
        $this->app->bind('mediatheque.sources', function ($app) {
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
        $this->app->bind('mediatheque.metadatas', function ($app) {
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
            $mediatheque = new Mediatheque(
                $app,
                $app['mediatheque.types'],
                $app['mediatheque.pipelines']
            );
            return $mediatheque;
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
     * Register the pipeline class
     *
     * @return void
     */
    public function registerPipeline()
    {
        $this->app->bind(
            \Folklore\Mediatheque\Contracts\Pipeline\Pipeline::class,
            \Folklore\Mediatheque\Support\Pipeline::class
        );
    }

    /**
     * Register the type class
     *
     * @return void
     */
    public function registerType()
    {
        $this->app->bind(
            \Folklore\Mediatheque\Contracts\Type\Type::class,
            \Folklore\Mediatheque\Support\Type::class
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

        $services = [
            'mediatheque.services.otfinfo' => [
                \Folklore\Mediatheque\Contracts\Services\FontFamilyName::class
            ],
            'mediatheque.services.imagick' => [
                \Folklore\Mediatheque\Contracts\Services\ImageDimension::class,
                \Folklore\Mediatheque\Contracts\Services\PagesCount::class,
                \Folklore\Mediatheque\Contracts\Services\DocumentThumbnail::class,
                \Folklore\Mediatheque\Contracts\Services\ImageThumbnail::class
            ],
            'mediatheque.services.metadata' => [
                \Folklore\Mediatheque\Contracts\Services\Dimension::class,
                \Folklore\Mediatheque\Contracts\Services\Duration::class,
                \Folklore\Mediatheque\Contracts\Services\Thumbnail::class,
                \Folklore\Mediatheque\Contracts\Services\Mime::class,
                \Folklore\Mediatheque\Contracts\Services\Extension::class,
                \Folklore\Mediatheque\Contracts\Services\Metadata::class
            ],
            'mediatheque.services.ffmpeg' => [
                \Folklore\Mediatheque\Contracts\Services\VideoDimension::class,
                \Folklore\Mediatheque\Contracts\Services\AudioDuration::class,
                \Folklore\Mediatheque\Contracts\Services\VideoDuration::class,
                \Folklore\Mediatheque\Contracts\Services\VideoThumbnail::class
            ],
            'mediatheque.services.audiowaveform' => [
                \Folklore\Mediatheque\Contracts\Services\AudioThumbnail::class
            ],
            'mediatheque.services.path_formatter' => [
                \Folklore\Mediatheque\Contracts\Services\PathFormatter::class
            ]
        ];
        foreach ($services as $key => $aliases) {
            foreach ($aliases as $alias) {
                $this->app->alias($key, $alias);
            }
        }
    }

    protected function getRouter()
    {
        return $this->app->bound('router') ? $this->app['router'] : $this->app;
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
