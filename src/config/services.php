<?php

return [
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
];
