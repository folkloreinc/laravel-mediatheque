<?php namespace Folklore\Mediatheque;

use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Database\Eloquent\Model;
use Folklore\Mediatheque\Support\Interfaces\HasFiles as HasFilesInterface;
use Folklore\Mediatheque\Support\Interfaces\HasPipelines as HasPipelinesInterface;
use Folklore\Mediatheque\Contracts\Models\Audio as AudioContract;
use Folklore\Mediatheque\Contracts\Models\Document as DocumentContract;
use Folklore\Mediatheque\Contracts\Models\Font as FontContract;
use Folklore\Mediatheque\Contracts\Models\Image as ImageContract;
use Folklore\Mediatheque\Contracts\Models\Video as VideoContract;
use Folklore\Mediatheque\Contracts\Models\File as FileContract;
use Folklore\Mediatheque\Contracts\Models\Pipeline as PipelineContract;
use Folklore\Mediatheque\Contracts\Models\PipelineJob as PipelineJobContract;
use Folklore\Mediatheque\Contracts\ThumbnailCreator as ThumbnailCreatorContract;
use Folklore\Mediatheque\Contracts\MetadataGetter;
use Folklore\Mediatheque\Contracts\DimensionGetter;
use Folklore\Mediatheque\Contracts\DurationGetter;
use Folklore\Mediatheque\Contracts\MimeGetter;
use Folklore\Mediatheque\Contracts\ExtensionGetter;
use Folklore\Mediatheque\Contracts\TypeGetter;
use Folklore\Mediatheque\Contracts\PagesCountGetter;
use Folklore\Mediatheque\Contracts\FamilyNameGetter;
use Folklore\Mediatheque\Services\Metadata;
use Folklore\Mediatheque\Services\ThumbnailCreator;
use Folklore\Mediatheque\Services\FFMpeg;
use Folklore\Mediatheque\Services\Imagick;
use Folklore\Mediatheque\Services\AudioWaveForm;
use Folklore\Mediatheque\Services\OtfInfo;

class MediathequeServiceProvider extends BaseServiceProvider
{

    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = false;

    protected function getRouter()
    {
        return $this->app['router'];
    }

    /**
     * Bootstrap the application events.
     *
     * @return void
     */
    public function boot()
    {
        $this->bootPublishes();
        $this->bootEvents();
    }

    public function bootPublishes()
    {
        // Config file path
        $configPath = __DIR__ . '/../../config/config.php';
        $migrationsPath = __DIR__ . '/../../migrations';
        $routesPath = __DIR__ . '/../../routes';

        // Merge files
        $this->mergeConfigFrom($configPath, 'mediatheque');

        // Migrations
        if (method_exists($this, 'loadMigrationsFrom')) {
            $this->loadMigrationsFrom($migrationsPath);
        } else {
            $this->publishes([
                $migrationsPath => base_path('database/migrations')
            ], 'migrations');
        }

        // Publish
        $this->publishes([
            $configPath => config_path('mediatheque.php')
        ], 'config');

        $this->publishes([
            $routesPath => base_path('routes')
        ], 'routes');
    }

