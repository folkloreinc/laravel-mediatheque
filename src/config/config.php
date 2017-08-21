<?php

return [

    'table_prefix' => 'mediatheque_',

    'route_prefix' => 'mediatheque',

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

    'path_format' => '{type}/{date(Y-m-d)}/{id}-{date(his)}.{extension}',

    'mimes' => [
        'picture' => [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/x-png' => 'png',
            'image/gif' => 'gif',
            'image/x-gif' => 'gif',
            'image/svg+xml' => 'svg',
            'image/xml' => 'svg',
            'image/svg' => 'svg',
        ],

        'audio' => [
            'audio/wave' => 'wav',
            'audio/x-wave' => 'wav',
            'audio/wav' => 'wav',
            'audio/x-wav' => 'wav',
            'audio/mpeg' => 'mp3',
            'audio/mp3' => 'mp3',
        ],

        'video' => [
            'video/quicktime' => 'mov',
            'video/mpeg' => 'mp4',
            'video/mpeg-4' => 'mp4',
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            'video/ogv' => 'ogv',
            'video/avi' => 'avi',
            'video/x-m4v' => 'mp4'
        ],

        'document' => [
            'application/pdf' => 'pdf',
            'application/octet-stream' => '*',
            'text/plain' => '*'
        ],

        'font' => [
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
        ],
    ],

    'file_creators_use_queue' => true,

    'events' => [
        'created' => \Folklore\Mediatheque\Events\MediaCreated::class,
        'updated' => \Folklore\Mediatheque\Events\MediaUpdated::class,
        'deleting' => \Folklore\Mediatheque\Events\MediaDeleting::class,
        'saved' => \Folklore\Mediatheque\Events\MediaSaved::class,
        'restored' => \Folklore\Mediatheque\Events\MediaRestored::class,
    ],

    'thumbnails' => [
        'enable' => true,

        'tmp_path' => sys_get_temp_dir(),

        'video' => [
            'enable' => true,
            'count' => 5,
            'in_middle' => true
        ],

        'audio' => [
            'enable' => true,
            'zoom' => 600,
            'width' => 1200,
            'height' => 400,
            'axis_label' => false,
            'background_color' => 'FFFFFF00',
            'color' => '000000',
            'border_color' => null,
            'axis_label_color' => null
        ],

        'document' => [
            'enable' => true,
            'count' => 'all',
            'resolution' => 150,
            'quality' => 100,
            'background' => 'white',
            'format' => 'jpeg',
            'font' => storage_path('mediatheque/fonts/arial.ttf')
        ]
    ],

    'programs' => [
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
    ],

];
