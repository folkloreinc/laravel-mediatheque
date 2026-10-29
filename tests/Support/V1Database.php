<?php

namespace Folklore\Mediatheque\Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Rows as mediatheque 1.x wrote them, in the 1.x tables
 * (tests/fixtures/v1/migrations): media of each type with their files,
 * metadata and pipeline runs, and the leftovers a project accumulates. The
 * data is generic: no project's.
 *
 * - 1: an image, attached to a page under `cover`
 * - 2: a video whose run completed: h264, webm and five thumbnails
 * - 3: a video whose last run failed (hevc), after one that completed
 * - 4: an audio file with its waveform (`thumbnails`, a single file)
 * - 5: a document with a thumbnail per page
 * - 6: a font, whose run made web fonts (no 2.0 operation)
 *
 * Leftovers: metadata, an attachment and a run of a deleted media (99), a
 * job of a deleted run, a file that nothing attaches, a file attached to a
 * page through `files_pivot`, and a rendition whose handle breaks the 2.0
 * rule (`1080p`).
 */
final class V1Database
{
    public const string MEDIA = 'Folklore\\Mediatheque\\Models\\Media';

    public const string FILE = 'Folklore\\Mediatheque\\Models\\File';

    private const string JOBS = 'Folklore\\Mediatheque\\Jobs\\';

    private int $files = 0;

    private int $runs = 0;

    public function __construct(
        private readonly string $prefix = 'mediatheque_',
        private readonly string $mediaType = self::MEDIA,
    ) {}

