<?php

namespace Folklore\Mediatheque\Support;

use Folklore\Mediatheque\Contracts\Models\File as FileContract;
use Folklore\Mediatheque\Contracts\Support\HasFiles as HasFilesContract;
use Exception;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class ShellJob extends PipelineJob
{
    protected $defaultShellOptions = [
        'bin' => null,
        'arguments' => []
    ];

    public function __construct(
        FileContract $file,
        $options = [],
        HasFilesContract $model = null
    ) {
        $this->options = array_merge(
            $this->defaultShellOptions,
            $this->defaultOptions,
            $options
        );
        $this->file = $file;
        $this->model = $model;
    }

    protected function bin()
    {
        return $this->options['bin'];
    }

    protected function arguments()
    {
        return $this->options['arguments'];
    }

    protected function makeProcess()
    {
        $arguments = array_merge([$this->bin()], $this->arguments());
        return new Process($arguments);
    }

    protected function runProcess($process)
    {
        $process->run();
    }

    protected function throwProcessException($process)
    {
        throw new ProcessFailedException($process);
    }

    protected function getOutputFromProcess($process)
    {
        return $process->getOutput();
    }

    public function handle()
    {
        $process = $this->makeProcess();
        $this->runProcess($process);
        if (!$process->isSuccessful()) {
            $this->throwProcessException($process);
        }
        return $this->getOutputFromProcess($process);
    }
}
