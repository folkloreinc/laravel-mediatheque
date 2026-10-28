<?php

namespace Folklore\Mediatheque\Tests\Feature\Ingest;

use Folklore\Mediatheque\Contracts\Ingestor;
use Folklore\Mediatheque\Enums\MediaStatus;
use Folklore\Mediatheque\Enums\RejectionReason;
use Folklore\Mediatheque\Enums\Visibility;
use Folklore\Mediatheque\Events\IngestRejected;
use Folklore\Mediatheque\Events\MediaCreated;
use Folklore\Mediatheque\Exceptions\FileRejected;
use Folklore\Mediatheque\Exceptions\InvalidPath;
use Folklore\Mediatheque\Facades\Mediatheque;
use Folklore\Mediatheque\Ingest\IngestOptions;
use Folklore\Mediatheque\Ingest\MediaIngestor;
use Folklore\Mediatheque\Ingest\Source;
use Folklore\Mediatheque\Jobs\ProbeMedia;
use Folklore\Mediatheque\Models\File;
use Folklore\Mediatheque\Models\Media;
use Folklore\Mediatheque\Tests\DatabaseTestCase;
use Folklore\Mediatheque\Tests\Support\GpsImages;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;

class MediaIngestorTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Bus::fake([ProbeMedia::class]);
    }

    /**
     * An upload whose content is a fixture, with the name and Content-Type a
     * client chose.
     */
    private function upload(string $fixture, string $name, string $clientMime = 'application/octet-stream'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upload');
        copy(self::fixturePath($fixture), (string) $path);

        return new UploadedFile((string) $path, $name, $clientMime, null, true);
    }

    private function uploadWithContents(string $contents, string $name, string $clientMime = 'application/octet-stream'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upload');
        file_put_contents((string) $path, $contents);

        return new UploadedFile((string) $path, $name, $clientMime, null, true);
    }

    /**
     * @param  callable(): mixed  $ingest
     */
    private function assertRejected(RejectionReason $reason, callable $ingest): void
    {
        try {
            $ingest();
            $this->fail('The file was not rejected.');
        } catch (FileRejected $exception) {
            $this->assertSame($reason, $exception->reason);
        }
    }

    private function assertNothingStored(): void
    {
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame(0, Media::query()->withTrashed()->count());
        $this->assertSame(0, File::query()->count());
    }

    #[Test]
    public function the_package_binds_its_ingestor(): void
    {
        $this->assertInstanceOf(MediaIngestor::class, app(Ingestor::class));
    }

    #[Test]
    public function it_ingests_an_upload_and_queues_its_probe(): void
    {
        Event::fake([MediaCreated::class]);

        $media = Mediatheque::ingest($this->upload('media/test.mp4', 'My clip.mp4', 'video/mp4'), name: 'Episode 1', data: ['credit' => 'Me']);

        $this->assertInstanceOf(Media::class, $media);
        $this->assertSame(['video', 'Episode 1', MediaStatus::Pending, Visibility::Public], [$media->type(), $media->name(), $media->status(), $media->visibility()]);
        $this->assertSame(['credit' => 'Me'], $media->data);
        $this->assertSame([
            'filename' => 'My clip.mp4',
            'mime' => 'video/mp4',
            'extension' => 'mp4',
            'size' => 49685,
            'checksum' => hash_file('sha256', self::fixturePath('media/test.mp4')),
        ], $media->metadata());

        $original = $media->original();
        $this->assertInstanceOf(File::class, $original);
        $this->assertSame('video/'.$media->uid().'/original.mp4', $original->path());
        $this->assertSame(['public', 'video/mp4', 49685, 'My clip.mp4'], [$original->source(), $original->mime(), $original->size(), $original->name]);
        Storage::disk('public')->assertExists((string) $original->path());
        $this->assertSame(1, Media::query()->count());

        Event::assertDispatched(MediaCreated::class, fn (MediaCreated $event) => $event->media->uid() === $media->uid());
        Bus::assertDispatched(ProbeMedia::class, fn (ProbeMedia $job) => $job->mediaId === $media->id);
    }

    #[Test]
    public function the_extension_comes_from_the_content_not_the_client(): void
    {
        $media = Mediatheque::ingest($this->upload('media/image.jpg', 'shell.php', 'application/x-php'));
        $polyglot = Mediatheque::ingest($this->uploadWithContents(
            (string) file_get_contents(self::fixturePath('media/image.jpg')).'<?php echo "hello"; ?>',
            'photo.php.jpg',
        ));

        $this->assertSame('image', $media->type());
        $this->assertStringEndsWith('/original.jpg', (string) $media->original()?->path());
        $this->assertSame('image/jpeg', $media->original()?->mime());
        $this->assertStringEndsWith('/original.jpg', (string) $polyglot->original()?->path());
        $this->assertSame('shell.php', $media->name());
    }

    #[Test]
    public function the_type_ignores_the_client_mime_type(): void
    {
        $media = Mediatheque::ingest($this->upload('media/test.wav', 'song.mp4', 'video/mp4'));

        $this->assertSame('audio', $media->type());
        $this->assertStringEndsWith('.wav', (string) $media->original()?->path());
    }

    /**
     * @return iterable<string, array{string, string, RejectionReason}>
     */
    public static function rejectedFiles(): iterable
    {
        yield 'html' => ['<!DOCTYPE html><html><body><script>alert(1)</script></body></html>', 'page.jpg', RejectionReason::UnknownType];
        yield 'php' => ["<?php\nsystem(\$_GET['c']);\n", 'image.png', RejectionReason::UnknownType];
        yield 'text' => ['Just some text', 'notes.mp4', RejectionReason::UnknownType];
        yield 'invalid svg' => ['<svg xmlns="http://www.w3.org/2000/svg"><rect></svg>', 'icon.svg', RejectionReason::InvalidSvg];
    }

    #[Test]
    #[DataProvider('rejectedFiles')]
    public function files_of_no_type_are_rejected_and_nothing_is_stored(string $contents, string $name, RejectionReason $reason): void
    {
        $this->assertRejected($reason, fn () => Mediatheque::ingest($this->uploadWithContents($contents, $name, 'image/jpeg')));

        $this->assertNothingStored();
        Bus::assertNotDispatched(ProbeMedia::class);
    }

    #[Test]
    public function blocked_mimes_are_never_stored_even_when_a_type_accepts_them(): void
    {
        config(['mediatheque.types.document.mimes' => ['application/pdf', 'text/*']]);

        $this->assertRejected(RejectionReason::MimeNotAllowed, fn () => Mediatheque::ingest($this->uploadWithContents('<!DOCTYPE html><html><body>Hi</body></html>', 'page.html')));

        $this->assertNothingStored();
    }

    #[Test]
    public function files_larger_than_their_type_allows_are_rejected(): void
    {
        config(['mediatheque.types.image.max_size' => 1000]);

        $this->assertRejected(RejectionReason::TooLarge, fn () => Mediatheque::ingest($this->upload('media/image.jpg', 'photo.jpg')));

        $this->assertNothingStored();
    }

    #[Test]
    public function a_rejected_file_dispatches_ingest_rejected(): void
    {
        Event::fake([IngestRejected::class]);
        config(['mediatheque.types.image.max_size' => 1000]);

        $this->assertRejected(RejectionReason::TooLarge, fn () => Mediatheque::ingest($this->upload('media/image.jpg', 'photo.jpg')));

        Event::assertDispatchedTimes(IngestRejected::class, 1);
        Event::assertDispatched(IngestRejected::class, fn (IngestRejected $event) => $event->reason === RejectionReason::TooLarge
            && $event->filename === 'photo.jpg'
            && str_contains($event->message, 'photo.jpg'));
    }

    #[Test]
    public function svg_files_are_sanitized_before_they_are_stored(): void
    {
        $media = Mediatheque::ingest($this->uploadWithContents(
            '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(2)</script><rect width="10" height="10"/></svg>',
            'icon.svg',
        ));

        $stored = (string) Storage::disk('public')->get((string) $media->original()?->path());
        $this->assertStringEndsWith('.svg', (string) $media->original()?->path());
        $this->assertStringNotContainsString('alert', $stored);
        $this->assertStringContainsString('<rect', $stored);
        $original = $media->original();
        $this->assertNotNull($original);
        $this->assertSame(strlen($stored), $original->size());
        $this->assertSame(hash('sha256', $stored), $original->checksum());
    }

    #[Test]
    public function it_ingests_a_path_and_a_stream(): void
    {
        $fromPath = Mediatheque::ingest(self::fixturePath('media/image.jpg'));
        $stream = fopen(self::fixturePath('media/test.wav'), 'rb');
        $this->assertNotFalse($stream);
        $fromStream = Mediatheque::ingest($stream);

        $this->assertSame(['image', 'image.jpg'], [$fromPath->type(), $fromPath->name()]);
        $this->assertSame(['audio', 'test.wav'], [$fromStream->type(), $fromStream->name()]);
        $this->assertSame([], glob(sys_get_temp_dir().'/mediatheque/*') ?: [], 'No temporary file is left.');
    }

    #[Test]
    public function a_file_on_the_same_disk_is_copied_on_the_server_and_probed_for_its_checksum(): void
    {
        $this->useMemoryDisk('uploads');
        config(['mediatheque.source' => 'uploads']);
        Storage::disk('uploads')->put('incoming/upl_1', (string) file_get_contents(self::fixturePath('media/test.mp4')));

        $media = Mediatheque::ingest(Source::disk('uploads', 'incoming/upl_1', 'clip.mov'));

        $original = $media->original();
        $this->assertSame(['video', 'clip.mov', 'uploads', 49685, null], [$media->type(), $media->name(), $original?->source(), $original?->size(), $original?->checksum()]);
        $this->assertSame(Storage::disk('uploads')->get('incoming/upl_1'), Storage::disk('uploads')->get((string) $original?->path()));
        $this->assertArrayNotHasKey('checksum', $media->metadata());
    }

    #[Test]
    public function a_file_on_another_disk_is_copied_with_the_media_visibility(): void
    {
        $this->useMemoryDisk('uploads');
        Storage::disk('uploads')->put('incoming/photo', (string) file_get_contents(self::fixturePath('media/image.jpg')));

        $media = Mediatheque::ingest(Source::disk('uploads', 'incoming/photo', 'photo.jpg'), visibility: Visibility::Private);

        $path = (string) $media->original()?->path();
        Storage::disk('public')->assertExists($path);
        $this->assertSame('private', Storage::disk('public')->getVisibility($path));
        $this->assertSame(Visibility::Private, $media->visibility());
    }

    #[Test]
    #[RequiresPhpExtension('gd')]
    public function an_image_is_stored_without_its_location(): void
    {
        $image = GpsImages::jpeg();

        $media = Mediatheque::ingest($this->uploadWithContents($image, 'photo.jpg'));

        $stored = Storage::disk('public')->get((string) $media->original()?->path());
        $this->assertNotNull($stored);
        $this->assertNotSame($image, $stored);
        $this->assertStringNotContainsString(GpsImages::DATUM, $stored);
        $this->assertStringNotContainsString(GpsImages::XMP_LATITUDE, $stored);
        $this->assertStringContainsString(GpsImages::MAKE, $stored);
        $this->assertSame([strlen($image), hash('sha256', $stored)], [$media->original()?->size(), $media->original()?->checksum()]);
        $this->assertSame(hash('sha256', $stored), $media->metadata('checksum'));
    }

    #[Test]
    #[RequiresPhpExtension('gd')]
    public function an_image_on_a_disk_is_stored_without_its_location(): void
    {
        $this->useMemoryDisk('uploads');
        Storage::disk('uploads')->put('incoming/photo', GpsImages::png());

        $media = Mediatheque::ingest(Source::disk('uploads', 'incoming/photo', 'photo.png'));

        $stored = (string) Storage::disk('public')->get((string) $media->original()?->path());
        $this->assertStringNotContainsString(GpsImages::DATUM, $stored);
        $this->assertSame(hash('sha256', $stored), $media->original()?->checksum(), 'The image was read whole, so its checksum is known.');
        $this->assertStringContainsString(GpsImages::DATUM, (string) Storage::disk('uploads')->get('incoming/photo'), 'The file on its disk is not changed.');
    }

    #[Test]
    #[RequiresPhpExtension('gd')]
    public function an_uploaded_file_is_not_changed_in_place(): void
    {
        $upload = $this->uploadWithContents(GpsImages::jpeg(), 'photo.jpg');

        Mediatheque::ingest($upload);

        $this->assertStringContainsString(GpsImages::DATUM, (string) file_get_contents((string) $upload->getRealPath()));
    }

    #[Test]
    #[RequiresPhpExtension('gd')]
    public function the_location_is_kept_when_strip_location_is_off(): void
    {
        config(['mediatheque.ingest.images.strip_location' => false]);
        $image = GpsImages::jpeg();

        $media = Mediatheque::ingest($this->uploadWithContents($image, 'photo.jpg'));

        $this->assertSame($image, Storage::disk('public')->get((string) $media->original()?->path()));
    }

    #[Test]
    #[RequiresPhpExtension('gd')]
    public function a_replaced_original_is_stored_without_its_location(): void
    {
        $media = Mediatheque::ingest($this->upload('media/image.jpg', 'photo.jpg'));

        $replaced = Mediatheque::replaceOriginal($media, $this->uploadWithContents(GpsImages::jpeg(), 'new.jpg'));

        $stored = (string) Storage::disk('public')->get((string) $replaced->original()?->path());
        $this->assertStringContainsString(GpsImages::MAKE, $stored);
        $this->assertStringNotContainsString(GpsImages::DATUM, $stored);
    }

    #[Test]
    public function a_missing_file_is_unreadable(): void
    {
        $this->useMemoryDisk('uploads');

        $this->assertRejected(RejectionReason::Unreadable, fn () => Mediatheque::ingest(Source::disk('uploads', 'missing')));
    }

    #[Test]
    public function url_imports_wait_for_the_ssrf_guard(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Mediatheque::ingest(Source::url('https://example.com/video.mp4'));
    }

    #[Test]
    public function application_attributes_are_set_before_the_path_is_built(): void
    {
        Schema::table('mediatheque_medias', fn (Blueprint $table) => $table->unsignedBigInteger('space_id')->nullable());
        config(['mediatheque.file_path_format' => 'spaces/{media.space_id}/{media.uid}/{handle}{index}.{extension}']);

        $media = Mediatheque::ingest($this->upload('media/image.jpg', 'photo.jpg'), attributes: ['space_id' => 7]);

        $this->assertInstanceOf(Media::class, $media);
        $this->assertSame(7, Media::query()->findOrFail($media->id)->getAttribute('space_id'));
        $this->assertStringStartsWith('spaces/7/'.$media->uid().'/', (string) $media->original()?->path());
    }

    #[Test]
    public function a_path_that_needs_a_missing_attribute_stores_nothing(): void
    {
        config(['mediatheque.file_path_format' => 'spaces/{media.space_id}/{media.uid}/{handle}{index}.{extension}']);

        $this->expectException(InvalidPath::class);

        try {
            Mediatheque::ingest($this->upload('media/image.jpg', 'photo.jpg'));
        } finally {
            $this->assertNothingStored();
        }
    }

    #[Test]
    public function the_stored_file_is_deleted_when_saving_fails(): void
    {
        Media::creating(fn () => throw new \RuntimeException('The database is gone.'));

        try {
            Mediatheque::ingest($this->upload('media/image.jpg', 'photo.jpg'));
            $this->fail('Saving did not fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('The database is gone.', $exception->getMessage());
        }

        $this->assertNothingStored();
    }

    #[Test]
    public function the_original_is_stored_on_the_source_of_the_options(): void
    {
        $this->useMemoryDisk('archive');

        $media = app(Ingestor::class)->ingest(Source::path(self::fixturePath('media/image.jpg')), new IngestOptions(source: 'archive'));

        $original = $media->original();
        $this->assertNotNull($original);
        $this->assertSame('archive', $original->source());
        Storage::disk('archive')->assertExists((string) $original->path());
    }
}
