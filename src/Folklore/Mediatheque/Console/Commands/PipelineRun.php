<?php

namespace Folklore\Mediatheque\Console\Commands;

use Illuminate\Console\Command;

class PipelineRun extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mediatheque:pipeline:run {type} {name} {--id=*}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run a pipeline';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $type = $this->argument('type');
        $name = $this->argument('name');
        $ids = $this->option('id');
        if (is_null($ids) || !sizeof($ids)) {
            $this->line('<error>You must provide either --id= or </error>');
        }

        $items = mediatheque()
            ->type($type)
            ->model()
            ->newQuery()
            ->whereIn('id', $ids)
            ->get();

        foreach ($items as $item) {
            $this->line('<comment>Running</comment> Pipeline '.$name.' on model #'.$item->id);
            $item->runPipeline($name);
        }
    }
}
