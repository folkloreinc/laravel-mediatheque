<?php

namespace Folklore\Mediatheque;

use Illuminate\Contracts\Container\Container;
use Folklore\Mediatheque\Contracts\Pipeline as PipelineContract;
use Folklore\Mediatheque\Contracts\Type as TypeContract;

class Mediatheque
{
    protected $container;
    protected $types = [];
    protected $pipelines = [];

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function pipeline($name)
    {
        $pipeline = array_get($this->pipelines, $name, null);
        if (is_null($pipeline) && !$this->container->bound($name)) {
            throw new Exception('Pipeline "'.$name.'" doesn\'t exists');
        }

        if (is_string($pipeline)) {
            $pipeline = $this->container->make($pipeline);
        } elseif (is_array($pipeline)) {
            $options = array_except($pipeline, ['jobs']);
            $jobs = array_get($pipeline, 'jobs', []);
            $pipeline = $this->container->make(PipelineContract::class);
            $pipeline->setOptions($options);
            $pipeline->setJobs($jobs);
        }

        $pipeline->setName($name);

        return $pipeline;
    }

    public function addPipeline($name, $pipeline)
    {
        $this->pipelines[$name] = $pipeline;
        return $this;
    }

    public function setPipelines($pipelines)
    {
        $this->pipelines = $pipelines;
        return $this;
    }

    public function type($name)
    {
        $type = array_get($this->types, $name, null);
        if (is_null($type) && !$this->container->bound($name)) {
            throw new Exception('Type "'.$name.'" doesn\'t exists');
        }

        if (is_string($type)) {
            $type = $this->container->make($type);
        } elseif (is_array($type)) {
            $definition = $type;
            $type = $this->container->make(TypeContract::class);
            $type->setDefinition($definition);
        }

        $type->setName($name);

        return $type;
    }

    public function types()
    {
        $types = [];
        foreach ($this->getTypes() as $name => $type) {
            $types[] = $this->type($name);
        }
        return $types;
    }

    public function addType($name, $type)
    {
        $this->types[$name] = $type;
        return $this;
    }

    public function setTypes($types)
    {
        $this->types = $types;
        return $this;
    }

    public function getTypes()
    {
        return $this->types;
    }

    public function __call($name, $arguments)
    {
        if (isset($this->types[$name])) {
            return new TypeManager($this->container, $this->type($name));
        }
    }
}
