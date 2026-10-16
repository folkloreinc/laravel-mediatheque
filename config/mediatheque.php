<?php

use Folklore\Mediatheque\Models\File;
use Folklore\Mediatheque\Models\Media;
use Folklore\Mediatheque\Models\PipelineJob;
use Folklore\Mediatheque\Models\PipelineRun;

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
    | Models
    |--------------------------------------------------------------------------
    |
    | The Eloquent models the package uses. Extend them to add your own
    | columns, scopes and relations, and set your classes here. Package code
    | only depends on the contracts they implement.
    |
    */
    'models' => [
        'media' => Media::class,
        'file' => File::class,
        'pipeline_run' => PipelineRun::class,
        'pipeline_job' => PipelineJob::class,
    ],

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
    | Paths
    |--------------------------------------------------------------------------
    |
    | `file_path_format` builds the path of originals, and names the files of
    | the ffmpeg driver. `run_path_format` builds the prefix of each pipeline
    | run: every transcoder writes the files of a run under it, so a run never
    | overwrites the files of another. Paths are recorded on the files.
    |
    | Placeholders: {media.<attribute>} (any attribute of your media model,
    | such as {media.uid} or {media.tenant_id}), {type}, {handle}, {index}
    | ("-2" in a group, empty otherwise), {extension}, {run} and {date(Y/m)}.
    |
    */
    'file_path_format' => '{type}/{media.uid}/{handle}{index}.{extension}',

    'run_path_format' => '{type}/{media.uid}/{run}',

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
    | Binaries
    |--------------------------------------------------------------------------
    |
    | The tools the local prober and the ffmpeg transcoder run: a name found
    | in the PATH, or an absolute path.
    |
    */
    'binaries' => [
        'ffmpeg' => env('MEDIATHEQUE_FFMPEG', 'ffmpeg'),
        'ffprobe' => env('MEDIATHEQUE_FFPROBE', 'ffprobe'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Probe
    |--------------------------------------------------------------------------
    |
    | The probe reads the technical metadata of each original, in a queued
    | job, before its pipeline starts. `timeout` is in seconds.
    |
    */
    'probe' => [
        'timeout' => 120,
    ],

    /*
    |--------------------------------------------------------------------------
    | Jobs
    |--------------------------------------------------------------------------
    |
    | The queue of the package's jobs: the probe, and the renditions of the
    | ffmpeg transcoder. `timeout` is in seconds: keep your queue's
    | `retry_after` longer than it.
    |
    */
    'jobs' => [
        'connection' => env('MEDIATHEQUE_QUEUE_CONNECTION'),
        'queue' => env('MEDIATHEQUE_QUEUE'),
        'tries' => 3,
        'timeout' => 3600,
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
