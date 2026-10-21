<?php

namespace Folklore\Mediatheque\Monitoring;

use Carbon\CarbonImmutable;
use Closure;
use Folklore\Mediatheque\Contracts\Models\File as FileContract;
use Folklore\Mediatheque\Contracts\Models\Media as MediaContract;
use Folklore\Mediatheque\Contracts\Models\PipelineJob as PipelineJobContract;
use Folklore\Mediatheque\Contracts\Models\PipelineRun as PipelineRunContract;
use Folklore\Mediatheque\Enums\JobStatus;
use Folklore\Mediatheque\Enums\MediaStatus;
use Folklore\Mediatheque\Enums\PipelineStatus;
use Folklore\Mediatheque\Enums\TranscoderErrorCode;
use Folklore\Mediatheque\Support\MediathequeConfig;
use Folklore\Mediatheque\Support\Models;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\Date;

/**
 * The read model of monitoring (docs/decisions/0017): what the dashboard,
 * `mediatheque:status` and an application's own views read.
 *
 * Queries ignore the application's global scopes, as the package's workers
 * do: monitoring sees every media unless the application narrows it with
 * `Mediatheque::monitoringScope()`. That scope narrows media, and runs and
 * renditions through their media.
 */
class Monitoring
{
    /** @var (Closure(Builder<covariant Model>, string): mixed)|null */
    private ?Closure $scope = null;

    public function __construct(private readonly MediathequeConfig $config) {}

    /**
     * Narrow what monitoring reads. The closure receives a query of media,
     * with the kind `media`, without the application's global scopes.
     *
     * @param  (Closure(Builder<covariant Model>, string): mixed)|null  $scope
     */
    public function scopeUsing(?Closure $scope): void
    {
        $this->scope = $scope;
    }

    public function overview(Period $period): Overview
    {
        $media = [];
        foreach (MediaStatus::cases() as $status) {
            $media[$status->value] = $this->mediaQuery()->whereNull('deleted_at')->where('status', $status)->count();
        }

        $runsEnded = [];
        foreach ([PipelineStatus::Completed, PipelineStatus::Failed, PipelineStatus::Canceled] as $status) {
            $runsEnded[$status->value] = $this->runQuery()->where('status', $status)->whereBetween('ended_at', $period->bounds())->count();
        }

        $oldest = $this->jobQuery()->where('status', JobStatus::Queued)->whereNotNull('queued_at')->min('queued_at');

        return new Overview(
            period: $period,
            media: $media,
            runsInProgress: $this->runQuery()->whereIn('status', [PipelineStatus::Pending, PipelineStatus::Running])->count(),
            stuckRuns: $this->stuck($this->runQuery())->count(),
            runsEnded: $runsEnded,
            oldestQueuedAt: is_string($oldest) || $oldest instanceof \DateTimeInterface ? CarbonImmutable::parse($oldest) : null,
        );
    }

    /**
     * Runs, newest first, with their jobs and their media.
     *
     * @return Page<PipelineRunContract>
     */
    public function runs(RunFilters $filters = new RunFilters, ?string $cursor = null, int $limit = 50): Page
    {
        $query = $this->runQuery()->with(['pipelineJobs', 'pipelinable']);

        if ($filters->status !== null) {
            $query->where('status', $filters->status);
        }
        if ($filters->pipeline !== null) {
            $query->where('name', $filters->pipeline);
        }
        if ($filters->transcoder !== null) {
            $query->where('transcoder', $filters->transcoder);
        }
        if ($filters->errorCode !== null) {
            $query->where('error->code', $filters->errorCode->value);
        }
        if ($filters->period !== null) {
            $query->whereBetween('created_at', $filters->period->bounds());
        }
        if ($filters->stuck) {
            $this->stuck($query);
        }
        if ($filters->mediaType !== null || $filters->media !== null) {
            $query->whereHasMorph('pipelinable', [Models::media()], function (Builder $media) use ($filters) {
                $media->withoutGlobalScopes();
                if ($filters->mediaType !== null) {
                    $media->where('type', $filters->mediaType);
                }
                if ($filters->media !== null) {
                    $media->where('uid', $filters->media);
                }
            });
        }

        $page = $query->orderByDesc('id')->cursorPaginate($limit, ['*'], 'cursor', $cursor !== null ? Cursor::fromEncoded($cursor) : null);

        /** @var list<PipelineRunContract> $items */
        $items = array_values($page->items());

        return new Page($items, $page->nextCursor()?->encode());
    }

