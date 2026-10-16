<?php

namespace Folklore\Mediatheque\Jobs;

use Folklore\Mediatheque\Support\MediathequeConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Deletes files from a disk once nothing records them: the files a run
 * replaced. Deleting a file that is already gone changes nothing.
 */
class DeleteStoredFiles implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries;

    /**
     * @param  list<string>  $paths
     */
    public function __construct(
        public readonly string $disk,
        public readonly array $paths,
    ) {
        $config = app(MediathequeConfig::class);
        $this->tries = $config->jobsTries();
        $this->onConnection($config->jobsConnection());
        $this->onQueue($config->jobsQueue());
    }

    public function handle(Filesystems $filesystems): void
    {
        $filesystems->disk($this->disk)->delete($this->paths);
    }
}
