<?php

namespace Folklore\Mediatheque\Tests\Unit;

use Folklore\Mediatheque\Metadata\MetadataKey;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MetadataKeyTest extends TestCase
{
    #[Test]
    public function the_canonical_keys_are_snake_case(): void
    {
        $this->assertSame([
            'kind', 'mime', 'extension', 'size', 'checksum', 'filename',
            'width', 'height', 'rotation', 'duration', 'frame_rate', 'bitrate',
            'video_codec', 'audio_codec', 'audio_channels', 'pages_count', 'font_names',
            'animated', 'exif', 'palette', 'blurhash', 'scenes', 'locale',
        ], array_column(MetadataKey::cases(), 'value'));
    }

    #[Test]
    public function it_maps_driver_names_to_the_canonical_keys(): void
    {
        $this->assertSame([
            'frame_rate' => 25.0,
            'pages_count' => 12,
            'video_codec' => 'h264',
            'audio_channels' => 2,
            'mime' => 'video/mp4',
            'font_names' => ['Inter'],
        ], MetadataKey::normalize([
            'frameRate' => 25.0,
            'pageCount' => 12,
            'videoCodec' => 'h264',
            'audioChannels' => 2,
            'mimeType' => 'video/mp4',
            'fontNames' => ['Inter'],
        ]));
    }

    #[Test]
    public function other_keys_are_stored_in_snake_case(): void
    {
        $this->assertSame(
            ['color_space' => 'bt709', 'has_alpha' => false, 'loudness_lufs' => -14],
            MetadataKey::normalize(['colorSpace' => 'bt709', 'has-alpha' => false, 'loudness_lufs' => -14]),
        );
    }

    #[Test]
    public function values_are_kept_as_they_are(): void
    {
        $exif = ['Make' => 'Apple', 'ExposureTime' => '1/60'];

        $this->assertSame(['exif' => $exif], MetadataKey::normalize(['exif' => $exif]));
    }
}
