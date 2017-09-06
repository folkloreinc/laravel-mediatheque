<?php namespace Folklore\Mediatheque;

use Illuminate\Support\ServiceProvider as BaseServiceProvider;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use Folklore\Mediatheque\Models\Observers\FileableObserver;
use Folklore\Mediatheque\Interfaces\FileableInterface;
use Folklore\Mediatheque\Contracts\ThumbnailCreator as ThumbnailCreatorContract;
use Folklore\Mediatheque\Contracts\DimensionGetter;
use Folklore\Mediatheque\Contracts\DurationGetter;
use Folklore\Mediatheque\Contracts\MimeGetter;
use Folklore\Mediatheque\Contracts\ExtensionGetter;
use Folklore\Mediatheque\Contracts\TypeGetter;
use Folklore\Mediatheque\Contracts\PagesCountGetter;
use Folklore\Mediatheque\Contracts\FamilyNameGetter;
use Folklore\Mediatheque\Services\MediaInfo;
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

        $this->mapRoutes();
    }

    public function bootPublishes()
    {
        // Config file path
        $configPath = __DIR__ . '/../../config/config.php';
        $migrationsPath = __DIR__ . '/../../migrations/';
        $routesPath = __DIR__ . '/../../routes/';

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

    public function mapRoutes()
    {
        if (! $this->app->routesAreCached()) {
            $router = $this->getRouter();
            $routesPath = is_file(base_path('routes/mediatheque.php')) ?
                base_path('routes/mediatheque.php') : (__DIR__ . '/../../routes/mediatheque.php');
            require $routesPath;
        }
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->registerModels();
        $this->registerSourceManager();
        $this->registerMediaInfo();
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
     * Register the models contracts
     *
     * @return void
     */
    public function registerModels()
    {
        $this->app->bind(
            \Folklore\Mediatheque\Contracts\Models\Picture::class,
            \Folklore\Mediatheque\Models\Picture::class
        );
        $this->app->bind(
            \Folklore\Mediatheque\Contracts\Models\File::class,
            \Folklore\Mediatheque\Models\File::class
        );
        $this->app->bind(
            \Folklore\Mediatheque\Contracts\Models\Audio::class,
            \Folklore\Mediatheque\Models\Audio::class
        );
        $this->app->bind(
            \Folklore\Mediatheque\Contracts\Models\Video::class,
            \Folklore\Mediatheque\Models\Video::class
        );
        $this->app->bind(
            \Folklore\Mediatheque\Contracts\Models\Document::class,
            \Folklore\Mediatheque\Models\Document::class
        );
        $this->app->bind(
            \Folklore\Mediatheque\Contracts\Models\Text::class,
            \Folklore\Mediatheque\Models\Text::class
        );
        $this->app->bind(
            \Folklore\Mediatheque\Contracts\Models\Font::class,
            \Folklore\Mediatheque\Models\Font::class
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
    public function registerMediaInfo()
    {
        $this->app->bind('mediatheque.services.mediainfo', function ($app) {
            return new MediaInfo($app);
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
        $this->app->bind(MimeGetter::class, 'mediatheque.services.mediainfo');
    }

    /**
     * Register the extension getter
     *
     * @return void
     */
    public function registerExtensionGetter()
    {
        $this->app->bind(ExtensionGetter::class, 'mediatheque.services.mediainfo');
    }

    /**
     * Register the type getter
     *
     * @return void
     */
    public function registerTypeGetter()
    {
        $this->app->bind(TypeGetter::class, 'mediatheque.services.mediainfo');
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
        $this->app->bind(DimensionGetter::class, 'mediatheque.services.mediainfo');
        $this->app->bind('mediatheque.services.dimension.picture', 'mediatheque.services.imagick');
        $this->app->bind('mediatheque.services.dimension.video', 'mediatheque.services.ffmpeg');
    }

    /**
     * Register the duration getter
     *
     * @return void
     */
    public function registerDurationGetter()
    {
        $this->app->bind(DurationGetter::class, 'mediatheque.services.mediainfo');
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
        $this->app->bind(PagesCountGetter::class, 'mediatheque.services.mediainfo');
        $this->app->bind('mediatheque.services.pagescount', 'mediatheque.services.imagick');
    }

    /**
     * Register the pages count getter
     *
     * @return void
     */
    public function registerFamilyName()
    {
        $this->app->bind(FamilyNameGetter::class, 'mediatheque.services.mediainfo');
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
