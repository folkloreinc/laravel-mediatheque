<?php

return [

    'pipelines' => [

        'video' => [
            'queue' => true,
            'jobs' => [
                'h264' => [
                    'job' => \Folklore\Mediatheque\Jobs\Video\H264::class,
                ],
                'webm' => [
                    'job' => \Folklore\Mediatheque\Jobs\Video\WebM::class,
                ],
                'thumbnails' => [
                    'job' => \Folklore\Mediatheque\Jobs\CreateThumbnails::class,
                    'count' => 5,
                    'in_middle' => true,
                ],
            ]
        ],

        'audio' => [
            'queue' => true,
            'jobs' => [
                'thumbnails' => [
                    'job' => \Folklore\Mediatheque\Jobs\CreateThumbnails::class,
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
                    'generator' => \Folklore\Mediatheque\Jobs\CreateThumbnails::class,
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
                'webfonts' => \Folklore\Mediatheque\Jobs\CreateWebfonts::class,
            ]
        ]

    ]
];
