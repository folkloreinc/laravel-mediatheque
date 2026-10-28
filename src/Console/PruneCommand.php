<?php

namespace Folklore\Mediatheque\Console;

use Folklore\Mediatheque\Enums\PipelineStatus;
use Folklore\Mediatheque\Support\MediathequeConfig;
use Folklore\Mediatheque\Support\Models;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Deletes what the package keeps only for a while (docs/architecture.md,
 * docs/decisions/0017), in batches, so a large table isn't locked:
 *
 * - media soft-deleted more than `prune.after_days` ago are force-deleted,
 *   and their stored files deleted in a queued job. A media in use is never
 *   soft-deleted, so none of them is in use;
 * - runs that ended more than `prune.runs_after_days` ago are deleted with
 *   their renditions, except each media's last run of each pipeline, so a
 *   media's history always shows why it is in its current state.
 *
 * The application schedules it. Running it again deletes nothing more.
 */
#[AsCommand(name: 'mediatheque:prune')]
class PruneCommand extends Command
{
    private const int BATCH = 200;

    protected $signature = 'mediatheque:prune
                            {--dry-run : List what would be deleted, and delete nothing}';

    protected $description = 'Force-delete media deleted long ago, and delete old pipeline runs';

    public function handle(MediathequeConfig $config): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $mediaDays = $config->pruneAfterDays();
        $runDays = $config->pruneRunsAfterDays();

        $media = $mediaDays !== null ? $this->pruneMedia($mediaDays, $dryRun) : null;
        $runs = $runDays !== null ? $this->pruneRuns($runDays, $dryRun) : null;

        $verb = $dryRun ? 'Would delete' : 'Deleted';
        $this->components->twoColumnDetail(
            $verb.' media deleted more than '.($mediaDays ?? '—').' days ago',
            $media === null ? 'off' : (string) $media,
        );
        $this->components->twoColumnDetail(
            $verb.' runs ended more than '.($runDays ?? '—').' days ago',
            $runs === null ? 'off' : (string) $runs,
        );

        return self::SUCCESS;
    }

    private function pruneMedia(int $days, bool $dryRun): int
    {
        $query = Models::media()::query()
            ->withoutGlobalScopes()
            ->whereNotNull('deleted_at')
            ->where('deleted_at', '<', Date::now()->subDays($days));

        if ($dryRun) {
            $count = $query->count();
            $uids = array_values(array_filter($query->clone()->orderBy('id')->limit(50)->pluck('uid')->all(), 'is_string'));
            if ($uids !== []) {
                $this->components->bulletList($count > count($uids) ? [...$uids, '…'] : $uids);
            }

            return $count;
        }

        $deleted = 0;
        $query->chunkById(self::BATCH, function (Collection $media) use (&$deleted) {
            foreach ($media as $model) {
                $model->forceDelete();
                $deleted++;
            }
        });

        return $deleted;
    }

    private function pruneRuns(int $days, bool $dryRun): int
    {
        $model = Models::pipelineRun()::query()->getModel();
        $query = $model->newQueryWithoutScopes()
            ->whereIn('status', array_map(fn (PipelineStatus $status) => $status->value, array_filter(PipelineStatus::cases(), fn (PipelineStatus $status) => $status->isFinal())))
            ->where('ended_at', '<', Date::now()->subDays($days));

        $deleted = 0;
        $query->chunkById(self::BATCH, function (Collection $runs) use ($model, $dryRun, &$deleted) {
            $old = array_diff($runs->modelKeys(), $this->lastRuns($model, $runs->pluck('pipelinable_id')->unique()->values()->all()));
            $deleted += count($old);

            if (! $dryRun && $old !== []) {
                // Files keep their rendition when it is pruned, without its job
                Models::file()::query()
                    ->whereIn('pipeline_job_id', Models::pipelineJob()::query()->select('id')->whereIn('pipeline_id', $old))
                    ->update(['pipeline_job_id' => null]);
                Models::pipelineJob()::query()->whereIn('pipeline_id', $old)->delete();
                $model->newQueryWithoutScopes()->whereKey($old)->delete();
            }
        });

        return $deleted;
    }

    /**
     * The last run of each pipeline of these media.
     *
     * @param  array<int, mixed>  $media  The media's keys
     * @return array<int, int|string>
     */
    private function lastRuns(Model $model, array $media): array
    {
        return $model->newQueryWithoutScopes()
            ->toBase()
            ->selectRaw('max(id) as last')
            ->whereIn('pipelinable_id', $media)
            ->groupBy('pipelinable_type', 'pipelinable_id', 'name')
            ->pluck('last')
            ->filter(fn (mixed $key) => is_int($key) || is_string($key))
            ->values()
            ->all();
    }
}
