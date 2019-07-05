<?php

namespace Folklore\Mediatheque;

use Illuminate\Contracts\Container\Container;
use Folklore\Mediatheque\Contracts\Type\Factory as TypeFactoryContract;
use Folklore\Mediatheque\Contracts\Pipeline\Factory as PipelineFactoryContract;

class Mediatheque
{
    protected $container;
    protected $typeFactory;
    protected $pipelineFactory;

    public function __construct(
        Container $container,
        TypeFactoryContract $typeFactory,
        PipelineFactoryContract $pipelineFactory
    ) {
        $this->container = $container;
        $this->typeFactory = $typeFactory;
        $this->pipelineFactory = $pipelineFactory;
    }

    public function types()
    {
        return $this->typeFactory->types();
    }

    public function type($name)
    {
        return $this->typeFactory->type($name);
    }

    public function hasType($name)
    {
        return $this->typeFactory->hasType($name);
    }

    public function typeFromPath($path)
    {
        return $this->typeFactory->typeFromPath($path);
    }

    public function pipeline($name)
    {
        return $this->pipelineFactory->pipeline($name);
    }

    public function hasPipeline($name)
    {
        return $this->pipelineFactory->hasPipeline($name);
    }
}
