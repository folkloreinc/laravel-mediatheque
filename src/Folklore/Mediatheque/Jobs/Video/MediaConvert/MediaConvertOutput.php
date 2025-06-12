<?php

namespace Folklore\Mediatheque\Jobs\Video\MediaConvert;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use \JsonSerializable;

class MediaConvertOutput implements JsonSerializable, Arrayable, Jsonable
{
    protected $containerSettings = [
        'Container' => 'MP4',
        'Mp4Settings' => [
            'MoovPlacement' => 'PROGRESSIVE_DOWNLOAD',
        ],
    ];

    protected $videoDescriptionH264 = [
        'Codec' => 'H_264',
        'H264Settings' => [
            'MaxBitrate' => 5000000,
            'RateControlMode' => 'QVBR',
            'SceneChangeDetect' => 'TRANSITION_DETECTION',
            'QualityTuningLevel' => 'SINGLE_PASS_HQ',
        ],
    ];

    protected $videoDescriptionWebm = [
        'Codec' => 'VP9',
        'Vp9Settings' => [
            'RateControlMode' => 'VBR',
            'Bitrate' => 5000000,
        ],
    ];

    protected $audioDescriptionAAC = [
        'Codec' => 'AAC',
        'AacSettings' => [
            'Bitrate' => 128000,
            'CodingMode' => 'CODING_MODE_2_0',
            'SampleRate' => 48000,
        ],
    ];

    protected $audioDescriptionOpus = [
        'Codec' => 'OPUS',
        'OpusSettings' => [],
    ];

    protected $options;

    protected $extension = null;

    protected $nameModifier = null;

    protected $width = null;

    protected $height = null;

    protected $scaling = null;

    protected $videoCodec = null;

    protected $audioCodecs = [];

    public function __construct(
        string $videoCodec,
        ?array $audioCodecs,
        int $width,
        int $height,
        ?string $scaling = null,
        ?string $extension = null,
        ?string $nameModifier = null,
        ?array $options = []
    ) {
        $this->videoCodec = $videoCodec;
        $this->audioCodecs = $audioCodecs;

        $this->width = $width;
        $this->height = $height;
        $this->scaling = $scaling ?? 'DEFAULT'; // Means fit with padding

        $this->extension = $extension;
        $this->nameModifier = $nameModifier;

        $this->options = $options;
    }

    protected function getContainerSettings()
    {
        return $this->containerSettings;
    }

    protected function getVideoDescription($videoCodec)
    {
        $videoDescription = [];

        if ($videoCodec === 'h264') {
            $videoDescription['CodecSettings'] = $this->videoDescriptionH264;
        } elseif ($videoCodec === 'h265') {
            $videoDescription['CodecSettings'] = $this->videoDescriptionH265 ?? [];
        } elseif ($videoCodec === 'webm') {
            $videoDescription['CodecSettings'] = $this->videoDescriptionWebM ?? [];
        }
        if (isset($this->width)) {
            $videoDescription['Width'] = $this->width;
        }
        if (isset($this->height)) {
            $videoDescription['Height'] = $this->height;
        }
        if (isset($this->scaling)) {
            $videoDescription['ScalingBehavior'] = $this->scaling;
        }
        return $videoDescription;
    }

    public function getAudioDescriptions()
    {
        return collect($this->audioCodecs)
            ->map(function ($codec, $index) {
                $codecSettings = [];
                if ($codec === 'aac') {
                    $codecSettings = $this->audioDescriptionAAC;
                } elseif ($codec === 'opus') {
                    $codecSettings = $this->audioDescriptionOpus;
                }
                return [
                    'AudioSourceName' => 'Audio Selector ' . ($index + 1),
                    'CodecSettings' => $codecSettings,
                ];
            })
            ->toArray();
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
                'Extension' => $this->extension,
                'ContainerSettings' => $this->getContainerSettings(),
                'VideoDescription' => $this->getVideoDescription($this->videoCodec),
                'AudioDescriptions' => $this->getAudioDescriptions($this->audioCodecs),
            ],
            isset($this->nameModifier) ? ['NameModifier' => $this->nameModifier] : [],
            $this->options ?? []
        );
    }
}
