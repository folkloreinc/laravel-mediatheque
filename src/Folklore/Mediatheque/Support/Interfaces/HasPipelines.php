<?php namespace Folklore\Mediatheque\Support\Interfaces;

interface HasPipelines extends HasFiles
{
    public function pipelines();

    public function runPipeline($pipeline);
}
