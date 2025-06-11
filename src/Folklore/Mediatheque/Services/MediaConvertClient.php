<?php

namespace Folklore\Mediatheque\Services;

use Aws\MediaConvert\MediaConvertClient as AwsMediaConvertClient;
use Folklore\Mediatheque\Contracts\Services\MediaConvertClient as MediaConvertClientContract;
// use Aws\Exception\AwsException;

class MediaConvertClient implements MediaConvertClientContract
{
    protected $client;

    public function __construct(string $key, string $secret, ?array $config = null)
    {
        $config = array_merge(
            [
                'region' => 'us-east-1',
                'version' => 'latest',
                'credentials' => [
                    'key' => $key,
                    'secret' => $secret,
                ],
                // 'endpoint' => 'https://abcd1234.mediaconvert.us-east-1.amazonaws.com' // optionnel
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

    public function getClient(): AwsMediaConvertClient
    {
        return $this->client;
    }
}
