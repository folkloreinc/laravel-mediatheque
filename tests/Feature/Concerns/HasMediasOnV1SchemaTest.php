<?php

namespace Folklore\Mediatheque\Tests\Feature\Concerns;

use Folklore\Mediatheque\Models\Media;
use Folklore\Mediatheque\Support\MediaUsage;
use Folklore\Mediatheque\Tests\Support\Page;
use Folklore\Mediatheque\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

/**
 * A project upgraded from 1.x keeps its `medias_pivot` table: HasMedias
 * works on it as it is, with the rows 1.x wrote.
 */
class HasMediasOnV1SchemaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(self::fixturePath('v1/migrations'));
        // The upgrade converts the medias table; until it exists, the media
        // model needs its soft deletes only
        Schema::table('mediatheque_medias', fn (Blueprint $table) => $table->softDeletes());
        Page::createTable();
    }

    private function runPivotMigration(): void
    {
        $migration = require __DIR__.'/../../../database/migrations/create_mediatheque_medias_pivot_table.php';
        if (! $migration instanceof Migration || ! method_exists($migration, 'up')) {
            $this->fail('The migration has no up().');
        }

        $migration->up();
    }

    private function insertMedia(): int
    {
        return DB::table('mediatheque_medias')->insertGetId(['type' => 'image', 'name' => 'Image']);
    }

    #[Test]
    public function the_migration_leaves_the_1x_table_as_it_is(): void
    {
        $this->runPivotMigration();

        $this->assertTrue(Schema::hasColumns('mediatheque_medias_pivot', ['id', 'media_id', 'morphable_type', 'morphable_id', 'handle', 'order', 'created_at']));
        $this->assertFalse(Schema::hasIndex('mediatheque_medias_pivot', ['morphable_type', 'morphable_id', 'handle', 'media_id'], 'unique'));
    }

    #[Test]
    public function syncing_a_handle_leaves_the_rows_that_1x_wrote(): void
    {
        $this->runPivotMigration();
        [$a, $b] = [$this->insertMedia(), $this->insertMedia()];
        $page = Page::query()->create(['title' => 'Page']);
        // 1.x synced JSON references through the relation, without a handle
        $page->medias()->sync([$a]);

        $page->syncMedias(['json:data' => [$b, $a]]);

        $this->assertSame([$b, $a], $page->medias('json:data')->map(fn (Model $media) => $media->getKey())->all());
        $this->assertSame(3, DB::table('mediatheque_medias_pivot')->count());

        $media = Media::query()->withoutGlobalScopes()->findOrFail($a);
        $this->assertSame([null, 'json:data'], $media->usages()->map(fn (MediaUsage $usage) => $usage->handle)->all());
    }
}
