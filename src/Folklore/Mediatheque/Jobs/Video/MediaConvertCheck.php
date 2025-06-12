<?php

namespace Folklore\Mediatheque\Jobs\Video;

use Folklore\Mediatheque\Contracts\Models\File as FileContract;
use Folklore\Mediatheque\Contracts\Support\HasFiles as HasFilesContract;
use Folklore\Mediatheque\Support\PipelineJob;
use Folklore\Mediatheque\Contracts\Services\MediaConvertClient;

class MediaConvertCheck extends PipelineJob
{
    public $config = [];

    protected $client;

    public function __construct(FileContract $file, $options = [], ?HasFilesContract $model = null)
    {
        $this->options = array_merge($this->config, $this->defaultOptions, $options);
        $this->file = $file;
        $this->model = $model;
        $this->client = app(MediaConvertClient::class);
    }

    public function handle()
    {
        $files = [];
        return $files;
    }

    public function formatS3DestinationPath($bucket, $path)
    {
        return 's3://' . $bucket . '/' . $this->formatS3Destination($path);
    }

    public function formatS3Destination($path)
    {
        return rtrim(ltrim($path, '/'), '/') . '/converted/';
    }
}
