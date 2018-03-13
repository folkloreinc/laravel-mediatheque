<?php

namespace Folklore\Mediatheque\Support;

use Ramsey\Uuid\Uuid;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Folklore\Mediatheque\Contracts\Pipeline as PipelineContract;
use Folklore\Mediatheque\Support\Interfaces\HasFiles as HasFilesContract;

class Pipeline implements PipelineContract, Arrayable, Jsonable
{
    protected $defaultOptions = [
        'autostart' => true,
        'unique' => false,
        'queue' => true,
        'from_file' => 'original',
        'namespace' => null,
    ];

    protected $name;

    protected $options;

    protected $jobs;

    public function __construct($options = [])
    {
        $this->setOptions($options);
    }

    public static function fromJobs($jobs, $options = [])
    {
        $pipeline = new static($options);
        $pipeline->setJobs($jobs);
        return $pipeline;
    }

    protected function options()
    {
        return [];
    }

    protected function jobs()
    {
        return [];
    }

    protected function name()
    {
        return Uuid::uuid1();
    }

    public function setName($name)
    {
        $this->name = $name;
        return $this;
    }

    public function getName()
    {
        if (isset($this->name)) {
            return $this->name;
        }
        return $this->name();
    }

    public function setOptions($options)
    {
        $this->options = array_merge($this->defaultOptions, $options);
        return $this;
    }

    public function getOptions()
    {
        if (isset($this->options)) {
            return $this->options;
        }
        return array_merge($this->defaultOptions, $this->options());
    }

    public function addJob($name, $job)
    {
        if (!isset($this->jobs)) {
            $this->jobs = [];
        }
        $this->jobs[$name] = $job;
        return $this;
    }

    public function setJobs($jobs)
    {
        $this->jobs = $jobs;
        return $this;
    }

    public function getJobs()
    {
        if (isset($this->jobs)) {
            return $this->jobs;
        }
        return $this->jobs();
    }

    public function get($key, $value = null)
    {
        $options = $this->getOptions();
        return array_get($options, $key, $value);
    }

    public function toArray()
    {
        $options = $this->getOptions();
        return array_merge($options, [
            'name' => $this->getName(),
            'jobs' => $this->getJobs(),
        ]);
    }

    public function toJson($options = 0)
    {
        return json_encode($this->toArray(), $options);
    }

    public function __get($key)
    {
        return $this->get($key);
    }

    public function __sleep()
    {
        return ['name', 'options', 'jobs'];
    }
}
