<?php

namespace Folklore\Mediatheque\Tests\Feature\Upgrade;

use Folklore\Mediatheque\Console\InstallCommand;
use Folklore\Mediatheque\Contracts\Models\File;
use Folklore\Mediatheque\Enums\JobStatus;
use Folklore\Mediatheque\Enums\MediaStatus;
use Folklore\Mediatheque\Enums\PipelineStatus;
use Folklore\Mediatheque\Enums\Visibility;
use Folklore\Mediatheque\Models\Media;
use Folklore\Mediatheque\Models\PipelineRun;
use Folklore\Mediatheque\Support\Uid;
use Folklore\Mediatheque\Tests\Support\Page;
use Folklore\Mediatheque\Tests\Support\V1Database;
use Folklore\Mediatheque\Tests\TestCase;
use Folklore\Mediatheque\Upgrade\LegacyJobs;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

/**
 * The upgrade of a 1.x database, on tables created by the 1.x migrations of
 * 2017–2018 and rows as 1.x wrote them (V1Database).
 */
class UpgradeFromV1Test extends TestCase
{
    private string $prefix = 'mediatheque_';

    protected function setUp(): void
    {
        parent::setUp();

        $this->startFromAnEmptyDatabase();
    }

    private function create1xDatabase(string $prefix = 'mediatheque_'): void
    {
        $this->prefix = $prefix;
        config(['mediatheque.table_prefix' => $prefix]);
        $this->migrate1x();
        (new V1Database($prefix))->seed();
    }

    /**
     * Migrated without a rollback at the end of the test: the foreign keys
     * the upgrade adds would stop the 1.x migrations' down(). The next test
     * starts from an empty database.
     */
    private function migrate1x(): void
    {
        Artisan::call('migrate', ['--path' => [self::fixturePath('v1/migrations'), self::applicationMigrations()], '--realpath' => true]);
        DB::table('pages')->insert(['id' => 1, 'title' => 'Page']);
    }

    private function migrate(): void
    {
        foreach (InstallCommand::upgradeMigrations() as $path) {
            $migration = require $path;
            if (! is_object($migration) || ! method_exists($migration, 'up')) {
                $this->fail('The migration has no up().');
            }
            $migration->up();
        }
    }

    private function upgrade(string ...$options): string
    {
        $status = Artisan::call('mediatheque:upgrade', ['--force' => true, ...array_fill_keys($options, true)]);
        $output = Artisan::output();
        $this->assertSame(0, $status, $output);

        return $output;
    }

    private function table(string $name): string
    {
        return $this->prefix.$name;
    }

    #[Test]
    public function the_migration_adds_the_2_0_columns_to_the_1x_tables(): void
    {
        $this->create1xDatabase();

        $this->migrate();

        $this->assertTrue(Schema::hasColumns($this->table('medias'), ['uid', 'status', 'visibility', 'metadata', 'data', 'transform', 'derived_from_id', 'deleted_at']));
        $this->assertTrue(Schema::hasColumns($this->table('files'), ['media_id', 'index', 'role', 'checksum', 'width', 'height', 'duration', 'bitrate', 'parts_count', 'metadata', 'definition_hash', 'pipeline_job_id', 'provider', 'provider_ref']));
        $this->assertTrue(Schema::hasColumns($this->table('pipelines'), ['uid', 'transcoder', 'external_id', 'output_source', 'path_prefix', 'visibility', 'status', 'progress', 'attempts', 'error']));
        $this->assertTrue(Schema::hasColumns($this->table('pipelines_jobs'), ['status', 'attempts', 'error', 'reason', 'queued_at']));
        // 1.x columns stay
        $this->assertTrue(Schema::hasColumns($this->table('pipelines'), ['started', 'ended', 'failed', 'failed_exception']));
        $this->assertTrue(Schema::hasTable($this->table('metadatas')));

        // Wider mimes and 64-bit sizes
        DB::table($this->table('files'))->where('id', 1)->update(['mime' => str_repeat('a', 100), 'size' => 5_000_000_000]);
        $this->assertEquals(5_000_000_000, DB::table($this->table('files'))->where('id', 1)->value('size'));
    }

