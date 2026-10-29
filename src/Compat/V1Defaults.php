<?php

namespace Folklore\Mediatheque\Compat;

/**
 * The default config of 1.x, as of `v1.1.3`. A 1.x `config/mediatheque.php`
 * was usually published whole: the values it shares with these defaults are
 * not the application's choices, and the 2.0 defaults replace them
 * (LegacyConfig).
 *
 * The 1.x classes don't exist any more: they are named here as strings.
 */
final class V1Defaults
{
    private const string JOBS = 'Folklore\\Mediatheque\\Jobs\\';

    private const string METADATA = 'Folklore\\Mediatheque\\Metadata\\';

    /**
     * @return array<string, mixed>
     */
    public static function config(): array
    {
        return [
            'table_prefix' => 'mediatheque_',
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
                    'visibility' => 'public',
                    'cache' => false,
                ],
            ],
            'file_path_format' => '{type}/{date(Y-m-d)}/{id}-{date(his)}.{extension}',
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
                'video' => [
                    'type' => 'Folklore\\Mediatheque\\Types\\Video',
                    'pipeline' => 'video',
                    'can_upload' => true,
                    'animated_image' => false,
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
            'metadatas' => [
                'duration' => self::METADATA.'Duration',
                'waveform' => self::METADATA.'Waveform',
                'dimension' => self::METADATA.'Dimension',
                'pages_count' => self::METADATA.'PagesCount',
                'audio_tracks_count' => self::METADATA.'AudioTracksCount',
                'font_family_name' => self::METADATA.'FontFamilyName',
                'colors' => self::METADATA.'Colors',
            ],
            'pipelines' => [
                'video' => [
                    'queue' => true,
                    'jobs' => array_filter([
                        'h264' => self::JOBS.'Video\\H264',
                        'webm' => self::JOBS.'Video\\WebM',
                        'hevc' => self::JOBS.'Video\\HEVC',
                        'thumbnails' => [
                            'job' => self::JOBS.'Video\\Thumbnails',
                            'count' => 5,
                            'in_middle' => true,
                        ],
                        'media_convert' => env('AWS_MEDIACONVERT_ROLE') ? [
                            'job' => self::JOBS.'Video\\MediaConvert',
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
                            'job' => self::JOBS.'Audio\\Thumbnails',
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
                            'job' => self::JOBS.'Document\\Thumbnails',
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
                        'webfonts' => self::JOBS.'Font\\WebFonts',
                    ],
                ],
            ],
            'uploads' => [
                'max_size' => null,
                'allow_replace' => false,
                'sanitize_svg' => true,
                'blocked_extensions' => null,
            ],
            'routes' => [
                'map' => base_path('routes/mediatheque.php'),
                'prefix' => 'mediatheque',
                'middleware' => [],
            ],
            'debug' => env('MEDIATHEQUE_DEBUG', false),
            'process_timeout' => 600,
            'jobs' => [
                'timeout' => env('MEDIATHEQUE_JOB_TIMEOUT'),
                'tries' => env('MEDIATHEQUE_JOB_TRIES'),
            ],
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
                    'endpoint' => env('AWS_MEDIACONVERT_ENDPOINT', null),
                    'role' => env('AWS_MEDIACONVERT_ROLE', null),
                    'queue' => env('AWS_MEDIACONVERT_QUEUE', null),
                ],
            ],
        ];
    }
}
