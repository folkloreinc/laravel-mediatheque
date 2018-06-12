<?php namespace Folklore\Mediatheque;

use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Bus\Dispatcher;
use Folklore\Mediatheque\Jobs\Handler;
use Folklore\Mediatheque\Support\Interfaces\HasFiles as HasFilesInterface;
use Folklore\Mediatheque\Support\Interfaces\HasPipelines as HasPipelinesInterface;
use Folklore\Mediatheque\Contracts\Model\Audio as AudioContract;
use Folklore\Mediatheque\Contracts\Model\Document as DocumentContract;
use Folklore\Mediatheque\Contracts\Model\Font as FontContract;
use Folklore\Mediatheque\Contracts\Model\Image as ImageContract;
use Folklore\Mediatheque\Contracts\Model\Video as VideoContract;
use Folklore\Mediatheque\Contracts\Model\File as FileContract;
use Folklore\Mediatheque\Contracts\Model\Pipeline as PipelineModelContract;
use Folklore\Mediatheque\Contracts\Model\PipelineJob as PipelineJobContract;
use Folklore\Mediatheque\Contracts\Type as TypeContract;
use Folklore\Mediatheque\Contracts\Pipeline as PipelineContract;
use Folklore\Mediatheque\Contracts\ThumbnailCreator as ThumbnailCreatorContract;
use Folklore\Mediatheque\Contracts\Getter\Metadata as MetadataGetter;
use Folklore\Mediatheque\Contracts\Getter\Dimension as DimensionGetter;
use Folklore\Mediatheque\Contracts\Getter\Duration as DurationGetter;
use Folklore\Mediatheque\Contracts\Getter\Mime as MimeGetter;
use Folklore\Mediatheque\Contracts\Getter\Extension as ExtensionGetter;
use Folklore\Mediatheque\Contracts\Getter\Type as TypeGetter;
use Folklore\Mediatheque\Contracts\Getter\PagesCount as PagesCountGetter;
use Folklore\Mediatheque\Contracts\Getter\FamilyName as FamilyNameGetter;
use Folklore\Mediatheque\Services\Metadata;
use Folklore\Mediatheque\Services\ThumbnailCreator;
use Folklore\Mediatheque\Services\FFMpeg;
use Folklore\Mediatheque\Services\Imagick;
use Folklore\Mediatheque\Services\AudioWaveForm;
use Folklore\Mediatheque\Services\OtfInfo;
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
        $this->publishes([
            $migrationsPath => base_path('database/migrations')
        ], 'migrations');

        $this->publishes([
            $configPath => config_path('mediatheque.php')
        ], 'config');
    }

    public function bootEvents()
    {
        // File attach and detach event
        $fileObserver = $this->app['config']->get('mediatheque.observers.file');
        $fileAttachedEvent = $this->app['config']->get('mediatheque.events.file_attached', null);
        if (!is_null($fileAttachedEvent)) {
            $this->app['events']->listen($fileAttachedEvent, $fileObserver.'@attached');
        }

        $fileDetachedEvent = $this->app['config']->get('mediatheque.events.file_detached', null);
        if (!is_null($fileDetachedEvent)) {
            $this->app['events']->listen($fileDetachedEvent, $fileObserver.'@detached');
        }
    }

    public function bootDispatcher()
    {
        $dispatcher = app(Dispatcher::class);
        if (method_exists($dispatcher, 'mapUsing')) {
            $dispatcher->mapUsing(function ($command) {
                if ($command instanceof \Folklore\Mediatheque\Jobs\RunPipeline ||
                    $command instanceof \Folklore\Mediatheque\Jobs\RunPipelineJob ||
                    $command instanceof \Folklore\Mediatheque\Support\PipelineJob
                ) {
                    return Handler::class.'@handle';
                }
                $className = get_class($command);
                throw new InvalidArgumentException("No handler registered for command [{$className}]");
            });
        }
    }

    public function bootRouter()
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        $config = $this->app['config']->get('mediatheque.routes', []);
        $router = app()->bound('router') ? app('router') : app();
        $groupConfig = array_only($config, ['middleware', 'domain', 'prefix', 'namespace']);
        $router->group($groupConfig, function ($router) use ($config) {
            if (array_get($config, 'api', null) !== false) {
                require __DIR__ .'/../../routes/api.php';
            }
            if (array_get($config, 'upload', null) !== false) {
                require __DIR__ .'/../../routes/upload.php';
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
        $this->registerMediatheque();
        $this->registerModels();
        $this->registerPipeline();
        $this->registerType();
        $this->registerSourceManager();
        $this->registerServices();
        $this->registerThumbnailCreator();
        $this->registerGetters();
    }

    /**
     * Register mediatheque
     *
     * @return void
     */
    public function registerMediatheque()
    {
        $this->app->singleton('mediatheque', function ($app) {
            $mediatheque = new Mediatheque($app);
            $mediatheque->setPipelines($app['config']->get('mediatheque.pipelines', []));
            $mediatheque->setTypes($app['config']->get('mediatheque.types', []));
            return $mediatheque;
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
            AudioContract::class,
            \Folklore\Mediatheque\Models\Audio::class
        );

        $this->app->bind(
            DocumentContract::class,
            \Folklore\Mediatheque\Models\Document::class
        );

        $this->app->bind(
            FontContract::class,
            \Folklore\Mediatheque\Models\Font::class
        );

        $this->app->bind(
            ImageContract::class,
            \Folklore\Mediatheque\Models\Image::class
        );

        $this->app->bind(
            VideoContract::class,
            \Folklore\Mediatheque\Models\Video::class
        );

        $this->app->bind(
            FileContract::class,
            \Folklore\Mediatheque\Models\File::class
        );
        $this->app->bind(
            PipelineModelContract::class,
            \Folklore\Mediatheque\Models\Pipeline::class
        );
        $this->app->bind(
            PipelineJobContract::class,
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
            PipelineContract::class,
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
            TypeContract::class,
            \Folklore\Mediatheque\Support\Type::class
        );
    }

    /**
     * Register the source manager
     *
     * @return void
     */
    public function registerSourceManager()
    {
        $this->app->bind('mediatheque.source', function ($app) {
            return new SourceManager($app);
        });
    }

    /**
     * Register services
     *
     * @return void
     */
    public function registerServices()
    {
        $this->app->singleton('mediatheque.services.metadata', Metadata::class);
        $this->app->singleton('mediatheque.services.ffmpeg', FFMpeg::class);
        $this->app->singleton('mediatheque.services.imagick', Imagick::class);
        $this->app->singleton('mediatheque.services.audiowaveform', AudioWaveForm::class);
        $this->app->singleton('mediatheque.services.otfinfo', OtfInfo::class);
    }

    /**
     * Register getters
     *
     * @return void
     */
    public function registerGetters()
    {
        $this->app->bind(MimeGetter::class, 'mediatheque.services.metadata');
        $this->app->bind(ExtensionGetter::class, 'mediatheque.services.metadata');
        $this->app->bind(MetadataGetter::class, 'mediatheque.services.metadata');
        $this->app->bind(TypeGetter::class, 'mediatheque.services.metadata');

        $this->app->bind(DimensionGetter::class, 'mediatheque.services.metadata');
        $this->app->bind('mediatheque.services.dimension.image', 'mediatheque.services.imagick');
        $this->app->bind('mediatheque.services.dimension.video', 'mediatheque.services.ffmpeg');

        $this->app->bind(DurationGetter::class, 'mediatheque.services.metadata');
        $this->app->bind('mediatheque.services.duration.audio', 'mediatheque.services.ffmpeg');
        $this->app->bind('mediatheque.services.duration.video', 'mediatheque.services.ffmpeg');

        $this->app->bind(PagesCountGetter::class, 'mediatheque.services.metadata');
        $this->app->bind('mediatheque.services.pagescount', 'mediatheque.services.imagick');

        $this->app->bind(FamilyNameGetter::class, 'mediatheque.services.metadata');
        $this->app->bind('mediatheque.services.familyname', 'mediatheque.services.otfinfo');
    }

    /**
     * Register the thumbnail creator
     *
     * @return void
     */
    public function registerThumbnailCreator()
    {
        $this->app->bind('mediatheque.services.thumbnail.audio', 'mediatheque.services.audiowaveform');
        $this->app->bind('mediatheque.services.thumbnail.video', 'mediatheque.services.ffmpeg');
        $this->app->bind('mediatheque.services.thumbnail.document', 'mediatheque.services.imagick');
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