    #[Test]
    public function the_check_lists_what_the_upgrade_removes_or_cant_convert_before_the_migration(): void
    {
        $this->create1xDatabase();

        $this->assertSame(0, Artisan::call('mediatheque:upgrade', ['--check' => true]));
        $output = Artisan::output();

        $this->assertMatchesRegularExpression('/metadatas \.+ 1/', $output);
        $this->assertMatchesRegularExpression('/medias_pivot \.+ 1/', $output);
        $this->assertMatchesRegularExpression('/pipelines \.+ 1/', $output);
        $this->assertMatchesRegularExpression('/pipelines_jobs \.+ 1/', $output);
        $this->assertMatchesRegularExpression('/files \.+ 1/', $output);
        $this->assertMatchesRegularExpression('/Folklore\\\\Mediatheque\\\\Jobs\\\\Font\\\\WebFonts \.+ 1 runs/', $output);
        $this->assertMatchesRegularExpression('/1080p \.+ 1 files/', $output);
        $this->assertMatchesRegularExpression('/'.preg_quote(Page::class, '/').' \.+ 1 rows/', $output);
        $this->assertStringContainsString('font (1 media)', $output);
        $this->assertStringContainsString('WebFonts', $output);
        $this->assertMatchesRegularExpression('/document \\(1 media\\) .*1\\.x rendered every page of documents; 2\\.0 renders the first/', $output);
        $this->assertStringContainsString('Files without a source, which 1.x stored on its default source: they get "public"', $output);

        // Read-only
        $this->assertSame(1, DB::table($this->table('medias_pivot'))->where('media_id', 99)->count());
    }

    #[Test]
    public function the_upgrade_requires_the_migration(): void
    {
        $this->create1xDatabase();

        $this->assertSame(1, Artisan::call('mediatheque:upgrade', ['--force' => true]));
        $this->assertStringContainsString('mediatheque:install --upgrade', Artisan::output());
    }

    #[Test]
    public function the_upgrade_converts_media_files_and_metadata(): void
    {
        $this->create1xDatabase();
        $this->migrate();

        $this->upgrade();

        $image = Media::query()->findOrFail(1);
        $this->assertTrue(Uid::isValid($image->uid(), 'med'));
        $this->assertSame('2017-09-01', Uid::prefixOf($image->uid()) === 'med' ? $image->created_at?->format('Y-m-d') : null);
        $this->assertSame(MediaStatus::Ready, $image->status());
        $this->assertSame(Visibility::Public, $image->visibility());
        $this->assertSame(1200, $image->metadata('width'));
        $this->assertSame('image', $image->metadata('kind'));
        $this->assertSame('image/jpeg', $image->metadata('mime'));
        $this->assertSame('media/image/1-original.jpg', $image->original()?->path());
        $this->assertSame('public', $image->original()->source(), '1.x stored a file without a source on its default source (#144).');
        $this->assertSame(File::ORIGINAL, $image->original()->role());
        $this->assertSame(1200, $image->original()->width());
        $this->assertSame([], $image->original()->metadata());
        $this->assertSame('/storage/media/image/1-original.jpg', parse_url((string) $image->url(), PHP_URL_PATH));

        $video = Media::query()->findOrFail(2);
        $this->assertSame(12.5, $video->metadata('duration'));
        $this->assertSame(['1080p', 'h264', 'original', 'thumbnails', 'webm'], $video->files()->map(fn (File $file) => $file->handle())->unique()->values()->all());
        $this->assertSame([0, 1, 2, 3, 4], $video->files('thumbnails')->map(fn (File $file) => $file->index())->all());
        $this->assertSame('thumbnail', $video->file('thumbnails', 3)?->role());
        $this->assertSame('media/video/2-thumbnails-3.jpg', $video->file('thumbnails', 3)->path());
        $this->assertSame('main', $video->file('h264')?->role());
        $this->assertNull($video->file('h264')->index());
        $this->assertSame(12.5, $video->file('h264')->duration());
        $this->assertSame('thumbnail', $video->thumbnail()?->role());

        $this->assertSame(MediaStatus::Failed, Media::query()->findOrFail(3)->status(), 'Its last run failed.');

        $audio = Media::query()->findOrFail(4);
        $this->assertSame(61.2, $audio->metadata('duration'));
        $this->assertNull($audio->file('thumbnails')?->index());
        $this->assertSame('thumbnail', $audio->file('thumbnails')?->role());

        $document = Media::query()->findOrFail(5);
        $this->assertSame(2, $document->metadata('pages_count'));
        $this->assertCount(2, $document->files('thumbnails'));

        $font = Media::query()->findOrFail(6);
        $this->assertSame(['Generic Sans'], $font->metadata('font_names'));
        $this->assertSame(['#000000', '#ffffff'], $font->metadata('palette'));
        $this->assertNull($font->metadata('font_family_name'));

        // The page keeps its media
        $this->assertSame(1, Page::query()->findOrFail(1)->media('cover')?->getKey());
    }

