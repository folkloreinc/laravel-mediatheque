<?php

return [

    'pipelines' => [

        'video' => [
            'queue' => true,
            'files' => [
                'mp4' => [
                    'job' => \Folklore\Mediatheque\Jobs\ConvertH264::class,
                    'resize' => [-1, 720],
                    'use_file' => null,
                ],
                'webm' => [
                    'job' => \Folklore\Mediatheque\Jobs\ConvertWebm::class,
                    'resize' => [-1, 720],
                    'use_file' => null,
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
            'files' => [
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
            'files' => [
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
            'files' => [
                'webfonts' => \Folklore\Mediatheque\Jobs\CreateWebfonts::class,
            ]
        ]

    ]
];
