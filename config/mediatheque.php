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
    | The disk of config/filesystems.php where originals are stored by
    | default. A pipeline writes its renditions to the original's disk,
    | unless it sets its own `source`.
    |
    */
    'source' => env('MEDIATHEQUE_SOURCE', 'public'),

    /*
    |--------------------------------------------------------------------------
    | Ingest
    |--------------------------------------------------------------------------
    */
    'ingest' => [
        // `public`, or `private` for media served only through temporary URLs
        'visibility' => 'public',

        // The largest file ingest accepts, for types that don't set their
        // own `max_size`: bytes, or a string such as "500M" or "2G"
        'max_size' => '2G',
    ],

    /*
    |--------------------------------------------------------------------------
    | Types
    |--------------------------------------------------------------------------
    |
    | A type is detected from the mime sniffed from the content: the first
    | type with a matching pattern wins, and a file that matches none is
    | rejected. Each type runs the pipeline of the same name by default, and
    | can set its own `max_size`. Set a type to false to remove it.
    |
    | Your config is merged with these defaults: a published config only
    | needs its overrides. Lists, such as `mimes`, replace the default list.
    |
    */
    'types' => [
        'video' => [
            'mimes' => ['video/*'],
            'max_size' => '10G',
        ],
        'audio' => [
            'mimes' => ['audio/*'],
            'max_size' => '1G',
        ],
        'image' => [
            'mimes' => ['image/*'],
            'max_size' => '50M',
            // Image renditions come from laravel-image, on the fly
            'pipeline' => null,
        ],
        'document' => [
            'mimes' => ['application/pdf'],
            'max_size' => '200M',
        ],
        'font' => [
            'mimes' => ['font/*', 'application/font-*', 'application/x-font-*', 'application/vnd.ms-opentype'],
            'max_size' => '20M',
        ],
        'captions' => [
            'mimes' => ['text/vtt', 'application/x-subrip', 'text/srt'],
            'max_size' => '10M',
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
    | A pipeline's `renditions` replace the default renditions as a whole.
    | Set a pipeline to false to remove it.
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
