<?php

namespace Folklore\Mediatheque\Jobs\Video\MediaConvert;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use \JsonSerializable;

class MediaConvertOutput implements JsonSerializable, Arrayable, Jsonable
{
    protected $containerSettingsMP4 = [
        'Container' => 'MP4',
        'Mp4Settings' => [
            'MoovPlacement' => 'PROGRESSIVE_DOWNLOAD',
        ],
    ];

    protected $containerSettingsWebM = [
        'Container' => 'WEBM',
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

    protected $videoDescriptionH265 = [
        'Codec' => 'H_265',
        'H265Settings' => [
            'MaxBitrate' => 5000000,
            'RateControlMode' => 'QVBR',
            'SceneChangeDetect' => 'TRANSITION_DETECTION',
            'QualityTuningLevel' => 'SINGLE_PASS_HQ',
        ],
    ];

    protected $videoDescriptionWebM = [
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
        'OpusSettings' => [
            'Bitrate' => 128000,
            'SampleRate' => 48000,
            'Channels' => 2,
        ],
    ];

    protected $options;

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
        ?array $options = []
    ) {
        $this->videoCodec = $videoCodec;
        $this->audioCodecs = $audioCodecs;

        $this->width = $width;
        $this->height = $height;
        $this->scaling = $scaling ?? 'DEFAULT'; // Means fit with padding

        $this->options = $options;
    }

    protected function getContainerSettings($videoCodec)
    {
        if ($videoCodec === 'h264' || $videoCodec === 'h265') {
            return $this->containerSettingsMP4;
        } elseif ($videoCodec === 'webm') {
            return $this->containerSettingsWebM;
        }
        return null;
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
                'ContainerSettings' => $this->getContainerSettings($this->videoCodec),
                'VideoDescription' => $this->getVideoDescription($this->videoCodec),
                'AudioDescriptions' => $this->getAudioDescriptions($this->audioCodecs),
            ],
            $this->options ?? []
        );
    }
}
