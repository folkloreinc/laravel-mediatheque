<?php

namespace Folklore\Mediatheque\Tests\Feature\Models;

use Folklore\Mediatheque\Contracts\Models\File as FileContract;
use Folklore\Mediatheque\Sources\LocalSource;
use Folklore\Mediatheque\Tests\TestCase;
use Illuminate\Support\Str;

class FileSourceTest extends TestCase
{
    protected $publicRoot;

    protected $cloudRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--database' => 'testbench']);

        $this->publicRoot = sys_get_temp_dir().'/mediatheque_public_'.Str::random(8);
        $this->cloudRoot = sys_get_temp_dir().'/mediatheque_cloud_'.Str::random(8);
        app('files')->makeDirectory($this->publicRoot);
        app('files')->makeDirectory($this->cloudRoot);

        config()->set('mediatheque.sources.public.path', $this->publicRoot);
        config()->set('filesystems.disks.mediatheque_cloud', [
            'driver' => 'local',
            'root' => $this->cloudRoot,
        ]);
        config()->set('mediatheque.sources.cloud.disk', 'mediatheque_cloud');
        config()->set('mediatheque.source', 'public');
    }

    protected function tearDown(): void
    {
        app('files')->deleteDirectory($this->publicRoot);
        app('files')->deleteDirectory($this->cloudRoot);

        parent::tearDown();
    }

    public function test_set_file_stores_it_on_the_requested_source()
    {
        $file = app(FileContract::class);
        $file->setFile(public_path('image.jpg'), ['source' => 'cloud']);

        $this->assertEquals('cloud', $file->source);
        $this->assertFileExists($this->cloudRoot.'/'.$file->path);
        $this->assertFileDoesNotExist($this->publicRoot.'/'.$file->path);
        $this->assertTrue($file->fresh()->getSource()->exists($file->path));
    }

    public function test_set_file_records_the_default_source()
    {
        $file = app(FileContract::class);
        $file->setFile(public_path('image.jpg'));

        $this->assertEquals('public', $file->fresh()->source);

        // Changing the default source later doesn't move existing files
        config()->set('mediatheque.source', 'cloud');
        $this->assertInstanceOf(LocalSource::class, $file->fresh()->getSource());
        $this->assertTrue($file->fresh()->getSource()->exists($file->path));
    }

    public function test_set_file_from_source_moves_it_within_the_requested_source()
    {
        app('mediatheque.sources')
            ->source('cloud')
            ->putFromLocalPath('incoming/image.jpg', public_path('image.jpg'));

        $file = app(FileContract::class);
        $file->setFileFromSource('incoming/image.jpg', [
            'source' => 'cloud',
            'type' => 'image',
            'extension' => 'jpg',
        ]);

        $this->assertEquals('cloud', $file->source);
        $this->assertFileExists($this->cloudRoot.'/'.$file->path);
        $this->assertFileDoesNotExist($this->cloudRoot.'/incoming/image.jpg');
    }
}
