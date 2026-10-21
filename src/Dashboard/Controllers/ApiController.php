<?php

namespace Folklore\Mediatheque\Dashboard\Controllers;

use Carbon\CarbonImmutable;
use Folklore\Mediatheque\Contracts\Mediatheque;
use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Contracts\Models\PipelineRun;
use Folklore\Mediatheque\Dashboard\Problem;
use Folklore\Mediatheque\Enums\JobStatus;
use Folklore\Mediatheque\Enums\MediaStatus;
use Folklore\Mediatheque\Enums\PipelineStatus;
use Folklore\Mediatheque\Enums\TranscoderErrorCode;
use Folklore\Mediatheque\Http\Resources\PipelineRunResource;
use Folklore\Mediatheque\Metadata\MetadataKey;
use Folklore\Mediatheque\Monitoring\FailureGroup;
use Folklore\Mediatheque\Monitoring\Monitoring;
use Folklore\Mediatheque\Monitoring\Period;
use Folklore\Mediatheque\Monitoring\RenditionDurations;
use Folklore\Mediatheque\Monitoring\RunFilters;
use Folklore\Mediatheque\Pipelines\PipelineDefinition;
use Folklore\Mediatheque\Transcoders\Capabilities;
use Folklore\Mediatheque\Transcoders\TranscoderManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * The dashboard's JSON endpoints, on the Monitoring read model and the
 * actions of the Mediatheque contract (docs/decisions/0017). They answer
 * the dashboard's page only, and change with it: they are not a versioned
 * API. Runs and media are found within the monitoring scope.
 */
final class ApiController
{
    private const int MAX_HOURS = 24 * 90;

    private const int MAX_LIMIT = 100;

    public function __construct(
        private readonly Monitoring $monitoring,
        private readonly Mediatheque $mediatheque,
        private readonly TranscoderManager $transcoders,
    ) {}

    public function overview(Request $request): JsonResponse
    {
        $overview = $this->monitoring->overview($this->period($request));

        return new JsonResponse(['data' => [
            'period' => self::periodBounds($overview->period),
            'media' => $overview->media,
            'runs_in_progress' => $overview->runsInProgress,
            'stuck_runs' => $overview->stuckRuns,
            'runs_ended' => $overview->runsEnded,
            'oldest_queued_at' => self::time($overview->oldestQueuedAt),
        ]]);
    }

    public function runs(Request $request): JsonResponse
    {
        $hours = $request->query('hours');
        $page = $this->monitoring->runs(new RunFilters(
            status: PipelineStatus::tryFrom(self::string($request, 'status') ?? ''),
            pipeline: self::string($request, 'pipeline'),
            transcoder: self::string($request, 'transcoder'),
            mediaType: self::string($request, 'media_type'),
            errorCode: TranscoderErrorCode::tryFrom(self::string($request, 'error_code') ?? ''),
            media: self::string($request, 'media'),
            period: $hours !== null ? $this->period($request) : null,
            stuck: $request->boolean('stuck'),
        ), self::string($request, 'cursor'), $this->limit($request));

        return new JsonResponse([
            'data' => array_map(fn (PipelineRun $run) => (new PipelineRunResource($run))->toArray($request), $page->items),
            'next_cursor' => $page->nextCursor,
        ]);
    }

    public function run(Request $request): JsonResponse
    {
        $run = $this->findRun($request);

        return $run instanceof PipelineRun ? $this->runResponse($request, $run) : $run;
    }

