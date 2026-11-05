<?php

namespace Folklore\Mediatheque\Tests\Unit;

use Folklore\Mediatheque\Enums\ProbeKind;
use Folklore\Mediatheque\Exceptions\InvalidPipeline;
use Folklore\Mediatheque\Pipelines\Rendition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class RenditionTest extends TestCase
{
    #[Test]
    public function it_reads_a_rendition_from_the_config(): void
    {
        $rendition = Rendition::fromArray('thumbnails', [
            'op' => 'video.thumbnails',
            'count' => 5,
            'from' => 'h264',
            'when' => ['kind' => 'video', 'min_duration' => 2],
            'queue' => 'media',
            'role' => 'thumbnail',
        ]);

        $this->assertSame('thumbnails', $rendition->name);
        $this->assertSame('video.thumbnails', $rendition->operation);
        $this->assertSame(['count' => 5], $rendition->options);
        $this->assertSame('h264', $rendition->from);
        $this->assertSame([ProbeKind::Video], $rendition->when->kinds);
        $this->assertSame(2.0, $rendition->when->minDuration);
        $this->assertTrue($rendition->keep);
        $this->assertSame('media', $rendition->queue);
        $this->assertSame('thumbnail', $rendition->role);
    }

    #[Test]
    public function its_role_is_main_by_default(): void
    {
        $this->assertSame('main', Rendition::fromArray('h264', ['op' => 'video.transcode'])->role);
    }

    #[Test]
    public function it_is_stored_as_json_and_read_back(): void
    {
        $definition = [
            'op' => 'video.transcode',
            'codec' => 'h264',
            'max_width' => 1920,
            'from' => 'normalized',
            'when' => ['kind' => ['video'], 'max_duration' => 600.0],
            'keep' => false,
            'queue' => 'media',
            'role' => 'preview',
        ];

        $rendition = Rendition::fromArray('preview', $definition);
        $stored = json_decode((string) json_encode($rendition->toArray()), true);
        $this->assertIsArray($stored);

        $this->assertEquals($rendition, Rendition::fromArray('preview', $stored));
        $this->assertSame(['op' => 'video.transcode'], Rendition::fromArray('h264', ['op' => 'video.transcode'])->toArray());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validHandles(): iterable
    {
        yield 'letters' => ['h264'];
        yield 'dash and underscore' => ['web-video_hd'];
        yield 'uppercase' => ['HLS'];
        yield '64 characters' => ['a'.str_repeat('b', 63)];
    }

    #[Test]
    #[DataProvider('validHandles')]
    public function a_handle_starts_with_a_letter(string $handle): void
    {
        $this->assertTrue(Rendition::isValidHandle($handle));
        $this->assertSame($handle, Rendition::fromArray($handle, ['op' => 'video.transcode'])->name);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidHandles(): iterable
    {
        yield 'a digit first' => ['1080p'];
        yield 'an underscore first' => ['_tmp'];
        yield 'a dot (1.x groups)' => ['thumbnails.0'];
        yield '65 characters' => ['a'.str_repeat('b', 64)];
        yield 'a space' => ['web video'];
        yield 'empty' => [''];
        yield 'the original' => ['original'];
    }

    #[Test]
    #[DataProvider('invalidHandles')]
    public function other_handles_are_refused(string $handle): void
    {
        $this->assertFalse(Rendition::isValidHandle($handle));

        $this->expectException(InvalidPipeline::class);
        Rendition::fromArray($handle, ['op' => 'video.transcode']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidDefinitions(): iterable
    {
        yield 'no operation' => [['codec' => 'h264'], 'no operation'];
        yield 'a type in when.kind' => [['op' => 'video.transcode', 'when' => ['kind' => 'captions']], 'not a probe kind'];
        yield 'an unknown condition' => [['op' => 'video.transcode', 'when' => ['type' => 'video']], 'unknown condition'];
        yield 'a negative duration' => [['op' => 'video.transcode', 'when' => ['min_duration' => -1]], 'when.min_duration'];
        yield 'keep as a string' => [['op' => 'video.transcode', 'keep' => 'no'], '`keep`'];
        yield 'reads from itself' => [['op' => 'video.transcode', 'from' => 'h264'], 'reads from itself'];
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    #[Test]
    #[DataProvider('invalidDefinitions')]
    public function invalid_definitions_are_refused_with_a_clear_message(array $definition, string $message): void
    {
        $this->expectException(InvalidPipeline::class);
        $this->expectExceptionMessage($message);

        Rendition::fromArray('h264', $definition);
    }

    #[Test]
    public function the_definition_hash_ignores_the_order_of_options(): void
    {
        $a = Rendition::fromArray('h264', ['op' => 'video.transcode', 'codec' => 'h264', 'max_width' => 1920, 'audio' => ['codec' => 'aac', 'bitrate' => 128]]);
        $b = Rendition::fromArray('h264', ['max_width' => 1920, 'audio' => ['bitrate' => 128, 'codec' => 'aac'], 'codec' => 'h264', 'op' => 'video.transcode']);

        $this->assertSame($a->definitionHash(), $b->definitionHash());
    }

    #[Test]
    public function the_definition_hash_changes_with_what_the_output_depends_on(): void
    {
        $base = Rendition::fromArray('h264', ['op' => 'video.transcode', 'codec' => 'h264']);

        $this->assertNotSame($base->definitionHash(), Rendition::fromArray('h264', ['op' => 'video.transcode', 'codec' => 'h265'])->definitionHash());
        $this->assertNotSame($base->definitionHash(), Rendition::fromArray('h264', ['op' => 'video.hls', 'codec' => 'h264'])->definitionHash());
        $this->assertNotSame($base->definitionHash(), Rendition::fromArray('h264', ['op' => 'video.transcode', 'codec' => 'h264', 'from' => 'normalized'])->definitionHash());
        $this->assertSame($base->definitionHash(), Rendition::fromArray('h264', ['op' => 'video.transcode', 'codec' => 'h264', 'queue' => 'media', 'role' => 'main', 'when' => ['kind' => 'video']])->definitionHash());
    }

    #[Test]
    public function options_of_a_run_replace_the_options_of_the_same_name(): void
    {
        $rendition = Rendition::fromArray('h264', ['op' => 'video.transcode', 'codec' => 'h264', 'max_width' => 1920, 'filters' => ['a' => 1, 'b' => 2], 'role' => 'edit']);

        $merged = $rendition->withOptions(['max_width' => 1280, 'filters' => ['a' => 3], 'title' => 'Épisode 4']);

        $this->assertSame(['codec' => 'h264', 'max_width' => 1280, 'filters' => ['a' => 3], 'title' => 'Épisode 4'], $merged->options, 'The merge is shallow.');
        $this->assertSame(['video.transcode', 'edit'], [$merged->operation, $merged->role]);
        $this->assertSame(1920, $rendition->options['max_width'], 'The rendition is not changed.');
        $this->assertNotSame($rendition->definitionHash(), $merged->definitionHash());
        $this->assertSame($merged->definitionHash(), Rendition::fromArray('h264', $merged->toArray())->definitionHash(), 'The snapshot reads back the merged definition.');
        $this->assertSame($rendition, $rendition->withOptions([]));
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function invalidRunOptions(): iterable
    {
        yield 'a list' => [[1280]];
        yield 'an empty name' => [['' => 1280]];
        foreach (['op', 'from', 'when', 'keep', 'queue', 'role'] as $key) {
            yield 'the rendition\'s own key '.$key => [[$key => 'value']];
        }
        yield 'an object' => [['max_width' => new \stdClass]];
        yield 'an object in an array' => [['filters' => ['a' => new \stdClass]]];
        yield 'infinity' => [['max_width' => INF]];
        yield 'a closure' => [['max_width' => fn () => 1]];
        yield 'invalid UTF-8' => [['title' => "\xC3\x28"]];
    }

    /**
     * @param  array<array-key, mixed>  $options
     */
    #[Test]
    #[DataProvider('invalidRunOptions')]
    public function invalid_options_of_a_run_throw(array $options): void
    {
        $this->expectException(InvalidPipeline::class);

        Rendition::fromArray('h264', ['op' => 'video.transcode'])->withOptions($options);
    }
}
