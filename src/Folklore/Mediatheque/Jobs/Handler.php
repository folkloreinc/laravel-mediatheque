<?php

namespace Folklore\Mediatheque\Jobs;

use Illuminate\Contracts\Container\Container;

class Handler
{
    protected $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle($command)
    {
        $this->container->call([$command, 'handle']);
    }
}
