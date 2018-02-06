<?php

return [
    
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

    'file_path_format' => '{type}/{date(Y-m-d)}/{id}-{date(his)}.{extension}',
];
