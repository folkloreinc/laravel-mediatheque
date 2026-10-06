<?php

namespace Folklore\Mediatheque\Tests\Support\Jobs;

use Folklore\Mediatheque\Support\PipelineJob;

class NoOutputJob extends PipelineJob
{
    public function handle()
    {
        return null;
    }
}
