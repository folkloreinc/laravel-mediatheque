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
            'url' => env('APP_URL') . '/files',
        ],

        'cloud' => [
            'driver' => 'filesystem',
            'disk' => 'public',
            'path' => '/',
            'visibility' => \Illuminate\Contracts\Filesystem\Filesystem::VISIBILITY_PUBLIC,
            'cache' => false,
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
            'pipeline' => 'audio',
            'can_upload' => true,
            'mimes' => [
                'audio/*' => '*',
                'audio/wave' => 'wav',
                'audio/x-wave' => 'wav',
                'audio/x-wav' => 'wav',
                'audio/mpeg' => 'mp3',
            ],
            'metadatas' => ['duration'],
        ],

        'document' => [
            'pipeline' => 'document',
            'can_upload' => true,
            'mimes' => [
                'application/pdf' => 'pdf',
                'application/octet-stream' => '*',
                'text/plain' => '*',
            ],
            'metadatas' => ['pages_count'],
        ],

        'font' => [
            'pipeline' => 'font',
            'can_upload' => true,
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
                'font/woff2' => 'woff2',
            ],
            'metadatas' => ['font_family_name'],
        ],

        'image' => [
            'pipeline' => 'image',
            'can_upload' => true,
            'mimes' => [
                'image/*' => '*',
                'image/jpeg' => 'jpg',
                'image/x-png' => 'png',
                'image/x-gif' => 'gif',
                'image/svg+xml' => 'svg',
                'image/xml' => 'svg',
            ],
            'metadatas' => ['dimension'],
        ],

        'video' => [
            'pipeline' => 'video',
            'can_upload' => true,
            'mimes' => [
                'video/*' => '*',
                'video/quicktime' => 'mov',
                'video/mpeg' => 'mp4',
                'video/mpeg-4' => 'mp4',
                'video/x-m4v' => 'mp4',
            ],
            'metadatas' => ['dimension', 'duration'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Metadata readers
    |--------------------------------------------------------------------------
    */
    'metadatas' => [
        'duration' => \Folklore\Mediatheque\Metadata\Duration::class,
        'dimension' => \Folklore\Mediatheque\Metadata\Dimension::class,
        'pages_count' => \Folklore\Mediatheque\Metadata\PagesCount::class,
        'font_family_name' => \Folklore\Mediatheque\Metadata\FontFamilyName::class,
        'colors' => \Folklore\Mediatheque\Metadata\Colors::class,
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
            'should_queue' => true,
            'jobs' => [
                'h264' => \Folklore\Mediatheque\Jobs\Video\H264::class,
                'webm' => \Folklore\Mediatheque\Jobs\Video\WebM::class,
                'thumbnails' => [
                    'job' => \Folklore\Mediatheque\Jobs\Video\Thumbnails::class,
                    'count' => 5,
                    'in_middle' => true,
                ],
            ],
        ],

        'audio' => [
            'should_queue' => true,
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
            ],
        ],

        'document' => [
            'should_queue' => true,
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
            ],
        ],

        'font' => [
            'should_queue' => true,
            'jobs' => [
                'webfonts' => \Folklore\Mediatheque\Jobs\Font\WebFonts::class,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    */
    'routes' => [
        // Path to the routes file that will be automatically loaded. Set to null
        // to prevent auto-loading of routes.
        'map' => base_path('routes/mediatheque.php'),

        'prefix' => 'mediatheque',

        'middleware' => [],
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
    | Process timeout
    |--------------------------------------------------------------------------
    |
    | This setting sets the timeout for jobs.
    |
    */
    'process_timeout' => 300,

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
            'ffmpeg.binaries' => env('FFMPEG_BIN', '/usr/local/bin/ffmpeg'),
            'ffprobe.binaries' => env('FFPROBE_BIN', '/usr/local/bin/ffprobe'),
        ],

        'audiowaveform' => [
            'bin' => env('AUDIOWAVEFORM_BIN', '/usr/local/bin/audiowaveform'),
        ],

        'imagick' => [
            'convert' => env('IMAGICK_CONVERT_BIN', '/usr/local/bin/convert'),
        ],

        'otfinfo' => [
            'bin' => env('OTFINFO_BIN', '/usr/local/bin/otfinfo'),
        ],

        'convertFonts' => [
            'bin' => env('CONVERTFONTS_BIN', '/usr/local/bin/convertFonts.sh'),
        ],
    ],
];