    public function seed(): void
    {
        $this->media(1, 'image', '2017-09-01 10:00:00', ['width' => 1200, 'height' => 800], [
            'original' => ['image/jpeg', 'jpg', ['width' => 1200, 'height' => 800]],
        ]);
        $this->insert('medias_pivot', ['media_id' => 1, 'morphable_type' => Page::class, 'morphable_id' => 1, 'handle' => 'cover', 'order' => 0]);

        $thumbnails = [];
        foreach (range(0, 4) as $index) {
            $thumbnails['thumbnails.'.$index] = ['image/jpeg', 'jpg', ['width' => 640, 'height' => 360]];
        }
        $this->media(2, 'video', '2018-03-01 10:00:00', ['width' => 1920, 'height' => 1080, 'duration' => 12.5], [
            'original' => ['video/quicktime', 'mov', ['width' => 1920, 'height' => 1080, 'duration' => 12.5]],
            'h264' => ['video/mp4', 'mp4', ['width' => 1920, 'height' => 1080, 'duration' => 12.5]],
            'webm' => ['video/webm', 'webm', []],
            '1080p' => ['video/mp4', 'mp4', []],
            ...$thumbnails,
        ]);
        $this->run(2, 'video', self::videoJobs(), ended: true);

        $this->media(3, 'video', '2018-04-01 10:00:00', ['width' => 1280, 'height' => 720, 'duration' => 3.0], [
            'original' => ['video/mp4', 'mp4', []],
            'h264' => ['video/mp4', 'mp4', []],
        ]);
        $this->run(3, 'video', self::videoJobs(), ended: true);
        $this->run(3, 'video', self::videoJobs(), failed: 'hevc');

        $this->media(4, 'audio', '2018-05-01 10:00:00', ['duration' => 61.2], [
            'original' => ['audio/mpeg', 'mp3', ['duration' => 61.2]],
            'thumbnails' => ['image/png', 'png', []],
        ]);
        $this->run(4, 'audio', ['thumbnails' => ['job' => self::JOBS.'Audio\\Thumbnails', 'width' => 1200, 'height' => 400, 'color' => '000000']], ended: true);

        $this->media(5, 'document', '2018-06-01 10:00:00', ['pages_count' => 2], [
            'original' => ['application/pdf', 'pdf', []],
            'thumbnails.0' => ['image/jpeg', 'jpg', []],
            'thumbnails.1' => ['image/jpeg', 'jpg', []],
        ]);
        $this->run(5, 'document', ['thumbnails' => ['job' => self::JOBS.'Document\\Thumbnails', 'count' => 'all', 'format' => 'jpeg']], ended: true);

        $this->media(6, 'font', '2018-07-01 10:00:00', ['font_family_name' => 'Generic Sans', 'colors' => ['#000000', '#ffffff']], [
            'original' => ['font/otf', 'otf', []],
        ]);
        $this->run(6, 'font', ['webfonts' => self::JOBS.'Font\\WebFonts'], ended: true);

        // A media deleted without its rows
        $this->metadata($this->mediaType, 99, 'width', 100);
        $this->insert('medias_pivot', ['media_id' => 99, 'morphable_type' => Page::class, 'morphable_id' => 1, 'handle' => null, 'order' => 0]);
        $this->run(99, 'video', self::videoJobs(), ended: true);
        $this->insert('pipelines_jobs', ['pipeline_id' => 999, 'name' => 'h264', 'definition' => '{"job":"'.addslashes(self::JOBS.'Video\\H264').'"}', 'ended' => true]);

        // A file that nothing attaches, and one a page attaches
        $this->file(null, 'orphan', 'image/jpeg', 'jpg');
        $page = $this->file(null, 'attachment', 'application/pdf', 'pdf');
        $this->insert('files_pivot', ['file_id' => $page, 'morphable_type' => Page::class, 'morphable_id' => 1, 'handle' => 'attachment', 'order' => 0]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, array{0: string, 1: string, 2: array<string, mixed>}>  $files  By 1.x handle: mime, extension, metadata
     */
    private function media(int $id, string $type, string $createdAt, array $metadata, array $files): void
    {
        $this->insert('medias', ['id' => $id, 'type' => $type, 'name' => ucfirst($type).' '.$id, 'created_at' => $createdAt, 'updated_at' => $createdAt]);

        foreach ($metadata as $name => $value) {
            $this->metadata($this->mediaType, $id, $name, $value);
        }

        $order = 0;
        foreach ($files as $handle => [$mime, $extension, $fileMetadata]) {
            // 1.x wrote the handle on the original's row, and on the pivot
            // for the files of renditions
            $file = $this->file($handle === 'original' ? 'original' : null, $type.'/'.$id.'-'.str_replace('.', '-', $handle), $mime, $extension);
            $this->insert('files_pivot', ['file_id' => $file, 'morphable_type' => $this->mediaType, 'morphable_id' => $id, 'handle' => $handle, 'order' => $order++]);

            foreach ($fileMetadata as $name => $value) {
                $this->metadata(self::FILE, $file, $name, $value);
            }
        }
    }

    private function file(?string $handle, string $name, string $mime, string $extension): int
    {
        $this->files++;
        $this->insert('files', [
            'id' => $this->files,
            'handle' => $handle,
            'source' => 'public',
            'name' => basename($name).'.'.$extension,
            'path' => 'media/'.$name.'.'.$extension,
            'mime' => $mime,
            'size' => 1000 + $this->files,
        ]);

        return $this->files;
    }

    private function metadata(string $type, int $id, string $name, mixed $value): void
    {
        $kind = match (true) {
            is_int($value) => 'integer',
            is_float($value) => 'float',
            is_array($value) => 'json',
            default => 'string',
        };

        $this->insert('metadatas', [
            'morphable_type' => $type,
            'morphable_id' => $id,
            'name' => $name,
            'type' => $kind,
            'value_'.$kind => is_array($value) ? json_encode($value) : $value,
        ]);
    }

    /**
     * @param  array<string, mixed>  $jobs
     */
    private function run(int $media, string $name, array $jobs, bool $ended = false, ?string $failed = null): void
    {
        $this->runs++;
        $this->insert('pipelines', [
            'id' => $this->runs,
            'name' => $name,
            'pipelinable_type' => $this->mediaType,
            'pipelinable_id' => $media,
            'definition' => self::serializedPipeline($name, $jobs),
            'started' => false,
            'ended' => $ended,
            'failed' => $failed !== null,
            'failed_exception' => null,
            'started_at' => '2018-08-01 10:00:00',
            'ended_at' => '2018-08-01 10:05:00',
            'created_at' => '2018-08-01 10:00:00',
        ]);

        foreach ($jobs as $job => $definition) {
            $definition = is_array($definition) ? $definition : ['job' => $definition];
            $this->insert('pipelines_jobs', [
                'pipeline_id' => $this->runs,
                'name' => $job,
                'definition' => json_encode([...$definition, 'from_file' => 'original', 'queue' => true]),
                'ended' => $job !== $failed,
                'failed' => $job === $failed,
                'failed_exception' => $job === $failed ? 'RuntimeException: ffmpeg failed' : null,
                'started_at' => '2018-08-01 10:00:00',
                'created_at' => '2018-08-01 10:00:00',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function videoJobs(): array
    {
        return [
            'h264' => self::JOBS.'Video\\H264',
            'webm' => self::JOBS.'Video\\WebM',
            'hevc' => self::JOBS.'Video\\HEVC',
            'thumbnails' => ['job' => self::JOBS.'Video\\Thumbnails', 'count' => 5, 'in_middle' => true],
        ];
    }

    /**
     * A pipeline definition as 1.x stored it: a serialized
     * `Folklore\Mediatheque\Support\Pipeline`, a class 2.0 doesn't have.
     *
     * @param  array<string, mixed>  $jobs
     */
    public static function serializedPipeline(string $name, array $jobs): string
    {
        $serialized = serialize(new V1SerializedPipeline($name, $jobs));
        $class = 'Folklore\\Mediatheque\\Support\\Pipeline';

        return str_replace(
            sprintf('O:%d:"%s"', strlen(V1SerializedPipeline::class), V1SerializedPipeline::class),
            sprintf('O:%d:"%s"', strlen($class), $class),
            $serialized,
        );
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function insert(string $table, array $values): void
    {
        DB::table($this->prefix.$table)->insert($values);
    }
}
