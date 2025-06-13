<?php

namespace Folklore\Mediatheque\Jobs\Video\MediaConvert;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use \JsonSerializable;
use Illuminate\Support\Arr;

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

    protected $videoBitrate = 5000000;

    protected $audioBitrate = 128000;

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

        $this->videoBitrate =
            isset($options['videoBitrate']) && !empty($options['videoBitrate'])
                ? (int) $options['videoBitrate'] * 1000
                : $this->videoBitrate;
        $this->audioBitrate =
            isset($options['audioBitrate']) && !empty($options['audioBitrate'])
                ? (int) $options['audioBitrate'] * 1000
                : $this->audioBitrate;

        $this->options = Arr::except($options, ['videoBitrate', 'audioBitrate']);
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
        $codecSettings = [];

        if ($videoCodec === 'h264') {
            $codecSettings = $this->videoDescriptionH264;
            data_set($codecSettings, 'H264Settings.MaxBitrate', $this->videoBitrate);
        } elseif ($videoCodec === 'h265') {
            $codecSettings = $this->videoDescriptionH265 ?? [];
            data_set($codecSettings, 'H265Settings.MaxBitrate', $this->videoBitrate);
        } elseif ($videoCodec === 'webm') {
            $codecSettings = $this->videoDescriptionWebM ?? [];
            data_set($codecSettings, 'Vp9Settings.Bitrate', $this->videoBitrate);
        }

        return array_merge(
            [
                'CodecSettings' => $codecSettings,
            ],
            isset($this->width) ? ['Width' => $this->width] : [],
            isset($this->height) ? ['Height' => $this->height] : [],
            isset($this->scaling) ? ['ScalingBehavior' => $this->scaling] : []
        );
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
                data_set($codecSettings, 'Bitrate', $this->audioBitrate);
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
