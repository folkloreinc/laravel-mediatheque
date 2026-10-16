<?php

namespace Folklore\Mediatheque\Tests\Unit;

use Folklore\Mediatheque\Pipelines\OutputFile;
use Folklore\Mediatheque\Pipelines\Outputs;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class OutputsTest extends TestCase
{
    #[Test]
    public function a_rendition_can_produce_nothing(): void
    {
        $outputs = Outputs::none();

        $this->assertFalse($outputs->hasFiles());
        $this->assertNull($outputs->mainFile());
        $this->assertSame([], $outputs->metadata);
    }

    #[Test]
    public function a_group_is_ordered_by_index_and_its_main_file_is_the_first(): void
    {
        $outputs = Outputs::group([
            new OutputFile('runs/1/thumbnails-1.jpg', index: 1),
            new OutputFile('runs/1/thumbnails-0.jpg', index: 0),
            new OutputFile('runs/1/thumbnails-2.jpg', index: 2),
        ]);

        $this->assertSame([0, 1, 2], array_map(fn (OutputFile $file) => $file->index, $outputs->files));
        $this->assertSame('runs/1/thumbnails-0.jpg', $outputs->mainFile()?->path);
    }

    #[Test]
    public function files_of_a_group_need_their_own_index(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Outputs::group([new OutputFile('a.jpg', index: 0), new OutputFile('b.jpg', index: 0)]);
    }

    #[Test]
    public function files_of_a_group_need_an_index(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Outputs::group([new OutputFile('a.jpg'), new OutputFile('b.jpg')]);
    }

    #[Test]
    public function metadata_outputs_use_the_canonical_keys(): void
    {
        $outputs = Outputs::none()->withMetadata(['palette' => ['#000000'], 'sceneCount' => 3]);

        $this->assertSame(['palette' => ['#000000'], 'scene_count' => 3], $outputs->metadata);
    }

    #[Test]
    public function an_output_file_needs_a_path(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new OutputFile('');
    }

    #[Test]
    public function an_output_file_says_if_it_must_be_probed(): void
    {
        $this->assertFalse((new OutputFile('h264.mp4'))->isComplete());
        $this->assertTrue((new OutputFile('poster.jpg', width: 1920, height: 1080))->isComplete());
        $this->assertFalse((new OutputFile('h264.mp4', width: 1920, height: 1080))->isComplete(needsDuration: true));
        $this->assertTrue((new OutputFile('h264.mp4', width: 1920, height: 1080, duration: 10.0, bitrate: 4_000_000))->isComplete(needsDuration: true));
    }
}
