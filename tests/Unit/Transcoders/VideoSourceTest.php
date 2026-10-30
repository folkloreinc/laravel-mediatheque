<?php

namespace Folklore\Mediatheque\Tests\Unit\Transcoders;

use Folklore\Mediatheque\Transcoders\Ffmpeg\VideoSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class VideoSourceTest extends TestCase
{
    /**
     * A 1080p H.264 file ready for the web, as ffprobe describes it.
     *
     * @param  array<string, mixed>  $video
     * @return array<string, mixed>
     */
    private static function probe(array $video = []): array
    {
        return [
            'streams' => [
                [
                    'codec_type' => 'video',
                    'codec_name' => 'h264',
                    'profile' => 'High',
                    'level' => 40,
                    'pix_fmt' => 'yuv420p',
                    'width' => 1920,
                    'height' => 1080,
                    'sample_aspect_ratio' => '1:1',
                    'field_order' => 'progressive',
                    'avg_frame_rate' => '24000/1001',
                    'bit_rate' => '1992382',
                    ...$video,
                ],
                ['codec_type' => 'audio', 'codec_name' => 'aac', 'channels' => 2, 'bit_rate' => '128001'],
            ],
            'format' => ['bit_rate' => '2126104'],
        ];
    }

    #[Test]
    public function it_reads_the_video_and_audio_streams(): void
    {
        $source = VideoSource::fromFfprobe(self::probe());

        $this->assertNotNull($source);
        $this->assertSame([1920, 1080, 'h264', 'High', 40, 1992382], [$source->width, $source->height, $source->codec, $source->profile, $source->level, $source->bitrate]);
        $this->assertEqualsWithDelta(23.976, $source->frameRate, 0.001);
        $this->assertSame(['aac', 2, 128001], [$source->audioCodec, $source->audioChannels, $source->audioBitrate]);
        $this->assertTrue($source->plain);
        $this->assertNull(VideoSource::fromFfprobe(['streams' => [['codec_type' => 'audio']]]));
    }

    #[Test]
    public function a_rotated_video_has_its_displayed_size_and_is_not_plain(): void
    {
        $source = VideoSource::fromFfprobe(self::probe(['side_data_list' => [['rotation' => -90]]]));

        $this->assertSame([1080, 1920, false], [$source?->width, $source?->height, $source?->plain]);
    }

    #[Test]
    public function without_a_stream_bitrate_it_takes_the_file_s(): void
    {
        $this->assertSame(2126104, VideoSource::fromFfprobe(self::probe(['bit_rate' => null]))?->bitrate);
    }

    #[Test]
    public function the_web_bitrate_follows_the_output_size_and_the_frame_rate(): void
    {
        $source = new VideoSource(1920, 1080, frameRate: 30.0);

        $this->assertSame(3000, $source->webBitrate(1280, 720));
        $this->assertSame(3000, $source->webBitrate(720, 1280));
        $this->assertSame(6000, $source->webBitrate(1920, 1080));
        $this->assertSame(16000, $source->webBitrate(3840, 2160));
        $this->assertSame(9000, (new VideoSource(1920, 1080, frameRate: 60.0))->webBitrate(1920, 1080));
    }

    #[Test]
    public function it_fits_in_the_maximum_size_without_upscaling(): void
    {
        $source = new VideoSource(1920, 1080);

        $this->assertSame([1280, 720], $source->fit(1280, 1280));
        $this->assertSame([1920, 1080], $source->fit(3840, null));
        $this->assertSame([1920, 1080], $source->fit(null, null));
    }

    #[Test]
    public function a_web_ready_video_can_be_copied(): void
    {
        $source = VideoSource::fromFfprobe(self::probe());

        $this->assertNotNull($source);
        $this->assertNull($source->passthroughRefusal(1920, 1920, 6000));
        $this->assertTrue($source->copiesAudio(128));
        $this->assertFalse($source->copiesAudio(48), 'Audio far above the bitrate is encoded.');
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function refusals(): iterable
    {
        yield 'another codec' => [['codec_name' => 'hevc'], '/hevc/'];
        yield '10 bits' => [['pix_fmt' => 'yuv420p10le', 'profile' => 'High 10'], '/yuv420p10le/'];
        yield 'a profile browsers lack' => [['profile' => 'Baseline'], '/profile/'];
        yield 'a level above 4.2' => [['level' => 51], '/level is 5\.1/'];
        yield 'interlaced' => [['field_order' => 'tt'], '/interlaced/'];
        yield 'non-square pixels' => [['sample_aspect_ratio' => '4:3'], '/non-square/'];
        yield 'an unknown bitrate' => [['bit_rate' => null], '/bitrate/'];
    }

    /**
     * @param  array<string, mixed>  $video
     */
    #[Test]
    #[DataProvider('refusals')]
    public function a_video_that_does_not_fit_is_encoded(array $video, string $reason): void
    {
        $probe = self::probe($video);
        if (array_key_exists('bit_rate', $video)) {
            unset($probe['format']);
        }

        $this->assertMatchesRegularExpression($reason, (string) VideoSource::fromFfprobe($probe)?->passthroughRefusal(null, null, 6000));
    }

    #[Test]
    public function a_video_larger_or_above_the_bitrate_is_encoded(): void
    {
        $source = VideoSource::fromFfprobe(self::probe());

        $this->assertMatchesRegularExpression('/larger/', (string) $source?->passthroughRefusal(1280, 1280, 6000));
        $this->assertMatchesRegularExpression('/above 1500 kbit\/s/', (string) $source?->passthroughRefusal(null, null, 1500));
    }
}
