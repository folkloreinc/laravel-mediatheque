<?php

namespace Folklore\Mediatheque;

use Folklore\Mediatheque\Console\Commands\PipelineRun;
use Folklore\Mediatheque\Contracts\Services\AudioDuration;
use Folklore\Mediatheque\Contracts\Services\AudioThumbnail;
use Folklore\Mediatheque\Contracts\Services\AudioTracks;
use Folklore\Mediatheque\Contracts\Services\Color;
use Folklore\Mediatheque\Contracts\Services\Dimension;
use Folklore\Mediatheque\Contracts\Services\DocumentThumbnail;
use Folklore\Mediatheque\Contracts\Services\Duration;
use Folklore\Mediatheque\Contracts\Services\Extension;
use Folklore\Mediatheque\Contracts\Services\FontFamilyName;
use Folklore\Mediatheque\Contracts\Services\ImageDimension;
use Folklore\Mediatheque\Contracts\Services\ImageThumbnail;
use Folklore\Mediatheque\Contracts\Services\Mime;
use Folklore\Mediatheque\Contracts\Services\PagesCount;
use Folklore\Mediatheque\Contracts\Services\Palette;
use Folklore\Mediatheque\Contracts\Services\Svg;
use Folklore\Mediatheque\Contracts\Services\Thumbnail;
use Folklore\Mediatheque\Contracts\Services\VideoDimension;
use Folklore\Mediatheque\Contracts\Services\VideoDuration;
use Folklore\Mediatheque\Contracts\Services\VideoThumbnail;
use Folklore\Mediatheque\Contracts\Services\Waveform;
use Folklore\Mediatheque\Contracts\Type\Factory;
use Folklore\Mediatheque\Events\FileAttached;
use Folklore\Mediatheque\Events\FileDetached;
use Folklore\Mediatheque\Models\File;
use Folklore\Mediatheque\Models\Media;
use Folklore\Mediatheque\Models\Metadata;
use Folklore\Mediatheque\Models\Pipeline;
use Folklore\Mediatheque\Models\PipelineJob;
use Folklore\Mediatheque\Observers\FileObserver;
use Folklore\Mediatheque\Services\AnimatedImage;
use Folklore\Mediatheque\Services\AudioWaveForm;
use Folklore\Mediatheque\Services\ColorExtractor;
use Folklore\Mediatheque\Services\FFMpeg;
use Folklore\Mediatheque\Services\Gif;
use Folklore\Mediatheque\Services\Imagick;
use Folklore\Mediatheque\Services\ImagineSvg;
use Folklore\Mediatheque\Services\MediaConvertClient;
use Folklore\Mediatheque\Services\OtfInfo;
use Folklore\Mediatheque\Services\PathFormatter;
use Folklore\Mediatheque\Services\Webp;
use Illuminate\Support\Arr;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use InvalidArgumentException;
use Symfony\Component\Mime\MimeTypeGuesserInterface;
use Symfony\Component\Mime\MimeTypes;

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

        // Console
        if ($this->app->runningInConsole()) {
            $this->commands([PipelineRun::class]);
        }
    }

    public function bootPublishes()
    {
        // Config file path
        $configPath = __DIR__.'/../../config/config.php';
        $migrationsPath = __DIR__.'/../../migrations';
        $routesPath = __DIR__.'/../../routes.php';

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
            FileAttached::class,
            FileObserver::class.'@attached'
        );
        $this->app['events']->listen(
            FileDetached::class,
            FileObserver::class.'@detached'
        );
    }

    public function bootRouter()
    {
        $app = $this->app;

        $this->app['router']->macro('mediatheque', function ($opts = []) use ($app) {
            return $app['mediatheque.router']->mediatheque($opts);
        });

        $map = $this->app['config']->get('mediatheque.routes.map');
        if (! is_null($map)) {
            $this->loadRoutesFrom(file_exists($map) ? $map : __DIR__.'/../../routes.php');
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
        $this->registerMediaConvert();
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
        $this->app->bind(Factory::class, 'mediatheque.types');
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
            Contracts\Pipeline\Factory::class,
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
            Contracts\Source\Factory::class,
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
            Contracts\Metadata\Factory::class,
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
                    ).'.'
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
        $this->app->bind(MimeTypeGuesserInterface::class, function () {
            return new MimeTypes;
        });
    }

    public function registerMediaConvert()
    {
        $this->app->singleton('mediatheque.media_convert', function ($app) {
            $config = $app['config']->get('mediatheque.services.mediaConvert', []);

            $filesystem = data_get($config, 'disk', 's3');
            $disk = $app['config']->get('filesystems.disks.'.$filesystem, []);
            if (empty($disk)) {
                throw new InvalidArgumentException(
                    'Media Convert filesystem configuration is required.'
                );
            }
            $key = Arr::get($disk, 'key');
            $secret = Arr::get($disk, 'secret');
            $region = Arr::get($disk, 'region', 'us-east-1');

            $role = data_get($config, 'role', null);
            $queue = data_get($config, 'queue', null);
            $endpoint = data_get($config, 'endpoint', null);

            return new MediaConvertClient(
                $key,
                $secret,
                $role,
                $queue,
                array_merge(
                    data_get($config, 'config', []),
                    ['region' => $region],
                    ! empty($endpoint) ? ['endpoint' => $endpoint] : []
                )
            );
        });

        $this->app->bind(
            Contracts\Services\MediaConvertClient::class,
            'mediatheque.media_convert'
        );
    }

    /**
     * Register the models contracts
     *
     * @return void
     */
    public function registerModels()
    {
        $this->app->bind(
            Contracts\Models\Media::class,
            Media::class
        );

        $this->app->bind(
            Contracts\Models\Metadata::class,
            Metadata::class
        );

        $this->app->bind(
            Contracts\Models\File::class,
            File::class
        );

        $this->app->bind(
            Contracts\Models\Pipeline::class,
            Pipeline::class
        );

        $this->app->bind(
            Contracts\Models\PipelineJob::class,
            PipelineJob::class
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
            Services\Metadata::class
        );
        $this->app->singleton(
            'mediatheque.services.ffmpeg',
            FFMpeg::class
        );
        $this->app->singleton(
            'mediatheque.services.imagick',
            Imagick::class
        );
        $this->app->singleton(
            'mediatheque.services.audiowaveform',
            AudioWaveForm::class
        );
        $this->app->singleton(
            'mediatheque.services.otfinfo',
            OtfInfo::class
        );
        $this->app->singleton(
            'mediatheque.services.path_formatter',
            PathFormatter::class
        );
        $this->app->singleton(
            'mediatheque.services.color_extractor',
            ColorExtractor::class
        );
        $this->app->singleton(
            'mediatheque.services.gif',
            Gif::class
        );
        $this->app->singleton(
            'mediatheque.services.webp',
            Webp::class
        );
        $this->app->singleton(
            'mediatheque.services.animated_image',
            AnimatedImage::class
        );
        $this->app->singleton(
            'mediatheque.services.svg',
            ImagineSvg::class
        );

        $services = [
            'mediatheque.services.animated_image' => [
                Contracts\Services\AnimatedImage::class,
            ],
            'mediatheque.services.gif' => [Contracts\Services\Gif::class],
            'mediatheque.services.webp' => [Contracts\Services\Webp::class],
            'mediatheque.services.svg' => [Svg::class],
            'mediatheque.services.otfinfo' => [
                FontFamilyName::class,
            ],
            'mediatheque.services.imagick' => [
                ImageDimension::class,
                PagesCount::class,
                DocumentThumbnail::class,
                ImageThumbnail::class,
            ],
            'mediatheque.services.metadata' => [
                Dimension::class,
                Duration::class,
                Thumbnail::class,
                Mime::class,
                Extension::class,
                Contracts\Services\Metadata::class,
            ],
            'mediatheque.services.ffmpeg' => [
                VideoDimension::class,
                AudioDuration::class,
                VideoDuration::class,
                VideoThumbnail::class,
                AudioTracks::class,
            ],
            'mediatheque.services.audiowaveform' => [
                AudioThumbnail::class,
                Waveform::class,
            ],
            'mediatheque.services.path_formatter' => [
                Contracts\Services\PathFormatter::class,
            ],
            'mediatheque.services.color_extractor' => [
                Color::class,
                Palette::class,
            ],
        ];
        foreach ($services as $key => $aliases) {
            foreach ($aliases as $alias) {
                $this->app->alias($key, $alias);
            }
        }
    }

    protected function registerConfigInjections($classes, $injections)
    {
        foreach ($injections as $variable => $configKey) {
            $this->app
                ->when($classes)
                ->needs($variable)
                ->give(function () use ($configKey) {
                    return $this->app['config']->get($configKey);
                });
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
