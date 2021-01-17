<?php

namespace Folklore\Mediatheque;

use Illuminate\Support\Collection;
use Illuminate\Contracts\Container\Container;
use Folklore\Mediatheque\Contracts\Type\Factory as TypeFactoryContract;
use Folklore\Mediatheque\Contracts\Type\Type as TypeContract;
use Folklore\Mediatheque\Contracts\Pipeline\Factory as PipelineFactoryContract;
use Folklore\Mediatheque\Contracts\Pipeline\Pipeline as PipelineContract;

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

    public function types(): Collection
    {
        return $this->typeFactory->types();
    }

    public function type($name): TypeContract
    {
        return $this->typeFactory->type($name);
    }

    public function hasType($name): bool
    {
        return $this->typeFactory->hasType($name);
    }

    public function typeFromPath($path): ?TypeContract
    {
        return $this->typeFactory->typeFromPath($path);
    }

    public function pipeline($name): PipelineContract
    {
        return $this->pipelineFactory->pipeline($name);
    }

    public function hasPipeline($name): bool
    {
        return $this->pipelineFactory->hasPipeline($name);
    }
}
