<?php

namespace Folklore\Mediatheque\Tests\Feature;

use Folklore\Mediatheque\Contracts\Models\File as FileContract;
use Folklore\Mediatheque\Contracts\Models\Media as MediaContract;
use Folklore\Mediatheque\Jobs\Video\Thumbnails;
use Folklore\Mediatheque\Support\Pipeline;
use Folklore\Mediatheque\Tests\Support\Jobs\PassthroughJob;
use Folklore\Mediatheque\Tests\TestCase;
use Illuminate\Support\Str;

class PipelineTemporaryFilesTest extends TestCase
{
    protected $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--database' => 'testbench']);

        $this->root = sys_get_temp_dir().'/mediatheque_test_'.Str::random(8);
        app('files')->makeDirectory($this->root);
    }

    protected function tearDown(): void
    {
        app('files')->deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_a_local_source_keeps_only_stored_files()
    {
        config()->set('mediatheque.sources.public.path', $this->root);

        $this->runThumbnailsPipeline();

        $this->assertEquals($this->storedPaths(), $this->filesOnDisk());
    }

    public function test_a_filesystem_source_keeps_only_stored_files_and_cleans_temporary_files()
    {
        config()->set('filesystems.disks.mediatheque_test', [
            'driver' => 'local',
            'root' => $this->root,
        ]);
        config()->set('mediatheque.sources.cloud.disk', 'mediatheque_test');
        config()->set('mediatheque.source', 'cloud');
        $temporaryFiles = $this->temporaryFiles();

        $this->runThumbnailsPipeline();

        $this->assertEquals($this->storedPaths(), $this->filesOnDisk());
        $this->assertEquals($temporaryFiles, $this->temporaryFiles());
    }

    public function test_the_stored_original_is_never_deleted()
    {
        config()->set('mediatheque.sources.public.path', $this->root);
        $media = app(MediaContract::class);
        $media->withoutTypePipeline();
        $media->setOriginalFile(public_path('image.jpg'));
        $original = $media->getOriginalFile();

        $pipeline = $media->runPipeline(Pipeline::fromJobs([
            'copy' => PassthroughJob::class,
        ]))->fresh();

        $this->assertTrue($pipeline->ended);
        $this->assertTrue($original->getSource()->exists($original->path));
        $this->assertEquals($this->storedPaths(), $this->filesOnDisk());
    }

    protected function runThumbnailsPipeline(): void
    {
        $media = app(MediaContract::class);
        $media->withoutTypePipeline();
        $media->setOriginalFile(public_path('test.mp4'));

        $pipeline = $media->runPipeline(Pipeline::fromJobs([
            'thumbnails' => [
                'job' => Thumbnails::class,
                'count' => 2,
            ],
        ]))->fresh();

        $this->assertTrue($pipeline->ended);
        $this->assertCount(3, $media->fresh()->files);
    }

    protected function storedPaths(): array
    {
        return app(FileContract::class)
            ->newQuery()
            ->pluck('path')
            ->map(fn ($path) => ltrim($path, '/'))
            ->sort()
            ->values()
            ->all();
    }

    protected function filesOnDisk(): array
    {
        return collect(app('files')->allFiles($this->root))
            ->map(fn ($file) => $file->getRelativePathname())
            ->sort()
            ->values()
            ->all();
    }

    protected function temporaryFiles(): array
    {
        return glob(sys_get_temp_dir().'/mediatheque_pipeline_job*') ?: [];
    }
}
