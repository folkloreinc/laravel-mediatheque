<?php

return [

    'table_prefix' => 'mediatheque_',

    'routes' => [
        'prefix' => 'mediatheque',
        'namespace' => 'Folklore\Mediatheque\Http\Controllers',
        'middleware' => ['api'],
        'controllers' => [
            'upload' => 'UploadController',
            'image' => 'ImageController',
            'audio' => 'AudioController',
            'video' => 'VideoController',
            'document' => 'DocumentController',
            'font' => 'FontController',
        ],
    ],

    'types' => [
        'image' => [
            'mimes' => [
                'image/*' => '*',
                'image/jpeg' => 'jpg',
                'image/x-png' => 'png',
                'image/x-gif' => 'gif',
                'image/svg+xml' => 'svg',
                'image/xml' => 'svg',
            ],
        ],

        'audio' => [
            'mimes' => [
                'audio/*' => '*',
                'audio/wave' => 'wav',
                'audio/x-wave' => 'wav',
                'audio/x-wav' => 'wav',
                'audio/mpeg' => 'mp3',
            ]
        ],

        'video' => [
            'mimes' => [
                'video/*' => '*',
                'video/quicktime' => 'mov',
                'video/mpeg' => 'mp4',
                'video/mpeg-4' => 'mp4',
                'video/x-m4v' => 'mp4'
            ]
        ],

        'document' => [
            'mimes' => [
                'application/pdf' => 'pdf',
                'application/octet-stream' => '*',
                'text/plain' => '*'
            ]
        ],

        'font' => [
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
    ],

    'events' => [
        'created' => \Folklore\Mediatheque\Events\MediaCreated::class,
        'updated' => \Folklore\Mediatheque\Events\MediaUpdated::class,
        'deleting' => \Folklore\Mediatheque\Events\MediaDeleting::class,
        'saved' => \Folklore\Mediatheque\Events\MediaSaved::class,
        'restored' => \Folklore\Mediatheque\Events\MediaRestored::class,
    ],

];