    public function bootEvents()
    {
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
        $this->registerSourceManager();
        $this->registerMetadata();
        $this->registerMetadataGetter();
        $this->registerFFMpeg();
        $this->registerImagick();
        $this->registerAudioWaveForm();
        $this->registerOtfInfo();
        $this->registerMimeGetter();
        $this->registerExtensionGetter();
        $this->registerTypeGetter();
        $this->registerThumbnailCreator();
        $this->registerDimensionGetter();
        $this->registerDurationGetter();
        $this->registerPagesCountGetter();
        $this->registerFamilyName();
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
        $this->app->bind(AudioContract::class, function () {
            $model = $this->app['config']->get('mediatheque.types.audio.model', null);
            return !is_null($model) ? new $model() : null;
        });

        $this->app->bind(DocumentContract::class, function () {
            $model = $this->app['config']->get('mediatheque.types.document.model', null);
            return !is_null($model) ? new $model() : null;
        });

        $this->app->bind(FontContract::class, function () {
            $model = $this->app['config']->get('mediatheque.types.font.model', null);
            return !is_null($model) ? new $model() : null;
        });

        $this->app->bind(ImageContract::class, function () {
            $model = $this->app['config']->get('mediatheque.types.image.model', null);
            return !is_null($model) ? new $model() : null;
        });

        $this->app->bind(VideoContract::class, function () {
            $model = $this->app['config']->get('mediatheque.types.video.model', null);
            return !is_null($model) ? new $model() : null;
        });

        $this->app->bind(
            FileContract::class,
            \Folklore\Mediatheque\Models\File::class
        );
        $this->app->bind(
            PipelineContract::class,
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
            \Folklore\Mediatheque\Contracts\Pipeline::class,
            \Folklore\Mediatheque\Support\Pipeline::class
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
     * Register the media info service
     *
     * @return void
     */
    public function registerMetadata()
    {
        $this->app->bind('mediatheque.services.metadata', function ($app) {
            return new Metadata($app);
        });
    }

    /**
     * Register the ffmpeg service
     *
     * @return void
     */
    public function registerFFMpeg()
    {
        $this->app->bind('mediatheque.services.ffmpeg', function ($app) {
            return new FFMpeg();
        });
    }

    /**
     * Register the imagick service
     *
     * @return void
     */
    public function registerImagick()
    {
        $this->app->bind('mediatheque.services.imagick', function ($app) {
            return new Imagick();
        });
    }

    /**
     * Register the imagick service
     *
     * @return void
     */
    public function registerAudioWaveForm()
    {
        $this->app->bind('mediatheque.services.audiowaveform', function ($app) {
            return new AudioWaveForm();
        });
    }

    /**
     * Register the imagick service
     *
     * @return void
     */
    public function registerOtfInfo()
    {
        $this->app->bind('mediatheque.services.otfinfo', function ($app) {
            return new OtfInfo();
        });
    }

    /**
     * Register the mime getter
     *
     * @return void
     */
    public function registerMimeGetter()
    {
        $this->app->bind(MimeGetter::class, 'mediatheque.services.metadata');
    }

    /**
     * Register the extension getter
     *
     * @return void
     */
    public function registerExtensionGetter()
    {
        $this->app->bind(ExtensionGetter::class, 'mediatheque.services.metadata');
    }

    /**
     * Register the type getter
     *
     * @return void
     */
    public function registerMetadataGetter()
    {
        $this->app->bind(MetadataGetter::class, 'mediatheque.services.metadata');
    }

    /**
     * Register the type getter
     *
     * @return void
     */
    public function registerTypeGetter()
    {
        $this->app->bind(TypeGetter::class, 'mediatheque.services.metadata');
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

    /**
     * Register the dimension getter
     *
     * @return void
     */
    public function registerDimensionGetter()
    {
        $this->app->bind(DimensionGetter::class, 'mediatheque.services.metadata');
        $this->app->bind('mediatheque.services.dimension.image', 'mediatheque.services.imagick');
        $this->app->bind('mediatheque.services.dimension.video', 'mediatheque.services.ffmpeg');
    }

    /**
     * Register the duration getter
     *
     * @return void
     */
    public function registerDurationGetter()
    {
        $this->app->bind(DurationGetter::class, 'mediatheque.services.metadata');
        $this->app->bind('mediatheque.services.duration.audio', 'mediatheque.services.ffmpeg');
        $this->app->bind('mediatheque.services.duration.video', 'mediatheque.services.ffmpeg');
    }

    /**
     * Register the pages count getter
     *
     * @return void
     */
    public function registerPagesCountGetter()
    {
        $this->app->bind(PagesCountGetter::class, 'mediatheque.services.metadata');
        $this->app->bind('mediatheque.services.pagescount', 'mediatheque.services.imagick');
    }

    /**
     * Register the pages count getter
     *
     * @return void
     */
    public function registerFamilyName()
    {
        $this->app->bind(FamilyNameGetter::class, 'mediatheque.services.metadata');
        $this->app->bind('mediatheque.services.familyname', 'mediatheque.services.otfinfo');
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
