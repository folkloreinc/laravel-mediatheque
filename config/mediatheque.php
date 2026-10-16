<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Table prefix
    |--------------------------------------------------------------------------
    |
    | The prefix of the package's tables. 2.x keeps the 1.x tables and their
    | names, so an upgraded application keeps its own prefix here.
    |
    */
    'table_prefix' => 'mediatheque_',

    /*
    |--------------------------------------------------------------------------
    | Ids
    |--------------------------------------------------------------------------
    |
    | Media get a prefixed ULID as their public id (med_01JAB3…), which
    | `media://` references use.
    |
    */
    'ids' => [
        'media_prefix' => 'med',
    ],

    /*
    |--------------------------------------------------------------------------
    | Source
    |--------------------------------------------------------------------------
    |
    | Where originals and renditions are stored by default.
    |
    */
    'source' => env('MEDIATHEQUE_SOURCE', 'public'),

    /*
    |--------------------------------------------------------------------------
    | Ingest
    |--------------------------------------------------------------------------
    */
    'ingest' => [
        'visibility' => 'public',
    ],

    /*
    |--------------------------------------------------------------------------
    | Types
    |--------------------------------------------------------------------------
    |
    | A type is detected from the mime sniffed from the content: the first
    | type with a matching pattern wins, and a file that matches none is
    | rejected. Each type runs the pipeline of the same name by default.
    |
    */
    'types' => [
        'video' => [
            'mimes' => ['video/*'],
        ],
        'audio' => [
            'mimes' => ['audio/*'],
        ],
        'image' => [
            'mimes' => ['image/*'],
            // Image renditions come from laravel-image, on the fly
            'pipeline' => null,
        ],
        'document' => [
            'mimes' => ['application/pdf'],
        ],
        'font' => [
            'mimes' => ['font/*', 'application/font-*', 'application/x-font-*', 'application/vnd.ms-opentype'],
        ],
        'captions' => [
            'mimes' => ['text/vtt', 'application/x-subrip', 'text/srt'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pipelines
    |--------------------------------------------------------------------------
    |
    | A pipeline is a set of renditions run by one transcoder. A rendition
    | has an operation (`op`) and its options, and can set `from`, `when`,
    | `keep`, `queue` and `role`. Rendition names start with a letter, then
    | letters, digits, "_" and "-", 64 characters at most.
    |
    */
    'pipelines' => [
        'video' => [
            'transcoder' => env('MEDIATHEQUE_TRANSCODER', 'ffmpeg'),
            'source' => null,
            'renditions' => [
                'h264' => ['op' => 'video.transcode', 'codec' => 'h264', 'max_width' => 1920],
                'poster' => ['op' => 'video.poster', 'role' => 'poster'],
                'thumbnails' => ['op' => 'video.thumbnails', 'count' => 5, 'role' => 'thumbnail'],
            ],
        ],
        'audio' => [
            'transcoder' => env('MEDIATHEQUE_TRANSCODER', 'ffmpeg'),
            'source' => null,
            'renditions' => [
                'mp3' => ['op' => 'audio.transcode', 'codec' => 'mp3'],
                'waveform' => ['op' => 'audio.waveform', 'role' => 'thumbnail'],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | URLs
    |--------------------------------------------------------------------------
    |
    | Private media only get temporary URLs, valid for this many seconds.
    |
    */
    'urls' => [
        'temporary_ttl' => 3600,
    ],
];
