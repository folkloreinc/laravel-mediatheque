<?php

namespace Folklore\Mediatheque\Contracts\Services;

interface MediaConvertClient
{
    public function createJob(array $jobParams): array;

    public function getJob(string $jobId): array;

    public function listJobs(array $params = []): array;

    public function cancelJob(string $jobId): array;

    public function isJobComplete(?array $job = null): bool;
}
