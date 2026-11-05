<?php

namespace Folklore\Mediatheque\Tests\Contracts;

use Folklore\Mediatheque\Contracts\Mediatheque;
use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Contracts\Models\PipelineRun;
use Folklore\Mediatheque\Enums\PipelineStatus;
use Folklore\Mediatheque\Exceptions\InvalidPipeline;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use stdClass;

/**
 * The options of one run, from Mediatheque::runPipeline()
 * (docs/decisions/0027), on the default `video` pipeline: `h264`
 * (`codec: h264`, `max_width: 1920`), `poster` and `thumbnails`.
 */
trait RunOptionsContractTests
{
    abstract protected function mediatheque(): Mediatheque;

    /**
     * A probed video, `ready` or `processing`.
     */
    abstract protected function probedVideo(): Media;

    /**
     * Renditions of the runs created from now on fail. None when empty.
     *
     * @param  list<string>  $renditions
     */
    abstract protected function failRenditions(array $renditions): void;

    abstract protected function freshRun(PipelineRun $run): PipelineRun;

    abstract protected function freshMedia(Media $media): Media;

    /**
     * @param  array<string, array<string, mixed>>  $options
     */
    private function runWithOptions(Media $media, array $options, bool $force = false): ?PipelineRun
    {
        $run = $this->mediatheque()->runPipeline($this->freshMedia($media), 'video', ['h264'], $force, $options);

        return $run === null ? null : $this->freshRun($run);
    }

    #[Test]
    public function the_options_of_a_run_replace_the_options_of_the_same_name(): void
    {
        $run = $this->runWithOptions($this->probedVideo(), ['h264' => ['max_width' => 1280, 'max_bitrate' => 2500]]);

        $this->assertNotNull($run);
        $h264 = $run->renditions()['h264'];
        $this->assertSame('video.transcode', $h264->operation);
        $this->assertSame('h264', $h264->options['codec'] ?? null, 'Options the run doesn\'t pass keep the config\'s value.');
        $this->assertSame(1280, $h264->options['max_width'] ?? null);
        $this->assertSame(2500, $h264->options['max_bitrate'] ?? null);
        $this->assertSame(PipelineStatus::Completed, $run->status());
    }

    #[Test]
    public function the_same_options_again_produce_nothing_and_other_options_produce_the_rendition_again(): void
    {
        $media = $this->probedVideo();
        $this->assertNotNull($this->runWithOptions($media, ['h264' => ['max_width' => 1280]]));

        $this->assertNull($this->runWithOptions($media, ['h264' => ['max_width' => 1280]]), 'The options are part of the rendition\'s definition hash.');

        $other = $this->runWithOptions($media, ['h264' => ['max_width' => 720]]);
        $this->assertNotNull($other);
        $this->assertSame(720, $other->renditions()['h264']->options['max_width'] ?? null);

        $default = $this->runWithOptions($media, []);
        $this->assertNotNull($default, 'A run without the options produces the config\'s definition again.');
        $this->assertSame(1920, $default->renditions()['h264']->options['max_width'] ?? null);
    }

    #[Test]
    public function retry_creates_the_new_run_with_the_options_of_the_run_it_retries(): void
    {
        $media = $this->probedVideo();
        $this->failRenditions(['h264']);
        $failed = $this->runWithOptions($media, ['h264' => ['max_width' => 1280]]);
        $this->assertNotNull($failed);
        $this->assertSame(PipelineStatus::Failed, $failed->status());

        $this->failRenditions([]);
        $retry = $this->mediatheque()->retry($failed);

        $this->assertNotNull($retry);
        $retry = $this->freshRun($retry);
        $this->assertSame(1280, $retry->renditions()['h264']->options['max_width'] ?? null);
        $this->assertSame(PipelineStatus::Completed, $retry->status());
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function invalidRunOptions(): iterable
    {
        yield 'a rendition the pipeline doesn\'t have' => [['vertical' => ['max_width' => 720]]];
        yield 'options that are not an array' => [['h264' => 720]];
        yield 'options that are not keyed by name' => [['h264' => [720]]];
        yield 'a rendition\'s own key' => [['h264' => ['op' => 'audio.transcode']]];
        yield 'a value JSON can\'t hold' => [['h264' => ['max_width' => new stdClass]]];
        yield 'a number JSON can\'t hold' => [['h264' => ['max_width' => NAN]]];
    }

    /**
     * @param  array<array-key, mixed>  $options
     */
    #[Test]
    #[DataProvider('invalidRunOptions')]
    public function invalid_options_throw(array $options): void
    {
        $media = $this->probedVideo();

        $this->expectException(InvalidPipeline::class);

        // @phpstan-ignore argument.type (the invalid options are the test)
        $this->mediatheque()->runPipeline($media, 'video', ['h264'], options: $options);
    }
}
