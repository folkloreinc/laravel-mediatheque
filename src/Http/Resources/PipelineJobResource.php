<?php

namespace Folklore\Mediatheque\Http\Resources;

use Folklore\Mediatheque\Contracts\Models\PipelineJob;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The JSON shape of a rendition in a run (docs/decisions/0017): what it
 * produces, how it went, and how long it waited and took. `duration` and
 * `wait` are in seconds.
 *
 * @property PipelineJob $resource
 */
class PipelineJobResource extends JsonResource
{
    use FormatsTimes;

    public function __construct(PipelineJob $job)
    {
        parent::__construct($job);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $job = $this->resource;
        $rendition = $job->rendition();

        return [
            'rendition' => $rendition->name,
            'operation' => $rendition->operation,
            'from' => $rendition->from,
            'status' => $job->status()->value,
            'progress' => $job->progress(),
            'attempts' => $job->attempts(),
            'error' => $job->error()?->toArray(),
            'reason' => $job->reason(),
            'queued_at' => self::time($job->queuedAt()),
            'started_at' => self::time($job->startedAt()),
            'ended_at' => self::time($job->endedAt()),
            'wait' => self::seconds($job->queuedAt(), $job->startedAt()),
            'duration' => self::seconds($job->startedAt(), $job->endedAt()),
        ];
    }
}
