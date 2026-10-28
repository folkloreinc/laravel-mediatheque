<?php

namespace Folklore\Mediatheque\Jobs;

use Folklore\Mediatheque\Support\MediathequeConfig;
use Folklore\Mediatheque\Support\Models;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Deletes files from a disk once nothing records them: the files a run
 * replaced, those of a replaced original, and those of a force-deleted
 * media. A path that a file records
 * again by the time the job runs is kept, and deleting a file that is
 * already gone changes nothing.
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
        $recorded = Models::file()::query()
            ->where('source', $this->disk)
            ->whereIn('path', $this->paths)
            ->pluck('path')
            ->filter(fn (mixed $path) => is_string($path))
            ->all();

        $paths = array_values(array_diff($this->paths, $recorded));

        if ($paths !== []) {
            $filesystems->disk($this->disk)->delete($paths);
        }
    }
}
