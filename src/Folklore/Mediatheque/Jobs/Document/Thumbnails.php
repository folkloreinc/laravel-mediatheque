<?php

namespace Folklore\Mediatheque\Jobs\Document;

use Folklore\Mediatheque\Support\ThumbnailsJob;
use Folklore\Mediatheque\Contracts\Services\PagesCount;
use Folklore\Mediatheque\Contracts\Support\HasMetadatas;

class Thumbnails extends ThumbnailsJob
{
    protected $type = 'document';

    protected $defaultOptions = [
        'count' => 'all',
        'resolution' => 150,
        'quality' => 100,
        'background' => 'white',
        'format' => 'jpeg',
        'font' => null,
    ];

    protected $pagesCount;

    protected function getOptions($index = 0)
    {
        return array_merge(
            [
                'page' => $index,
            ],
            $this->options
        );
    }

    protected function getCount()
    {
        $count = data_get($this->options, 'count', null);
        if ($count === 'all') {
            return $this->getPagesCount();
        }
        return $count;
    }

    protected function getPagesCount()
    {
        if (!isset($this->pagesCount) && $this->file instanceof HasMetadatas) {
            $metadata = $this->file->getMetadata('pages_count');
            $this->pagesCount = isset($metadata) ? $metadata->getValue() : null;
        }
        if (!isset($this->pagesCount) && $this->model instanceof HasMetadatas) {
            $metadata = $this->model->getMetadata('pages_count');
            $this->pagesCount = isset($metadata) ? $metadata->getValue() : null;
        }
        if (!isset($this->pagesCount)) {
            $localPath = $this->getLocalFilePath($this->file);
            $this->pagesCount = resolve(PagesCount::class)->getPagesCount($localPath);
        }
        return $this->pagesCount;
    }
}
