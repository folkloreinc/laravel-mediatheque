<?php

use Folklore\Mediatheque\Jobs\Font\WebFonts;
use Folklore\Mediatheque\Jobs\Video\H264;
use Folklore\Mediatheque\Jobs\Video\HEVC;
use Folklore\Mediatheque\Jobs\Video\MediaConvert;
use Folklore\Mediatheque\Jobs\Video\Thumbnails;
use Folklore\Mediatheque\Jobs\Video\WebM;
use Folklore\Mediatheque\Metadata\AudioTracksCount;
use Folklore\Mediatheque\Metadata\Colors;
use Folklore\Mediatheque\Metadata\Dimension;
use Folklore\Mediatheque\Metadata\Duration;
use Folklore\Mediatheque\Metadata\FontFamilyName;
use Folklore\Mediatheque\Metadata\PagesCount;
use Folklore\Mediatheque\Metadata\Waveform;
use Folklore\Mediatheque\Types\Video;
use Illuminate\Contracts\Filesystem\Filesystem;

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
            'path' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
        ],

        'cloud' => [
            'driver' => 'filesystem',
            'disk' => 'public',
            'path' => '/',
            'visibility' => Filesystem::VISIBILITY_PUBLIC,
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
            'metadatas' => ['duration' /* 'waveform' */],
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

        'video' => [
            'type' => Video::class,
            'pipeline' => 'video',
            'can_upload' => true,
            'animated_image' => false, // Detect animated GIF and WebP as video
            'mimes' => [
                'video/*' => '*',
                'video/quicktime' => 'mov',
                'video/mpeg' => 'mp4',
                'video/mpeg-4' => 'mp4',
                'video/x-m4v' => 'mp4',
            ],
            'metadatas' => ['dimension', 'duration'],
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
    ],

    /*
    |--------------------------------------------------------------------------
    | Metadata readers
    |--------------------------------------------------------------------------
    */
    'metadatas' => [
        'duration' => Duration::class,
        'waveform' => Waveform::class,
        'dimension' => Dimension::class,
        'pages_count' => PagesCount::class,
        'audio_tracks_count' => AudioTracksCount::class,
        'font_family_name' => FontFamilyName::class,
        'colors' => Colors::class,
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
            'jobs' => array_filter([
                'h264' => H264::class,
                'webm' => WebM::class,
                'hevc' => HEVC::class,
                'thumbnails' => [
                    'job' => Thumbnails::class,
                    'count' => 5,
                    'in_middle' => true,
                ],
                // MediaConvert only runs when it is configured: without a role,
                // the AWS client cannot be created and the job fails.
                'media_convert' => env('AWS_MEDIACONVERT_ROLE') ? [
                    'job' => MediaConvert::class,
                    'outputs' => ['webm', 'h264'],
                    'max_width' => 1080,
                    'max_height' => 1080,
                    'bitrate' => 4000,
                ] : null,
            ]),
        ],

        'audio' => [
            'queue' => true,
            'jobs' => [
                'thumbnails' => [
                    'job' => Folklore\Mediatheque\Jobs\Audio\Thumbnails::class,
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
            'queue' => true,
            'jobs' => [
                'thumbnails' => [
                    'job' => Folklore\Mediatheque\Jobs\Document\Thumbnails::class,
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
            'queue' => true,
            'jobs' => [
                'webfonts' => WebFonts::class,
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
    'process_timeout' => 600,

    /*
    |--------------------------------------------------------------------------
    | Pipeline jobs
    |--------------------------------------------------------------------------
    |
    | Queue settings of each pipeline job. Leave them null to use the worker's
    | settings. The timeout, in seconds, must stay below the retry_after of the
    | queue connection, otherwise a job still running is released and run a
    | second time. A job that times out fails without being retried. A job of
    | a pipeline can override them with its own "timeout" and "tries" options.
    |
    */
    'jobs' => [
        'timeout' => env('MEDIATHEQUE_JOB_TIMEOUT'),
        'tries' => env('MEDIATHEQUE_JOB_TRIES'),
    ],

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

        'mediaConvert' => [
            'disk' => env('AWS_MEDIACONVERT_DISK', 's3'),
            'temp_path' => env('AWS_MEDIACONVERT_TEMP_PATH', 'converted'),
            'endpoint' => env('AWS_MEDIACONVERT_ENDPOINT', null), // 'https://api.mediaconvert.us-east-1.amazonaws.com',
            'role' => env('AWS_MEDIACONVERT_ROLE', null),
            'queue' => env('AWS_MEDIACONVERT_QUEUE', null),
            // 'key' => env('AWS_MEDIACONVERT_KEY', null),
            // 'secret' => env('AWS_MEDIACONVERT_SECRET', null),
            // 'region' => env('AWS_MEDIACONVERT_REGION', 'us-east-1'),
        ],
    ],
];