    #[Test]
    public function the_upgrade_converts_runs_and_their_jobs(): void
    {
        $this->create1xDatabase();
        $this->migrate();

        $this->upgrade();

        $runs = PipelineRun::query()->with('pipelineJobs')->orderBy('id')->get();
        $this->assertCount(6, $runs, 'The run of the deleted media is removed.');

        $video = $runs->firstOrFail(fn (PipelineRun $run) => $run->pipelinable_id === 2);
        $this->assertTrue(Uid::isValid($video->key(), PipelineRun::UID_PREFIX));
        $this->assertSame((new Media)->getMorphClass(), $video->pipelinable_type);
        $this->assertSame(PipelineStatus::Completed, $video->status());
        $this->assertSame('ffmpeg', $video->transcoder());
        $this->assertSame('public', $video->outputSource());
        $this->assertSame('media/video', $video->pathPrefix());
        // PostgreSQL doesn't keep the order of the keys of a jsonb object
        $renditions = array_keys($video->renditions());
        sort($renditions);
        $this->assertSame(['h264', 'hevc', 'thumbnails', 'webm'], $renditions);
        $this->assertSame('video.transcode', $video->renditions()['h264']->operation);
        $this->assertSame('vp9', $video->renditions()['webm']->options['codec']);
        $this->assertSame(5, $video->renditions()['thumbnails']->options['count']);
        $this->assertSame('thumbnail', $video->renditions()['thumbnails']->role);
        $this->assertSame([JobStatus::Completed], array_values(array_unique($video->jobs()->map(fn ($job) => $job->status())->all(), SORT_REGULAR)));
        $this->assertSame(1.0, $video->job('h264')?->progress());
        $this->assertSame(Media::query()->findOrFail(2)->uid(), $video->media()->uid());

        $failed = $runs->filter(fn (PipelineRun $run) => $run->pipelinable_id === 3)->last();
        $this->assertNotNull($failed);
        $this->assertSame(PipelineStatus::Failed, $failed->status());
        $this->assertSame('operation_failed', $failed->error()?->code->value);
        $this->assertSame(JobStatus::Failed, $failed->job('hevc')?->status());
        $this->assertSame('RuntimeException: ffmpeg failed', $failed->job('hevc')->error()?->message);
        $this->assertSame(JobStatus::Completed, $failed->job('h264')?->status());

        $font = $runs->firstOrFail(fn (PipelineRun $run) => $run->pipelinable_id === 6);
        $this->assertSame(LegacyJobs::LEGACY_OPERATION, $font->renditions()['webfonts']->operation);
        $this->assertSame('Folklore\\Mediatheque\\Jobs\\Font\\WebFonts', $font->renditions()['webfonts']->options['job']);

        $document = $runs->firstOrFail(fn (PipelineRun $run) => $run->pipelinable_id === 5);
        $this->assertSame('document.thumbnails', $document->renditions()['thumbnails']->operation);
    }