    /**
     * A run by its uid, with its jobs and its media, within the monitoring
     * scope.
     */
    public function run(string $uid): ?PipelineRunContract
    {
        $run = $this->runQuery()->with(['pipelineJobs', 'pipelinable'])->where('uid', $uid)->first();

        return $run instanceof PipelineRunContract ? $run : null;
    }

    /**
     * A media by its uid, deleted or not, within the monitoring scope.
     */
    public function media(string $uid): ?MediaContract
    {
        $media = $this->mediaQuery()->where('uid', $uid)->first();

        return $media instanceof MediaContract ? $media : null;
    }

    /**
     * Renditions that failed in a period, grouped by error code, pipeline,
     * transcoder and rendition, the largest groups first.
     *
     * @return list<FailureGroup>
     */
    public function failures(Period $period): array
    {
        $jobs = $this->jobQuery()
            ->with('pipelineRun')
            ->where('status', JobStatus::Failed)
            ->whereBetween('ended_at', $period->bounds())
            ->orderByDesc('ended_at')
            ->limit($this->config->monitoringMaxRows())
            ->get();

        /** @var array<string, array{code: TranscoderErrorCode, run: PipelineRunContract, rendition: string, count: int, message: string, at: CarbonImmutable}> $groups */
        $groups = [];
        foreach ($jobs as $job) {
            $run = $job->run();
            $error = $job->error();
            $code = $error->code ?? TranscoderErrorCode::OperationFailed;
            $name = self::name($job);
            $key = implode('|', [$code->value, $run->name(), $run->transcoder(), $name]);

            if (isset($groups[$key])) {
                $groups[$key]['count']++;

                continue;
            }

            $groups[$key] = [
                'code' => $code,
                'run' => $run,
                'rendition' => $name,
                'count' => 1,
                'message' => $error->message ?? '',
                'at' => self::time($job->getAttribute('ended_at')) ?? CarbonImmutable::instance(Date::now()),
            ];
        }

        $failures = array_map(fn (array $group) => new FailureGroup(
            code: $group['code'],
            pipeline: $group['run']->name(),
            transcoder: $group['run']->transcoder(),
            rendition: $group['rendition'],
            count: $group['count'],
            lastMessage: $group['message'],
            lastFailedAt: $group['at'],
        ), array_values($groups));

        usort($failures, fn (FailureGroup $a, FailureGroup $b) => [$b->count, $b->lastFailedAt] <=> [$a->count, $a->lastFailedAt]);

        return $failures;
    }

    /**
     * How long renditions that completed in a period took, and waited in the
     * queue, by pipeline, rendition and transcoder, over at most
     * `monitoring.max_rows` of the latest ones.
     *
     * @return list<RenditionDurations>
     */
    public function durations(Period $period): array
    {
        $jobs = $this->jobQuery()
            ->with('pipelineRun')
            ->where('status', JobStatus::Completed)
            ->whereNotNull('started_at')
            ->whereBetween('ended_at', $period->bounds())
            ->orderByDesc('ended_at')
            ->limit($this->config->monitoringMaxRows())
            ->get();

        /** @var array<string, array{pipeline: string, rendition: string, transcoder: string, durations: list<float>, waits: list<float>}> $groups */
        $groups = [];
        foreach ($jobs as $job) {
            $run = $job->run();
            $name = self::name($job);
            $key = implode('|', [$run->name(), $name, $run->transcoder()]);
            $groups[$key] ??= ['pipeline' => $run->name(), 'rendition' => $name, 'transcoder' => $run->transcoder(), 'durations' => [], 'waits' => []];

            $queued = self::time($job->getAttribute('queued_at'));
            $started = self::time($job->getAttribute('started_at'));
            $ended = self::time($job->getAttribute('ended_at'));

            if ($started !== null && $ended !== null) {
                $groups[$key]['durations'][] = (float) $started->diffInSeconds($ended);
            }
            if ($queued !== null && $started !== null) {
                $groups[$key]['waits'][] = (float) $queued->diffInSeconds($started);
            }
        }

        $durations = [];
        foreach ($groups as $group) {
            if ($group['durations'] === []) {
                continue;
            }

            $durations[] = new RenditionDurations(
                pipeline: $group['pipeline'],
                rendition: $group['rendition'],
                transcoder: $group['transcoder'],
                count: count($group['durations']),
                median: self::percentile($group['durations'], 0.5),
                p95: self::percentile($group['durations'], 0.95),
                waitMedian: $group['waits'] !== [] ? self::percentile($group['waits'], 0.5) : null,
                waitP95: $group['waits'] !== [] ? self::percentile($group['waits'], 0.95) : null,
            );
        }

        return $durations;
    }

