<?php

namespace Folklore\Mediatheque\Support;

use Folklore\Mediatheque\Contracts\Models\File;
use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Contracts\Models\PipelineJob;
use Folklore\Mediatheque\Contracts\Models\PipelineRun;
use Illuminate\Database\Eloquent\Model;

/**
 * The model classes set in `config('mediatheque.models')`, and the tables of
 * the package. Package code creates and queries models through these, never
 * through the package's own classes.
 */
final class Models
{
    /**
     * @return class-string<Model&Media>
     */
    public static function media(): string
    {
        return self::config()->model('media', Media::class);
    }

    /**
     * @return class-string<Model&File>
     */
    public static function file(): string
    {
        return self::config()->model('file', File::class);
    }

    /**
     * @return class-string<Model&PipelineRun>
     */
    public static function pipelineRun(): string
    {
        return self::config()->model('pipeline_run', PipelineRun::class);
    }

    /**
     * @return class-string<Model&PipelineJob>
     */
    public static function pipelineJob(): string
    {
        return self::config()->model('pipeline_job', PipelineJob::class);
    }

    /**
     * A table of the package, with the configured prefix.
     */
    public static function table(string $name): string
    {
        return self::config()->tablePrefix().$name;
    }

    private static function config(): MediathequeConfig
    {
        return app(MediathequeConfig::class);
    }
}
