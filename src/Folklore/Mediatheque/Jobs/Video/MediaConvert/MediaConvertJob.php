<?php

namespace Folklore\Mediatheque\Jobs\Video\MediaConvert;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use \JsonSerializable;

class MediaConvertJob implements JsonSerializable, Arrayable, Jsonable
{
    protected $options = [];

    protected $queue;

    protected $role;

    protected $settings;

    public function __construct(string $queue, string $role, $settings, array $options = [])
    {
        $this->queue = $queue;
        $this->role = $role;
        $this->settings = $settings;
        $this->options = $options;
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    public function toJson($options = 0)
    {
        return json_encode($this->toArray(), $options);
    }

    public function toArray()
    {
        return array_merge(
            [
                'Queue' => $this->queue,
                'Role' => $this->role,
                'UserMetadata' => [],
                'Settings' =>
                    $this->settings instanceof MediaConvertJobSettings
                        ? $this->settings->toArray()
                        : $this->settings,
                'BillingTagsSource' => 'JOB',
                'AccelerationSettings' => [
                    'Mode' => 'DISABLED',
                ],
                'StatusUpdateInterval' => 'SECONDS_60',
                'Priority' => 0,
            ],
            $this->options
        );
    }
}
