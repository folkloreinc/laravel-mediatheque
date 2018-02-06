<?php

namespace Folklore\Mediatheque\Models;

use Folklore\Mediatheque\Contracts\Models\PipelineJob as PipelineJobContract;

class PipelineJob extends Model implements PipelineJobContract
{
    protected $table = 'pipelines_jobs';
}