    /**
     * Runs that ended in a period, hour by hour and by status, and the bytes
     * ingested and produced in it.
     */
    public function throughput(Period $period): Throughput
    {
        $hours = [];
        foreach ($this->runQuery()->whereBetween('ended_at', $period->bounds())->toBase()->select(['status', 'ended_at'])->cursor() as $row) {
            $ended = self::time($row->ended_at ?? null);
            if ($ended === null || ! is_string($row->status ?? null)) {
                continue;
            }

            $hour = Throughput::hour($ended);
            $hours[$hour][$row->status] = ($hours[$hour][$row->status] ?? 0) + 1;
        }
        ksort($hours);

        $files = fn () => Models::file()::query()->whereHas('media', fn (Builder $media) => $this->scopeMedia($media->withoutGlobalScopes()));

        return new Throughput(
            period: $period,
            runsPerHour: $hours,
            bytesIngested: (int) $files()->where('handle', FileContract::ORIGINAL)->whereBetween('created_at', $period->bounds())->sum('size'),
            bytesProduced: (int) $files()->where('handle', '!=', FileContract::ORIGINAL)->whereNotNull('pipeline_job_id')->whereBetween('created_at', $period->bounds())->sum('size'),
        );
    }

    /**
     * @return Builder<Model&MediaContract>
     */
    private function mediaQuery(): Builder
    {
        return $this->scopeMedia(Models::media()::query()->withoutGlobalScopes());
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function scopeMedia(Builder $query): Builder
    {
        if ($this->scope !== null) {
            ($this->scope)($query, 'media');
        }

        return $query;
    }

    /**
     * @return Builder<Model&PipelineRunContract>
     */
    private function runQuery(): Builder
    {
        $query = Models::pipelineRun()::query();

        if ($this->scope !== null) {
            $query->whereHasMorph('pipelinable', [Models::media()], fn (Builder $media) => $this->scopeMedia($media->withoutGlobalScopes()));
        }

        return $query;
    }

    /**
     * @return Builder<Model&PipelineJobContract>
     */
    private function jobQuery(): Builder
    {
        $query = Models::pipelineJob()::query();

        if ($this->scope !== null) {
            $query->whereHas('pipelineRun', fn (Builder $run) => $run->whereHasMorph('pipelinable', [Models::media()], fn (Builder $media) => $this->scopeMedia($media->withoutGlobalScopes())));
        }

        return $query;
    }

    /**
     * Runs that haven't ended `monitoring.stuck_after` seconds after they
     * started, or were created when they never started.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function stuck(Builder $query): Builder
    {
        $before = Date::now()->subSeconds($this->config->monitoringStuckAfter());

        return $query
            ->whereIn('status', [PipelineStatus::Pending, PipelineStatus::Running])
            ->where(fn (Builder $query) => $query
                ->where('started_at', '<', $before)
                ->orWhere(fn (Builder $query) => $query->whereNull('started_at')->where('created_at', '<', $before)));
    }

    /**
     * The rendition a job produces, as its row names it.
     */
    private static function name(Model $job): string
    {
        $name = $job->getAttribute('name');

        return is_string($name) ? $name : '';
    }

    private static function time(mixed $value): ?CarbonImmutable
    {
        return match (true) {
            $value instanceof \DateTimeInterface => CarbonImmutable::instance($value),
            is_string($value) && $value !== '' => CarbonImmutable::parse($value),
            default => null,
        };
    }

    /**
     * The nearest-rank percentile of a list of values.
     *
     * @param  non-empty-list<float>  $values
     */
    private static function percentile(array $values, float $percentile): float
    {
        sort($values);
        $rank = (int) ceil($percentile * count($values));

        return $values[max(0, $rank - 1)];
    }
}
