<?php

namespace Folklore\Mediatheque\Support;

use Ramsey\Uuid\Uuid;
use Folklore\Mediatheque\Contracts\Pipeline as PipelineContract;
use Folklore\Mediatheque\Support\Interfaces\HasFiles as HasFilesContract;

class Pipeline extends Definition implements PipelineContract
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
        $jobs = array_get($options, 'jobs', []);
        $options = array_except($options, ['jobs']);
        $this->setOptions($options);
        $this->setOptions($jobs);
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
        return $this->set('name', $name);
    }

    public function getName()
    {
        return $this->get('name');
    }

    public function setOptions($options)
    {
        return $this->set('options', array_merge($this->defaultOptions, $options));
    }

    public function getOptions()
    {
        if (isset($this->options)) {
            return $this->options;
        }
        return array_merge($this->defaultOptions, $this->options());
    }

    public function setJobs($jobs)
    {
        return $this->set('jobs', $jobs);
    }

    public function getJobs()
    {
        return $this->get('jobs');
    }

    public function addJob($name, $job)
    {
        if (!isset($this->jobs)) {
            $this->jobs = [];
        }
        $this->jobs[$name] = $job;
        return $this;
    }

    public function toArray()
    {
        $options = $this->getOptions();
        return array_merge($options, [
            'name' => $this->getName(),
            'jobs' => $this->getJobs(),
        ]);
    }

    public function __get($name)
    {
        $options = $this->getOptions();
        return array_get($options, $name);
    }

    public function __sleep()
    {
        return ['name', 'options', 'jobs'];
    }
}
