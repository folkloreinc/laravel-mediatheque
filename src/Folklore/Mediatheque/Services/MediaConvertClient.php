<?php

namespace Folklore\Mediatheque\Services;

use Aws\MediaConvert\MediaConvertClient as AwsMediaConvertClient;
use Folklore\Mediatheque\Contracts\Services\MediaConvertClient as MediaConvertClientContract;
// use Aws\Exception\AwsException;

class MediaConvertClient implements MediaConvertClientContract
{
    protected $client;

    public function __construct(
        string $key,
        string $secret,
        string $role,
        string $queue,
        ?array $config = null
    ) {
        $config = array_merge(
            [
                'region' => 'us-east-1',
                'version' => 'latest',
                'credentials' => [
                    'key' => $key,
                    'secret' => $secret,
                ],
                'role' => $role ?? null,
                'queue' => $queue ?? null,
                // 'endpoint' => 'https://abcd1234.mediaconvert.us-east-1.amazonaws.com'
            ],
            $config
        );
        $this->client = new AwsMediaConvertClient($config);
    }

    public function createJob(array $jobParams): array
    {
        return $this->client->createJob($jobParams)->toArray();
    }

    public function getJob(string $jobId): array
    {
        return $this->client->getJob(['Id' => $jobId])->toArray();
    }

    public function listJobs(array $params = []): array
    {
        return $this->client->listJobs($params)->toArray();
    }

    public function cancelJob(string $jobId): array
    {
        return $this->client->cancelJob(['Id' => $jobId])->toArray();
    }

    public function isJobComplete(?array $job = null): bool
    {
        $id = data_get($job, 'Job.Id', null);
        $job = $this->getJob($id);
        if (empty($job)) {
            return false;
        }
        $status = data_get($job, 'Job.Status', null);
        return isset($status) && in_array($status, ['COMPLETE', 'ERROR']);
    }

    public function getClient(): AwsMediaConvertClient
    {
        return $this->client;
    }
}