    #[Test]
    public function the_upgrade_removes_orphan_rows_and_adds_the_foreign_keys(): void
    {
        $this->create1xDatabase();
        $this->migrate();

        $this->upgrade();

        $this->assertSame(0, DB::table($this->table('medias_pivot'))->where('media_id', 99)->count());
        $this->assertSame(0, DB::table($this->table('pipelines_jobs'))->where('pipeline_id', 999)->count());
        $this->assertSame(0, DB::table($this->table('files'))->where('path', 'media/orphan.jpg')->count());
        // A file attached to a page is kept, without a media
        $this->assertNull(DB::table($this->table('files'))->where('path', 'media/attachment.pdf')->value('media_id'));
        $this->assertSame(1, DB::table($this->table('files'))->where('path', 'media/attachment.pdf')->count());

        $keys = fn (string $table) => array_merge(...array_column(Schema::getForeignKeys($this->table($table)), 'columns'));
        $this->assertContains('media_id', $keys('files'));
        $this->assertContains('pipeline_job_id', $keys('files'));
        $this->assertContains('pipeline_id', $keys('pipelines_jobs'));
        $this->assertContains('media_id', $keys('medias_pivot'));

        $types = array_column(Schema::getColumns($this->table('pipelines')), 'type_name', 'name');
        $expected = ['pgsql' => 'jsonb', 'mysql' => 'json'][DB::getDriverName()] ?? null;
        if ($expected !== null) {
            $this->assertSame($expected, $types['definition'], 'The 1.x definitions column is a JSON column once converted.');
        }

        // A media's force deletion removes its files and attachments
        Media::query()->findOrFail(1)->forceDelete();
        $this->assertSame(0, DB::table($this->table('medias_pivot'))->where('media_id', 1)->count());
    }

    #[Test]
    public function the_upgrade_resumes_in_batches_and_changes_nothing_the_second_time(): void
    {
        $this->create1xDatabase();
        $this->migrate();

        $this->assertSame(0, Artisan::call('mediatheque:upgrade', ['--force' => true, '--batch' => 1]));
        $uids = DB::table($this->table('medias'))->orderBy('id')->pluck('uid')->all();
        $files = DB::table($this->table('files'))->orderBy('id')->get()->map(fn (object $file) => (array) $file)->all();

        $output = $this->upgrade();

        $this->assertSame($uids, DB::table($this->table('medias'))->orderBy('id')->pluck('uid')->all());
        $this->assertEquals($files, DB::table($this->table('files'))->orderBy('id')->get()->map(fn (object $file) => (array) $file)->all());
        $this->assertMatchesRegularExpression('/Media: uid, status and metadata \.+ 0 converted/', $output);
        $this->assertStringContainsString('added already', $output);
    }

    #[Test]
    public function an_interrupted_upgrade_converts_the_rest(): void
    {
        $this->create1xDatabase();
        $this->migrate();
        // As if it stopped after a batch of media: their uid is set
        DB::table($this->table('medias'))->where('id', 1)->update(['uid' => Uid::generate('med'), 'status' => 'ready']);

        $this->upgrade();

        $this->assertSame(0, DB::table($this->table('medias'))->whereNull('uid')->count());
        $this->assertSame(0, DB::table($this->table('pipelines'))->whereNull('uid')->count());
    }

    #[Test]
    public function it_upgrades_tables_with_a_custom_prefix(): void
    {
        $this->create1xDatabase('legacy_');
        $this->migrate();

        $this->upgrade();

        $this->assertSame(6, Media::query()->count());
        $this->assertSame('legacy_medias', (new Media)->getTable());
        $this->assertCount(5, Media::query()->findOrFail(2)->files('thumbnails'));
        $this->assertContains('media_id', array_merge(...array_column(Schema::getForeignKeys('legacy_files'), 'columns')));
    }

