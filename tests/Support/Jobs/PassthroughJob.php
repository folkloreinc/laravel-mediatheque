<?php

namespace Folklore\Mediatheque\Tests\Support\Jobs;

use Folklore\Mediatheque\Support\PipelineJob;

class PassthroughJob extends PipelineJob
{
    public function handle()
    {
        return $this->makeFileFromPath($this->getLocalFilePath($this->file));
    }
}
