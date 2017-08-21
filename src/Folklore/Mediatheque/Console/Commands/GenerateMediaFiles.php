<?php

namespace Folklore\Mediatheque\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Folklore\Mediatheque\Contracts\Models\Audio as AudioContract;
use Folklore\Mediatheque\Contracts\Models\Document as DocumentContract;
use Folklore\Mediatheque\Contracts\Models\Font as FontContract;
use Folklore\Mediatheque\Contracts\Models\Picture as PictureContract;
use Folklore\Mediatheque\Contracts\Models\Video as VideoContract;
use Folklore\Mediatheque\Jobs\CreateFiles;

class GenerateMediaFiles extends Command
{
    use DispatchesJobs;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mediatheque:generate_media_files
                            {--o|organisationId= : The organisation ID, or all organisations if not specified}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate media files with filesCreators';

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
        $organisationId = $this->option('organisationId'); // @TODO

        $contracts = [
            AudioContract::class,
            DocumentContract::class,
            FontContract::class,
            PictureContract::class,
            VideoContract::class
        ];
        foreach ($contracts as $contract) {
            $all = app($contract)->all();
            foreach ($all as $item) {
                $job = new CreateFiles($item, true);
                $job->onQueue('generate_media_files');
                $this->dispatch($job);
            }
        }
    }
}