    #[Test]
    public function rows_of_another_media_class_are_converted_when_the_command_names_it(): void
    {
        $this->migrate1x();
        (new V1Database(mediaType: 'App\\Models\\Media'))->seed();
        $this->migrate();

        Artisan::call('mediatheque:upgrade', ['--check' => true]);
        $this->assertStringContainsString('App\\Models\\Media', Artisan::output());

        $this->assertSame(0, Artisan::call('mediatheque:upgrade', ['--force' => true, '--media-morph' => ['App\\Models\\Media']]));

        $video = Media::query()->findOrFail(2);
        $this->assertSame(1920, $video->metadata('width'));
        $this->assertCount(5, $video->files('thumbnails'));
        $this->assertCount(1, $video->pipelineRuns()->get());
    }

    #[Test]
    public function the_cleanup_drops_the_metadatas_table_once_everything_is_converted(): void
    {
        $this->create1xDatabase();
        $this->migrate();

        $this->assertSame(1, Artisan::call('mediatheque:upgrade', ['--cleanup' => true, '--force' => true]));
        $this->assertTrue(Schema::hasTable($this->table('metadatas')));

        $this->upgrade();
        $this->upgrade('--cleanup');

        $this->assertFalse(Schema::hasTable($this->table('metadatas')));
        $this->assertSame(1200, Media::query()->findOrFail(1)->metadata('width'));
    }

    #[Test]
    public function the_cleanup_refuses_while_the_metadatas_table_holds_metadata_that_was_not_copied(): void
    {
        $this->create1xDatabase();
        $this->migrate();
        // The file a page attaches has metadata, and no media to copy it to
        $attachment = DB::table($this->table('files'))->where('path', 'media/attachment.pdf')->value('id');
        DB::table($this->table('metadatas'))->insert(['morphable_type' => V1Database::FILE, 'morphable_id' => $attachment, 'name' => 'pages_count', 'type' => 'integer', 'value_integer' => 3]);
        $this->upgrade();

        $this->assertSame(1, Artisan::call('mediatheque:upgrade', ['--cleanup' => true, '--force' => true]));

        $this->assertMatchesRegularExpression('/of files without a media.*[ .]1\s/', Artisan::output());
        $this->assertTrue(Schema::hasTable($this->table('metadatas')));
    }

    #[Test]
    public function the_upgrade_refuses_metadata_of_unknown_morph_types_unless_accepted(): void
    {
        $this->create1xDatabase();
        $this->migrate();
        DB::table($this->table('metadatas'))->insert(['morphable_type' => 'App\\Legacy\\Media', 'morphable_id' => 1, 'name' => 'width', 'type' => 'integer', 'value_integer' => 10]);

        $this->assertSame(1, Artisan::call('mediatheque:upgrade', ['--force' => true]));
        $this->assertStringContainsString('App\\Legacy\\Media (1 rows)', Artisan::output());
        $this->assertSame(6, DB::table($this->table('medias'))->whereNull('uid')->count(), 'Nothing is converted.');

        $this->assertSame(0, Artisan::call('mediatheque:upgrade', ['--force' => true, '--accept-unknown-morphs' => true]));
        $this->assertSame(0, DB::table($this->table('medias'))->whereNull('uid')->count());

        // Its metadata was not copied: the cleanup keeps it
        $this->assertSame(1, Artisan::call('mediatheque:upgrade', ['--cleanup' => true, '--force' => true]));
        $this->assertMatchesRegularExpression('/of morph types that are neither media nor files[ .]+1\s/', Artisan::output());
    }

    #[Test]
    public function the_modes_run_one_at_a_time(): void
    {
        $this->create1xDatabase();
        file_put_contents(config_path('mediatheque.php'), "<?php\n\nreturn ['sources' => []];\n");

        try {
            $this->assertSame(1, Artisan::call('mediatheque:upgrade', ['--check' => true, '--config' => true]));
            $this->assertStringContainsString("--check and --config can't be combined", Artisan::output());
            $this->assertFileDoesNotExist(config_path('mediatheque.php.1x.bak'));
        } finally {
            @unlink(config_path('mediatheque.php'));
        }
    }

