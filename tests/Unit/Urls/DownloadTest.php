<?php

namespace Folklore\Mediatheque\Tests\Unit\Urls;

use Folklore\Mediatheque\Contracts\UrlGenerator;
use Folklore\Mediatheque\Testing\Models\FakeFile;
use Folklore\Mediatheque\Testing\Models\FakeMedia;
use Folklore\Mediatheque\Urls\Download;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class DownloadTest extends TestCase
{
    private function file(?string $name, ?string $path = 'video/med_1/original.mov'): FakeFile
    {
        return new FakeFile(
            media: new FakeMedia(1, 'med_1', 'video', null),
            urls: $this->createStub(UrlGenerator::class),
            handle: 'original',
            source: 'spaces',
            path: $path,
            name: $name,
        );
    }

    #[Test]
    public function false_shows_the_file(): void
    {
        $this->assertNull(Download::of($this->file('clip.mov'), false));
    }

    #[Test]
    public function true_takes_the_file_name_cleaned_or_the_last_segment_of_its_path(): void
    {
        $this->assertSame('clip.mov', Download::of($this->file('clip.mov'), true)?->filename);
        $this->assertSame('a_b_c.mov', Download::of($this->file("a/b\r\nc.mov"), true)?->filename);
        $this->assertSame('original.mov', Download::of($this->file(null), true)?->filename);
        $this->assertSame('original.mov', Download::of($this->file('..'), true)?->filename);
        $this->assertSame('download', Download::of($this->file(null, null), true)?->filename);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function dispositions(): iterable
    {
        yield 'ascii' => ['clip.mp4', "attachment; filename=\"clip.mp4\"; filename*=UTF-8''clip.mp4"];
        yield 'accents' => ['Été.mp4', "attachment; filename=\"Ete.mp4\"; filename*=UTF-8''%C3%89t%C3%A9.mp4"];
        yield 'quotes, backslash and percent' => ['a "b" 100%.mp4', "attachment; filename=\"a _b_ 100_.mp4\"; filename*=UTF-8''a%20%22b%22%20100%25.mp4"];
        yield 'nothing in ascii' => ['🎬.mp4', "attachment; filename=\"download.mp4\"; filename*=UTF-8''%F0%9F%8E%AC.mp4"];
    }

    #[Test]
    #[DataProvider('dispositions')]
    public function the_content_disposition_has_the_name_in_utf8_and_an_ascii_fallback(string $name, string $expected): void
    {
        $this->assertSame($expected, Download::of($this->file(null), $name)?->contentDisposition());
    }

    #[Test]
    public function a_name_given_by_the_application_is_not_cleaned_but_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Download::of($this->file('clip.mov'), '../clip.mov');
    }

    #[Test]
    public function true_on_a_media_takes_its_name_with_the_extension_of_the_file_it_serves(): void
    {
        $file = $this->file('h264.mp4', 'video/med_1/run_1/h264.mp4');
        $media = fn (?string $name) => new FakeMedia(1, 'med_1', 'video', $name);

        $this->assertSame('episode-1.mp4', Download::ofMedia($media('episode-1'), $file, true)?->filename);
        $this->assertSame('Episode 1.MP4', Download::ofMedia($media('Episode 1.MP4'), $file, true)?->filename, 'A name that ends with the extension keeps it.');
        $this->assertSame('a_b.mp4', Download::ofMedia($media('a/b'), $file, true)?->filename, 'A name from a client is cleaned.');
        $this->assertSame('h264.mp4', Download::ofMedia($media(null), $file, true)?->filename, 'Without a name, the file\'s.');
        $this->assertSame('h264.mp4', Download::ofMedia($media(' '), $file, true)?->filename);
        $this->assertSame('Step 3.mov', Download::ofMedia($media('episode-1'), $file, 'Step 3.mov')?->filename);
        $this->assertNull(Download::ofMedia($media('episode-1'), $file, false));
        $this->assertSame('episode-1', Download::ofMedia($media('episode-1'), $this->file(null, 'files/med_1/original'), true)?->filename, 'A file without an extension adds none.');
    }
}
