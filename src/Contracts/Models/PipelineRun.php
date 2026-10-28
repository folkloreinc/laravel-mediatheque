<?php

namespace Folklore\Mediatheque\Contracts\Models;

use Carbon\CarbonInterface;
use Folklore\Mediatheque\Enums\PipelineStatus;
use Folklore\Mediatheque\Enums\Visibility;
use Folklore\Mediatheque\Pipelines\Rendition;
use Folklore\Mediatheque\Transcoders\TranscoderError;
use Illuminate\Support\Collection;

/**
 * One execution of a pipeline on a media, by one transcoder
 * (docs/decisions/0012). A run is never retried in place: a retry is a new
 * run.
 */
interface PipelineRun
{
    /**
     * A stable id for the run, which drivers send back with their callbacks.
     */
    public function key(): string;

    /**
     * The pipeline's name, `probe` for a pending probe.
     */
    public function name(): string;

    public function media(): Media;

    /**
     * The file the run reads: the media's original.
     */
    public function input(): File;

    /**
     * The transcoder that runs it.
     */
    public function transcoder(): string;

    /**
     * The transcoder's job id, once submitted.
     */
    public function externalId(): ?string;

    public function status(): PipelineStatus;

    /**
     * The renditions this run produces, closed under `from`, as they were
     * when it was created.
     *
     * @return array<string, Rendition>
     */
    public function renditions(): array;

    /**
     * @return Collection<int, PipelineJob>
     */
    public function jobs(): Collection;

    public function job(string $rendition): ?PipelineJob;

    /**
     * The source the renditions are written to.
     */
    public function outputSource(): string;

    /**
     * The path prefix of the run's files (`run_path_format`). Remote drivers
     * write under it, with their own file names.
     */
    public function pathPrefix(): string;

    /**
     * The visibility drivers apply to the files they write.
     */
    public function visibility(): Visibility;

    /**
     * Between 0 and 1: the mean of its renditions, weighted by the input's
     * duration for transcodes, where an ended rendition is done
     * (Pipelines\Progress). Null while none of its renditions was measured
     * or ended.
     */
    public function progress(): ?float;

    public function error(): ?TranscoderError;

    public function createdAt(): ?CarbonInterface;

    /** When its first rendition started or ended. */
    public function startedAt(): ?CarbonInterface;

    /** When every rendition had a final status. */
    public function endedAt(): ?CarbonInterface;
}
