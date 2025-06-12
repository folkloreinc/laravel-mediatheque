<?php

namespace Folklore\Mediatheque\Jobs\Video\MediaConvert;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use \JsonSerializable;

class MediaConvertJobSettings implements JsonSerializable, Arrayable, Jsonable
{
    protected $options = [];

    protected $name;

    protected $destination;

    protected $inputs;

    protected $outputs;

    public function __construct(
        string $name,
        string $destination,
        $inputs,
        $outputs,
        array $options = []
    ) {
        $this->name = $name;
        $this->destination = $destination;

        $this->inputs = $inputs;
        $this->outputs = $outputs;

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
                'TimecodeConfig' => [
                    'Source' => 'EMBEDDED',
                ],
                'AdAvailOffset' => 0,
                'Inputs' => collect(is_array($this->inputs) ? $this->inputs : [$this->inputs])
                    ->map(function ($input) {
                        return $input instanceof MediaConvertInput ? $input->toArray() : $input;
                    })
                    ->values()
                    ->toArray(),
                'OutputGroups' => [
                    [
                        'Name' => 'File Group',
                        'CustomName' => $this->name,
                        'OutputGroupSettings' => [
                            'Type' => 'FILE_GROUP_SETTINGS',
                            'FileGroupSettings' => [
                                'Destination' => $this->destination, // S3 path
                                'DestinationSettings' => [
                                    'S3Settings' => [
                                        'StorageClass' => 'STANDARD',
                                        'AccessControl' => [
                                            'CannedAcl' => 'PUBLIC_READ',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'Outputs' => collect(
                            is_array($this->outputs) ? $this->outputs : [$this->outputs]
                        )
                            ->map(function ($output) {
                                return $output instanceof MediaConvertOutput
                                    ? $output->toArray()
                                    : $output;
                            })
                            ->values()
                            ->toArray(),
                    ],
                ],
            ],
            $this->options
        );
    }
}
