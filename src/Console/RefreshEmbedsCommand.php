<?php

namespace Folklore\Mediatheque\Console;

use Folklore\Mediatheque\Enums\MediaStatus;
use Folklore\Mediatheque\Jobs\ProbeMedia;
use Folklore\Mediatheque\Pipelines\PipelineDefinition;
use Folklore\Mediatheque\Support\Models;
use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher as Jobs;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Probes ready embeds again, in the queue (docs/decisions/0031): their
 * title, privacy and thumbnail follow their provider, an embed gone from its
 * provider gets `unavailable_at`, and a changed thumbnail gives a new
 * poster.
 *
 * Only embeds not probed in the last `--days` days are refreshed, and at
 * most `--limit` of them, the least recently probed first, so a schedule
 * spreads the requests to the providers and their quotas. The application
 * schedules it.
 */
#[AsCommand(name: 'mediatheque:refresh-embeds')]
class RefreshEmbedsCommand extends Command
{
    protected $signature = 'mediatheque:refresh-embeds
                            {--days=7 : Refresh the embeds not probed for this many days}
                            {--limit=100 : Refresh at most this many embeds}
                            {--media=* : Refresh these media, by uid, whenever they were probed}';

    protected $description = 'Probe embeds again, so that their metadata and poster follow their provider';

    public function handle(Jobs $jobs): int
    {
        $uids = array_values(array_filter((array) $this->option('media'), 'is_string'));
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($days === false || $limit === false) {
            $this->components->error('--days must be 0 or more, and --limit 1 or more.');

            return self::INVALID;
        }

        $media = $this->embeds($uids, $days)->limit($uids !== [] ? count($uids) : $limit)->get();
        foreach ($media as $model) {
            $key = $model->getKey();
            if (is_int($key) || is_string($key)) {
                $jobs->dispatch(new ProbeMedia($key, refresh: true));
            }
        }

        $this->components->twoColumnDetail('Embeds queued for a refresh', (string) $media->count());

        return self::SUCCESS;
    }

    /**
     * Ready embeds, the least recently probed first.
     *
     * @param  list<string>  $uids
     * @return Builder<Model>
     */
    private function embeds(array $uids, int $days): Builder
    {
        $model = Models::media()::query()->getModel();
        $runs = Models::pipelineRun()::query()->getModel();
        $lastProbe = $runs->newQuery()
            ->selectRaw('max(created_at)')
            ->where('name', PipelineDefinition::PROBE)
            ->where('pipelinable_type', $model->getMorphClass())
            ->whereColumn('pipelinable_id', $model->qualifyColumn($model->getKeyName()));

        /** @var Builder<Model> $query */
        $query = Models::media()::query()
            ->withoutGlobalScopes()
            ->where('status', MediaStatus::Ready)
            ->whereHas('mediaFiles', fn (Builder $files) => $files->where('role', 'original')->whereNotNull('provider'));

        if ($uids !== []) {
            return $query->whereIn('uid', $uids)->orderBy($model->getKeyName());
        }

        return $query
            ->whereDoesntHave('pipelineRuns', fn (Builder $runs) => $runs
                ->where('name', PipelineDefinition::PROBE)
                ->where('created_at', '>=', Date::now()->subDays($days)))
            ->orderBy($lastProbe->toBase())
            ->orderBy($model->getKeyName());
    }
}