    #[Test]
    public function the_check_reports_a_1x_source_that_the_disk_of_the_same_name_doesnt_store(): void
    {
        $this->create1xDatabase();
        file_put_contents(config_path('mediatheque.php'), "<?php\n\nreturn ['sources' => ['public' => ['driver' => 'local', 'path' => '/var/www/public/files', 'url' => '/files']]];\n");

        try {
            $this->assertSame(0, Artisan::call('mediatheque:upgrade', ['--check' => true]));
            $this->assertStringContainsString('The 1.x source "public"', Artisan::output());
        } finally {
            @unlink(config_path('mediatheque.php'));
        }
    }

    #[Test]
    public function a_run_on_another_model_takes_no_media_original(): void
    {
        $this->create1xDatabase();
        // A run on a page whose id is a media's
        DB::table($this->table('pipelines'))->insert(['id' => 50, 'name' => 'page', 'pipelinable_type' => Page::class, 'pipelinable_id' => 2, 'definition' => null, 'ended' => true, 'created_at' => '2018-08-01 10:00:00']);
        $this->migrate();

        $this->upgrade();

        $run = DB::table($this->table('pipelines'))->where('id', 50)->first();
        $this->assertNotNull($run);
        $this->assertSame(Page::class, $run->pipelinable_type);
        $this->assertSame('', $run->path_prefix);
        $this->assertSame('public', $run->output_source, 'The default source, not the media\'s.');
    }

    #[Test]
    public function a_zero_date_gets_a_uid_of_now(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('MySQL in strict mode and PostgreSQL refuse a zero date.');
        }

        $this->create1xDatabase();
        DB::table($this->table('medias'))->where('id', 1)->update(['created_at' => '0000-00-00 00:00:00']);
        $this->migrate();

        $this->upgrade();

        $uid = DB::table($this->table('medias'))->where('id', 1)->value('uid');
        $this->assertTrue(is_string($uid) && Uid::isValid($uid, 'med'));
    }

    #[Test]
    public function a_database_upgraded_before_gets_its_files_a_source_when_the_upgrade_runs_again(): void
    {
        $this->create1xDatabase();
        $this->migrate();
        $this->upgrade();
        // As 2.0.0-alpha.11 left them (#144)
        DB::table($this->table('files'))->where('path', 'like', 'media/image/%')->update(['source' => null]);
        $this->assertNull(Media::query()->findOrFail(1)->url());

        $output = $this->upgrade();

        $this->assertMatchesRegularExpression('/Files without a source: the default source \.+ 1 given "public"/', $output);
        $this->assertSame('/storage/media/image/1-original.jpg', parse_url((string) Media::query()->findOrFail(1)->url(), PHP_URL_PATH));
        $this->assertSame(0, DB::table($this->table('files'))->whereNull('source')->count());
        $this->assertMatchesRegularExpression('/Files without a source: the default source \.+ 0 given/', $this->upgrade());
    }

    #[Test]
    public function files_without_a_source_get_the_1x_default_source(): void
    {
        config(['mediatheque.source' => 'cloud', 'filesystems.disks.cloud' => ['driver' => 'local', 'root' => storage_path('app/cloud'), 'url' => '/cloud']]);
        $this->create1xDatabase();
        $this->migrate();

        $this->upgrade();

        $this->assertSame('cloud', Media::query()->findOrFail(1)->original()?->source());
        $this->assertSame('public', Media::query()->findOrFail(2)->original()?->source(), 'A file with a source keeps it.');
        $this->assertSame('/cloud/media/image/1-original.jpg', parse_url((string) Media::query()->findOrFail(1)->url(), PHP_URL_PATH));
    }
}
