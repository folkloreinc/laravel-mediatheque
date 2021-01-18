<?php

namespace Folklore\Mediatheque\Support;

use Ramsey\Uuid\Uuid;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Folklore\Mediatheque\Contracts\Pipeline\Pipeline as PipelineContract;
use Folklore\Mediatheque\Contracts\Support\HasFiles as HasFilesContract;

class Pipeline extends Definition implements PipelineContract
{
    protected $name;

    protected $autoStart = true;

    protected $unique = false;

    protected $shouldQueue = true;

    protected $fromFile = 'original';

    protected $jobs;

    public function __construct($name, $definition = [])
    {
        $this->name = $name;

        if (!is_null($definition)) {
            $this->setDefinition($definition);
        }
    }

    public static function fromJobs($jobs, $definition = [])
    {
        return new static(
            data_get($definition, 'name', Uuid::uuid1()),
            array_merge(Arr::except($definition, ['name']), ['jobs' => $jobs])
        );
    }

    public function name(): string
    {
        return $this->get('name');
    }

    public function autoStart(): bool
    {
        return $this->get('autoStart');
    }

    public function unique(): bool
    {
        return $this->get('unique');
    }

    public function shouldQueue(): bool
    {
        return $this->get('shouldQueue');
    }

    public function fromFile(): ?string
    {
        return $this->get('fromFile');
    }

    public function jobs(): Collection
    {
        return collect($this->get('jobs'));
    }

    public function toArray()
    {
        return [
            'name' => $this->name(),
            'auto_start' => $this->autoStart(),
            'unique' => $this->unique(),
            'should_queue' => $this->shouldQueue(),
            'from_file' => $this->fromFile(),
            'jobs' => $this->jobs()->toArray(),
        ];
    }

    public function __sleep()
    {
        return ['name', 'autoStart', 'unique', 'shouldQueue', 'fromFile', 'jobs'];
    }
}
