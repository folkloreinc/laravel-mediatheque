<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Table Prefix
    |--------------------------------------------------------------------------
    |
    | The table prefix used for each table created by this package
    |
    */
    'table_prefix' => 'mediatheque_',

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | Default configuration for routing in the packages.
    |
    */
    'routes' => [
        'prefix' => 'mediatheque',
        'domain' => null,
        'namespace' => null,
        'middleware' => ['api'],

        'upload' => [
            'prefix' => 'upload',
            'controller' => \Folklore\Mediatheque\Http\Controllers\UploadController::class,
        ],

        'api' => [
            'prefix' => 'api',
            'controllers' => [
                'audio' => \Folklore\Mediatheque\Http\Controllers\AudioController::class,
                'document' => \Folklore\Mediatheque\Http\Controllers\DocumentController::class,
                'font' => \Folklore\Mediatheque\Http\Controllers\FontController::class,
                'image' => \Folklore\Mediatheque\Http\Controllers\ImageController::class,
                'video' => \Folklore\Mediatheque\Http\Controllers\VideoController::class,
            ],
        ]
    ],

    /*
    |--------------------------------------------------------------------------
    | Sources
    |--------------------------------------------------------------------------
    |
    | Configuration of media sources. You can define multiple sources as well
    | as the default source. Available drives are: "local", "filesystem"
    |
    */
    'source' => 'public',

    'sources' => [
        'public' => [
            'driver' => 'local',
            'path' => public_path('files'),
            'url' => env('APP_URL').'/files'
        ],

        'cloud' => [
            'driver' => 'filesystem',
            'disk' => 'public',
            'path' => '/',
            'cache' => false
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Files
    |--------------------------------------------------------------------------
    |
    | When files are copied on the source, this is the format that is used to
    | generate the path.
    |
    */
    'file_path_format' => '{type}/{date(Y-m-d)}/{id}-{date(his)}.{extension}',

    /*
    |--------------------------------------------------------------------------
    | Media types
    |--------------------------------------------------------------------------
    |
    | This defines configuration for each media types. It list the default pipeline
    | that will be executed when a media is created and also the mimes types and
    | extensions that are used to detect media types.
    |
    */
    'types' => [
        'audio' => [
            'model' => \Folklore\Mediatheque\Models\Audio::class,
            'pipeline' => 'audio',
            'mimes' => [
                'audio/*' => '*',
                'audio/wave' => 'wav',
                'audio/x-wave' => 'wav',
                'audio/x-wav' => 'wav',
                'audio/mpeg' => 'mp3',
            ]
        ],

        'document' => [
            'model' => \Folklore\Mediatheque\Models\Document::class,
            'pipeline' => 'document',
            'mimes' => [
                'application/pdf' => 'pdf',
                'application/octet-stream' => '*',
                'text/plain' => '*'
            ]
        ],

        'font' => [
            'model' => \Folklore\Mediatheque\Models\Font::class,
            'pipeline' => 'font',
            'mimes' => [
                'application/x-font-truetype' => 'ttf',
                'application/x-font-ttf' => 'ttf',
                'application/x-font-opentype' => 'otf',
                'application/vnd.ms-opentype' => 'otf',
                'application/vnd.ms-fontobject' => 'eot',
                'inode/x-empty' => 'eot',
                'application/x-font-woff' => 'woff',
                'application/font-woff' => 'woff',
                'application/font-woff2' => 'woff2',
                'font/woff2' => 'woff2'
            ]
        ],

        'image' => [
            'model' => \Folklore\Mediatheque\Models\Image::class,
            'pipeline' => 'image',
            'mimes' => [
                'image/*' => '*',
                'image/jpeg' => 'jpg',
                'image/x-png' => 'png',
                'image/x-gif' => 'gif',
                'image/svg+xml' => 'svg',
                'image/xml' => 'svg',
            ],
        ],

        'video' => [
            'model' => \Folklore\Mediatheque\Models\Video::class,
            'pipeline' => 'video',
            'mimes' => [
                'video/*' => '*',
                'video/quicktime' => 'mov',
                'video/mpeg' => 'mp4',
                'video/mpeg-4' => 'mp4',
                'video/x-m4v' => 'mp4'
            ]
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pipelines
    |--------------------------------------------------------------------------
    |
    | Pipelines are groups of jobs that are executed on media to generate files
    | from original or other media files.
    |
    */
    'pipelines' => [

        'video' => [
            'queue' => true,
            'jobs' => [
                'h264' => \Folklore\Mediatheque\Jobs\Video\H264::class,
                'webm' => \Folklore\Mediatheque\Jobs\Video\WebM::class,
                'thumbnails' => [
                    'job' => \Folklore\Mediatheque\Jobs\Video\Thumbnails::class,
                    'count' => 5,
                    'in_middle' => true,
                ],
            ]
        ],

        'audio' => [
            'queue' => true,
            'jobs' => [
                'thumbnails' => [
                    'job' => \Folklore\Mediatheque\Jobs\Audio\Thumbnails::class,
                    'zoom' => 600,
                    'width' => 1200,
                    'height' => 400,
                    'axis_label' => false,
                    'background_color' => 'FFFFFF00',
                    'color' => '000000',
                    'border_color' => null,
                    'axis_label_color' => null,
                ],
            ]
        ],

        'document' => [
            'queue' => true,
            'jobs' => [
                'thumbnails' => [
                    'job' => \Folklore\Mediatheque\Jobs\Document\Thumbnails::class,
                    'count' => 'all',
                    'resolution' => 150,
                    'quality' => 100,
                    'background' => 'white',
                    'format' => 'jpeg',
                    'font' => storage_path('mediatheque/fonts/arial.ttf'),
                ],
            ]
        ],

        'font' => [
            'queue' => true,
            'jobs' => [
                'webfonts' => \Folklore\Mediatheque\Jobs\Font\WebFonts::class,
            ]
        ]

    ],

    /*
    |--------------------------------------------------------------------------
    | Events
    |--------------------------------------------------------------------------
    |
    | The events class dispatched by the package
    |
    */
    'events' => [
        'created' => \Folklore\Mediatheque\Events\MediaCreated::class,
        'updated' => \Folklore\Mediatheque\Events\MediaUpdated::class,
        'deleting' => \Folklore\Mediatheque\Events\MediaDeleting::class,
        'saved' => \Folklore\Mediatheque\Events\MediaSaved::class,
        'restored' => \Folklore\Mediatheque\Events\MediaRestored::class,
        'file_attached' => \Folklore\Mediatheque\Events\FileAttached::class,
        'file_detached' => \Folklore\Mediatheque\Events\FileDetached::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Observers
    |--------------------------------------------------------------------------
    |
    | The events observers used by the package
    |
    */
    'observers' => [
        'media' => \Folklore\Mediatheque\Observers\MediaObserver::class,
        'has_files' => \Folklore\Mediatheque\Observers\HasFilesObserver::class,
        'file' => \Folklore\Mediatheque\Observers\FileObserver::class,
        'pipeline' => \Folklore\Mediatheque\Observers\PipelineObserver::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Debug
    |--------------------------------------------------------------------------
    |
    | This setting will disable "graceful" error handling, especially with
    | services.
    |
    */
    'debug' => env('MEDIATHEQUE_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Services
    |--------------------------------------------------------------------------
    |
    | Configuration of services used by this package
    |
    */
    'services' => [
        'ffmpeg' => [
            'ffmpeg.binaries'  => env('FFMPEG_BIN', '/usr/local/bin/ffmpeg'),
            'ffprobe.binaries' => env('FFPROBE_BIN', '/usr/local/bin/ffprobe')
        ],

        'audiowaveform' => [
            'bin'  => env('AUDIOWAVEFORM_BIN', '/usr/local/bin/audiowaveform')
        ],

        'imagick' => [
            'convert'  => env('IMAGICK_CONVERT_BIN', '/usr/local/bin/convert')
        ],

        'otfinfo' => [
            'bin' => env('OTFINFO_BIN', '/usr/local/bin/otfinfo')
        ],

        'convertFonts' => [
            'bin' => env('CONVERTFONTS_BIN', '/usr/local/bin/convertFonts')
        ]
    ]

];
