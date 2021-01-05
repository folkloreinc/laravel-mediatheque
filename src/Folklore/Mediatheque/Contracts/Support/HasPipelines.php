<?php
namespace Folklore\Mediatheque\Contracts\Support;

interface HasPipelines extends HasFiles
{
    public function pipelines();

    public function runPipeline($pipeline);
}