    public function failures(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => array_map(fn (FailureGroup $group) => [
            'code' => $group->code->value,
            'pipeline' => $group->pipeline,
            'transcoder' => $group->transcoder,
            'rendition' => $group->rendition,
            'count' => $group->count,
            'last_message' => $group->lastMessage,
            'last_failed_at' => self::time($group->lastFailedAt),
        ], $this->monitoring->failures($this->period($request)))]);
    }

    public function durations(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => array_map(fn (RenditionDurations $durations) => [
            'pipeline' => $durations->pipeline,
            'rendition' => $durations->rendition,
            'transcoder' => $durations->transcoder,
            'count' => $durations->count,
            'median' => $durations->median,
            'p95' => $durations->p95,
            'wait_median' => $durations->waitMedian,
            'wait_p95' => $durations->waitP95,
        ], $this->monitoring->durations($this->period($request)))]);
    }

    public function throughput(Request $request): JsonResponse
    {
        $throughput = $this->monitoring->throughput($this->period($request));

        return new JsonResponse(['data' => [
            'period' => self::periodBounds($throughput->period),
            'runs_per_hour' => (object) $throughput->runsPerHour,
            'bytes_ingested' => $throughput->bytesIngested,
            'bytes_produced' => $throughput->bytesProduced,
        ]]);
    }

    public function retry(Request $request): JsonResponse
    {
        $run = $this->findRun($request);
        if (! $run instanceof PipelineRun) {
            return $run;
        }

        $retry = $this->mediatheque->retry($run);

        return $retry !== null
            ? $this->runResponse($request, $this->monitoring->run($retry->key()) ?? $retry, 201)
            : Problem::response(409, 'The run has nothing to retry.', 'It hasn\'t ended, nothing in it failed, or its media has a run of the same pipeline in progress.', ['reason' => 'nothing_to_retry']);
    }

    public function cancel(Request $request): JsonResponse
    {
        $run = $this->findRun($request);
        if (! $run instanceof PipelineRun) {
            return $run;
        }

        return $this->mediatheque->cancel($run)
            ? $this->runResponse($request, $this->monitoring->run($run->key()) ?? $run)
            : Problem::response(409, 'The run can\'t be canceled.', 'It has ended, or its transcoder can\'t cancel it.', ['reason' => 'not_cancelable']);
    }

    public function refresh(Request $request): JsonResponse
    {
        $run = $this->findRun($request);
        if (! $run instanceof PipelineRun) {
            return $run;
        }

        return $this->mediatheque->refresh($run)
            ? $this->runResponse($request, $this->monitoring->run($run->key()) ?? $run)
            : Problem::response(409, 'The run can\'t be refreshed.', 'It has ended, or its transcoder can\'t refresh it.', ['reason' => 'not_refreshable']);
    }

    public function probe(Request $request): JsonResponse
    {
        $uid = $request->route('media');
        $media = is_string($uid) ? $this->monitoring->media($uid) : null;

        if ($media === null) {
            return Problem::response(404, 'The media doesn\'t exist.');
        }

        return $this->mediatheque->probeAgain($media)
            ? new JsonResponse(['data' => ['id' => $media->uid(), 'status' => MediaStatus::Pending->value]], 202)
            : Problem::response(409, 'The media can\'t be probed again.', 'Only a media whose probe failed is probed again.', ['reason' => 'not_probe_failed']);
    }

    /**
     * A run, and what the dashboard can do with it.
     */
    private function runResponse(Request $request, PipelineRun $run, int $status = 200): JsonResponse
    {
        $ended = $run->status()->isFinal();
        $probe = $run->name() === PipelineDefinition::PROBE;
        $capabilities = $probe ? Capabilities::none() : $this->capabilities($run->transcoder());
        $retryable = $ended && ! $probe && $run->jobs()->contains(fn ($job) => in_array($job->status(), [JobStatus::Failed, JobStatus::Canceled], true));

        return new JsonResponse(['data' => [
            ...(new PipelineRunResource($run))->toArray($request),
            'actions' => [
                'retry' => $retryable,
                'cancel' => ! $ended && ($probe ? $run->externalId() === null : $capabilities->cancel),
                'refresh' => ! $ended && $capabilities->refresh,
                'probe_again' => self::probeFailed($run->media()),
            ],
        ]], $status);
    }

    private function findRun(Request $request): PipelineRun|JsonResponse
    {
        $uid = $request->route('run');
        $run = is_string($uid) ? $this->monitoring->run($uid) : null;

        return $run ?? Problem::response(404, 'The run doesn\'t exist.');
    }

    private function capabilities(string $transcoder): Capabilities
    {
        try {
            return $this->transcoders->driver($transcoder)->capabilities();
        } catch (InvalidArgumentException) {
            return Capabilities::none();
        }
    }

    private static function probeFailed(Media $media): bool
    {
        return $media->status() === MediaStatus::Failed && $media->metadata(MetadataKey::Kind->value) === null;
    }

    /**
     * The last `hours` up to now: 24 by default, 90 days at most.
     */
    private function period(Request $request): Period
    {
        $hours = filter_var($request->query('hours', '24'), FILTER_VALIDATE_INT);

        return Period::lastHours(is_int($hours) ? max(1, min(self::MAX_HOURS, $hours)) : 24);
    }

    private function limit(Request $request): int
    {
        $limit = filter_var($request->query('limit', '50'), FILTER_VALIDATE_INT);

        return is_int($limit) ? max(1, min(self::MAX_LIMIT, $limit)) : 50;
    }

    private static function string(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array{from: string|null, to: string|null}
     */
    private static function periodBounds(Period $period): array
    {
        return ['from' => self::time($period->from), 'to' => self::time($period->to)];
    }

    private static function time(?CarbonImmutable $time): ?string
    {
        return $time?->utc()->toIso8601ZuluString();
    }
}
