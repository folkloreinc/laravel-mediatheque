<?php

namespace Folklore\Mediatheque\Tests\Feature;

use Folklore\Mediatheque\Contracts\Mediatheque as MediathequeContract;
use Folklore\Mediatheque\Contracts\Models\Media as MediaContract;
use Folklore\Mediatheque\Facades\Mediatheque;
use Folklore\Mediatheque\MediathequeManager;
use Folklore\Mediatheque\Models\Media;
use Folklore\Mediatheque\Tests\Concerns\CreatesMedia;
use Folklore\Mediatheque\Tests\Contracts\ResolvesReferencesContractTests;
use Folklore\Mediatheque\Tests\DatabaseTestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

class MediathequeManagerTest extends DatabaseTestCase
{
    use CreatesMedia;
    use ResolvesReferencesContractTests;

    protected function mediatheque(): MediathequeContract
    {
        return app(MediathequeContract::class);
    }

    protected function internalId(MediaContract $media): int
    {
        return $media instanceof Media ? $media->id : 0;
    }

    #[Test]
    public function the_facade_resolves_the_manager_outside_the_fake(): void
    {
        $this->assertInstanceOf(MediathequeManager::class, Mediatheque::getFacadeRoot());
    }

    #[Test]
    public function a_deleted_media_does_not_resolve(): void
    {
        $media = $this->createMedia();
        $media->delete();

        $this->assertNull(Mediatheque::resolve($media->uid));
        $this->assertTrue(Mediatheque::resolveMany(['media://'.$media->uid])->isEmpty());
    }

    #[Test]
    public function resolve_many_runs_one_query_per_form_with_the_files(): void
    {
        $first = $this->createMedia();
        $second = $this->createMedia();
        $this->createFile($first);
        $this->createFile($second);

        DB::enableQueryLog();
        $resolved = Mediatheque::resolveMany([$first->uid, 'media://'.$second->uid, 'medias://'.$first->id]);
        $resolved->each(fn (MediaContract $media) => $media->original());
        $queries = count(DB::getQueryLog());

        $this->assertSame([$first->uid, 'media://'.$second->uid, 'medias://'.$first->id], $resolved->keys()->all());
        $this->assertSame(4, $queries, 'Media by uid and by id, and their files for each.');
    }
}
