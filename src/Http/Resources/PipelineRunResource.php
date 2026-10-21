<?php

namespace Folklore\Mediatheque\Http\Resources;

use Folklore\Mediatheque\Contracts\Models\PipelineJob;
use Folklore\Mediatheque\Contracts\Models\PipelineRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The JSON shape of a pipeline run (docs/decisions/0017): its pipeline and
 * transcoder, how it went, its media in short, and its renditions in the
 * order of its definition. `duration` is in seconds.
 *
 * @property PipelineRun $resource
 */
class PipelineRunResource extends JsonResource
{
    use FormatsTimes;

    public function __construct(PipelineRun $run)
    {
        parent::__construct($run);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $run = $this->resource;
        $media = $run->media();

        $renditions = [];
        foreach (array_keys($run->renditions()) as $name) {
            $job = $run->job($name);
            if ($job instanceof PipelineJob) {
                $renditions[] = (new PipelineJobResource($job))->toArray($request);
            }
        }

        return [
            'id' => $run->key(),
            'pipeline' => $run->name(),
            'status' => $run->status()->value,
            'transcoder' => $run->transcoder(),
            'external_id' => $run->externalId(),
            'progress' => $run->progress(),
            'error' => $run->error()?->toArray(),
            'media' => [
                'id' => $media->uid(),
                'type' => $media->type(),
                'name' => $media->name(),
                'status' => $media->status()->value,
            ],
            'renditions' => $renditions,
            'created_at' => self::time($run->createdAt()),
            'started_at' => self::time($run->startedAt()),
            'ended_at' => self::time($run->endedAt()),
            'duration' => self::seconds($run->startedAt(), $run->endedAt()),
        ];
    }
}
