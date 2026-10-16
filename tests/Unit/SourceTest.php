<?php

namespace Folklore\Mediatheque\Tests\Unit;

use Folklore\Mediatheque\Ingest\Source;
use Folklore\Mediatheque\Ingest\SourceKind;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SourceTest extends TestCase
{
    #[Test]
    public function it_reads_from_an_upload_and_keeps_the_client_name(): void
    {
        $upload = UploadedFile::fake()->create('Episode 1.mov', 10, 'video/quicktime');
        $source = Source::from($upload);

        $this->assertSame(SourceKind::Upload, $source->kind);
        $this->assertSame($upload, $source->upload);
        $this->assertSame('Episode 1.mov', $source->filename);
    }

    #[Test]
    public function it_reads_from_a_local_path(): void
    {
        $source = Source::from(__DIR__.'/../fixtures/media/image.jpg');

        $this->assertSame(SourceKind::Path, $source->kind);
        $this->assertSame('image.jpg', $source->filename);
    }

    #[Test]
    public function it_reads_from_a_stream(): void
    {
        $stream = fopen(__DIR__.'/../fixtures/media/image.svg', 'r');
        $this->assertIsResource($stream);

        $source = Source::from($stream);

        $this->assertSame(SourceKind::Stream, $source->kind);
        $this->assertSame('image.svg', $source->filename);
        fclose($stream);
    }

    #[Test]
    public function it_reads_from_a_disk(): void
    {
        $source = Source::disk('s3', '/uploads/abc.mov', 'Clip.mov');

        $this->assertSame(SourceKind::Disk, $source->kind);
        $this->assertSame('s3', $source->disk);
        $this->assertSame('uploads/abc.mov', $source->path);
        $this->assertSame('Clip.mov', $source->filename);
        $this->assertSame(Source::from($source), $source);
    }

    #[Test]
    public function a_disk_path_stays_on_its_disk(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Source::disk('s3', 'uploads/../../secrets.txt');
    }

    #[Test]
    public function it_reads_from_a_url(): void
    {
        $source = Source::url('https://example.com/media/video.mp4?token=abc');

        $this->assertSame(SourceKind::Url, $source->kind);
        $this->assertSame('https://example.com/media/video.mp4?token=abc', $source->url);
        $this->assertSame('video.mp4', $source->filename);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidUrls(): iterable
    {
        yield 'file' => ['file:///etc/passwd'];
        yield 'ftp' => ['ftp://example.com/video.mp4'];
        yield 'gopher' => ['gopher://127.0.0.1:6379/_INFO'];
        yield 'no host' => ['https:///video.mp4'];
        yield 'not a URL' => ['video.mp4'];
    }

    #[Test]
    #[DataProvider('invalidUrls')]
    public function it_only_imports_http_urls(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);

        Source::url($url);
    }

    #[Test]
    public function it_refuses_anything_else(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Source::from(['path' => 'video.mp4']);
    }
}
