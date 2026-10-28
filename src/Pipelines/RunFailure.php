<?php

namespace Folklore\Mediatheque\Pipelines;

use Folklore\Mediatheque\Contracts\Models\PipelineRun;
use Folklore\Mediatheque\Enums\JobStatus;
use Folklore\Mediatheque\Enums\TranscoderErrorCode;
use Folklore\Mediatheque\Transcoders\TranscoderError;

/**
 * Why a run failed, for `Media::failure()`: the error of its first
 * rendition, in the order of its definition, that failed for its own
 * reason rather than its input's (`dependency_failed`), or else of its
 * first failed rendition, or else the run's own error.
 *
 * @internal
 */
final class RunFailure
{
    public static function of(PipelineRun $run): ?TranscoderError
    {
        $errors = [];
        foreach (array_keys($run->renditions()) as $name) {
            $job = $run->job($name);
            $error = $job?->status() === JobStatus::Failed ? $job->error() : null;
            if ($error !== null) {
                $errors[] = $error;
            }
        }

        foreach ($errors as $error) {
            if ($error->code !== TranscoderErrorCode::DependencyFailed) {
                return $error;
            }
        }

        return $errors[0] ?? $run->error();
    }
}
