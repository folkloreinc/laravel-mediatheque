<?php

namespace Folklore\Mediatheque\Jobs;

use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Support\MediathequeConfig;
use Folklore\Mediatheque\Support\Models;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;
use Throwable;

/**
 * Applies a media's visibility to its stored files, the original and the
 * renditions (docs/decisions/0007). The visibility is read when the job
 * runs, so a later change wins, and running it again changes nothing.
 *
 * A file its disk doesn't have any more is skipped. A file whose
 * visibility can't be set fails the job, which is retried: a media made
 * private never keeps a public file silently.
 */
class ApplyVisibility implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries;

    public function __construct(
        /** The media's uid. */
        public readonly string $media,
    ) {
        $config = app(MediathequeConfig::class);
        $this->tries = $config->jobsTries();
        $this->onConnection($config->jobsConnection());
        $this->onQueue($config->jobsQueue());
        $this->afterCommit();
    }

    public function handle(Filesystems $filesystems): void
    {
        $media = Models::media()::query()
            ->withoutGlobalScopes()
            ->where('uid', $this->media)
            ->with('mediaFiles')
            ->first();

        if (! $media instanceof Media) {
            return;
        }

        $visibility = $media->visibility()->value;

        foreach ($media->files() as $file) {
            $source = $file->source();
            $path = $file->path();
            if ($source === null || $path === null) {
                continue;
            }

            $disk = $filesystems->disk($source);

            try {
                $applied = $disk->setVisibility($path, $visibility);
                $error = null;
            } catch (Throwable $exception) {
                $applied = false;
                $error = $exception;
            }

            if (! $applied && $disk->exists($path)) {
                throw new RuntimeException(sprintf('The file "%s" on the disk "%s" can\'t be made %s.', $path, $source, $visibility), previous: $error);
            }
        }
    }
}
