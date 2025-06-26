<?php

namespace Folklore\Mediatheque\Jobs\Video\MediaConvert;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use \JsonSerializable;

class MediaConvertInput implements JsonSerializable, Arrayable, Jsonable
{
    protected $options = [
        'AudioSelectors' => [
            'Audio Selector 1' => [
                'Offset' => 0,
                'DefaultSelection' => 'DEFAULT',
                'SelectorType' => 'TRACK',
            ],
        ],
        // 'VideoSelector' => [
        //     'ColorSpace' => 'REC_601',
        //     'ColorSpaceUsage' => 'FORCE',
        // ],
        'FilterEnable' => 'AUTO',
        'TimecodeSource' => 'ZEROBASED',
    ];

    protected $fileInput = null;

    public function __construct($fileInput, ?array $options = [])
    {
        $this->fileInput = $fileInput;
        $this->options = array_merge($this->options, $options);
    }

    public function setFileInput($fileInput): self
    {
        $this->fileInput = $fileInput;
        return $this;
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
        return array_merge(['FileInput' => $this->fileInput], $this->options);
    }
}
