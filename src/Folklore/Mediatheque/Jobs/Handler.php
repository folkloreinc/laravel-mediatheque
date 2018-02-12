<?php

namespace Folklore\Mediatheque\Jobs;

class Handler
{
    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle($command)
    {
        $command->handle();
    }
}
