<?php

namespace Folklore\Mediatheque\Tests\Feature\Concerns;

use Folklore\Mediatheque\Contracts\Models\Media as MediaContract;
use Folklore\Mediatheque\Exceptions\MediaInUse;
use Folklore\Mediatheque\Models\Media;
use Folklore\Mediatheque\Support\MediaUsage;
use Folklore\Mediatheque\Testing\Models\FakeMedia;
use Folklore\Mediatheque\Tests\Concerns\CreatesMedia;
use Folklore\Mediatheque\Tests\DatabaseTestCase;
use Folklore\Mediatheque\Tests\Support\Page;
use Folklore\Mediatheque\Tests\Support\Post;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\Test;

class HasMediasTest extends DatabaseTestCase
{
    use CreatesMedia;

    protected function tearDown(): void
    {
        Relation::morphMap([], false);
        Relation::requireMorphMap(false);

        parent::tearDown();
    }

    /**
     * @param  iterable<Model|MediaContract>  $medias
     * @return list<mixed>
     */
    private static function ids(iterable $medias): array
    {
        $ids = [];
        foreach ($medias as $media) {
            $ids[] = $media instanceof Model ? $media->getKey() : null;
        }

        return $ids;
    }

    #[Test]
    public function attached_media_are_read_by_handle_in_their_order(): void
    {
        [$cover, $a, $b] = [$this->createMedia(), $this->createMedia(), $this->createMedia()];
        $post = Post::query()->create(['title' => 'Post']);

        $post->attachMedia($cover, 'cover');
        $post->attachMedia($a, 'gallery');
        $post->attachMedia($b->id, 'gallery');

        $this->assertSame($cover->id, $post->media('cover')?->getKey());
        $this->assertSame([$a->id, $b->id], self::ids($post->medias('gallery')));
        $this->assertNull($post->media('poster'));
        $this->assertSame([], self::ids($post->medias('poster')));
        $this->assertSame([$cover->id, $a->id, $b->id], self::ids($post->medias()->get()), 'The relation reads every handle.');
    }

    #[Test]
    public function the_relation_has_the_handle_and_order_on_its_pivot(): void
    {
        [$a, $b] = [$this->createMedia(), $this->createMedia()];
        $post = Post::query()->create(['title' => 'Post']);
        $post->attachMedia($a, 'gallery');
        $post->attachMedia($b, 'gallery');

        $pivots = [];
        foreach ($post->medias()->get() as $media) {
            $this->assertInstanceOf(Model::class, $media);
            $pivot = $media->getRelation('pivot');
            $this->assertInstanceOf(Model::class, $pivot);
            $this->assertNotNull($pivot->getAttribute('created_at'));
            $pivots[] = [$pivot->getAttribute('handle'), $pivot->getAttribute('order')];
        }

        $this->assertEquals([['gallery', 0], ['gallery', 1]], $pivots);
    }

    #[Test]
    public function media_by_handle_read_the_loaded_relation(): void
    {
        [$cover, $a] = [$this->createMedia(), $this->createMedia()];
        $post = Post::query()->create(['title' => 'Post']);
        $post->attachMedia($cover, 'cover');
        $post->attachMedia($a, 'gallery');

        $loaded = Post::query()->with('medias')->findOrFail($post->id);
        DB::enableQueryLog();

        $this->assertSame($cover->id, $loaded->media('cover')?->getKey());
        $this->assertSame([$a->id], self::ids($loaded->medias('gallery')));
        $this->assertSame([], DB::getQueryLog());
    }

    #[Test]
    public function attaching_a_media_again_under_its_handle_changes_nothing(): void
    {
        [$a, $b] = [$this->createMedia(), $this->createMedia()];
        $post = Post::query()->create(['title' => 'Post']);
        $post->attachMedia($a, 'gallery');
        $post->attachMedia($b, 'gallery');

        $post->attachMedia($a, 'gallery');
        $post->attachMedia($a, 'cover');

        $this->assertSame([$a->id, $b->id], self::ids($post->medias('gallery')));
        $this->assertSame($a->id, $post->media('cover')?->getKey(), 'The same media can be attached under another handle.');
        $this->assertSame(3, DB::table('mediatheque_medias_pivot')->count());
    }

    #[Test]
    public function syncing_replaces_the_media_of_the_handles_it_names_only(): void
    {
        [$cover, $a, $b, $c] = [$this->createMedia(), $this->createMedia(), $this->createMedia(), $this->createMedia()];
        $page = Page::query()->create(['title' => 'Page']);
        $page->attachMedia($cover, 'cover');
        $page->syncMedias(['json:data' => [$a->id, $b->id]]);

        $page->syncMedias(['json:data' => [$c->id, $a->id]]);

        $this->assertSame([$c->id, $a->id], self::ids($page->medias('json:data')));
        $this->assertSame($cover->id, $page->media('cover')?->getKey(), 'Media attached under another handle stay.');
        $this->assertEquals(
            [[$c->id, 0], [$a->id, 1]],
            DB::table('mediatheque_medias_pivot')->where('handle', 'json:data')->orderBy('order')->get()
                ->map(fn (object $row) => [$row->media_id, $row->order])->all(),
        );
    }

    #[Test]
    public function syncing_several_handles_takes_media_and_their_keys(): void
    {
        [$cover, $a, $b] = [$this->createMedia(), $this->createMedia(), $this->createMedia()];
        $post = Post::query()->create(['title' => 'Post']);

        $post->syncMedias(['cover' => $cover, 'gallery' => collect([$a, $b->id, $a])]);

        $this->assertSame($cover->id, $post->media('cover')?->getKey());
        $this->assertSame([$a->id, $b->id], self::ids($post->medias('gallery')), 'A media listed twice is attached once.');
    }

    #[Test]
    public function syncing_a_handle_to_nothing_detaches_its_media(): void
    {
        [$cover, $a] = [$this->createMedia(), $this->createMedia()];
        $post = Post::query()->create(['title' => 'Post']);
        $post->syncMedias(['cover' => $cover, 'gallery' => [$a]]);

        $post->syncMedias(['cover' => null, 'gallery' => []]);

        $this->assertNull($post->media('cover'));
        $this->assertSame(0, DB::table('mediatheque_medias_pivot')->count());
    }

    #[Test]
    public function syncing_ignores_keys_that_no_media_has(): void
    {
        $a = $this->createMedia();
        $post = Post::query()->create(['title' => 'Post']);

        $post->syncMedias(['json:data' => [999, $a->id]]);

        $this->assertSame([$a->id], self::ids($post->medias('json:data')));
        $this->assertSame(0, DB::table('mediatheque_medias_pivot')->where('order', '>', 0)->count(), 'Positions count the media that exist.');
    }

    #[Test]
    public function syncing_refuses_values_that_are_not_media(): void
    {
        $post = Post::query()->create(['title' => 'Post']);

        $this->expectException(InvalidArgumentException::class);

        // @phpstan-ignore argument.type (a uid is not a key: the test checks that it is refused)
        $post->syncMedias(['gallery' => ['med_01J']]);
    }

    #[Test]
    public function attaching_a_key_that_no_media_has_throws(): void
    {
        $post = Post::query()->create(['title' => 'Post']);

        $this->expectException(ModelNotFoundException::class);

        $post->attachMedia(999, 'cover');
    }

    #[Test]
    public function a_media_of_the_fake_cant_be_attached(): void
    {
        $post = Post::query()->create(['title' => 'Post']);

        $this->expectException(InvalidArgumentException::class);

        $post->attachMedia(new FakeMedia(1, 'med_01J', 'video', null), 'cover');
    }

    #[Test]
    public function an_unsaved_model_cant_attach_media(): void
    {
        $this->expectException(LogicException::class);

        (new Post(['title' => 'Draft']))->attachMedia($this->createMedia(), 'cover');
    }

    #[Test]
    public function a_media_is_detached_from_a_handle_or_from_all_of_them(): void
    {
        [$a, $b] = [$this->createMedia(), $this->createMedia()];
        $post = Post::query()->create(['title' => 'Post']);
        $post->syncMedias(['cover' => $a, 'gallery' => [$a, $b]]);

        $this->assertSame(1, $post->detachMedia($a, 'gallery'));
        $this->assertSame($a->id, $post->media('cover')?->getKey());
        $this->assertSame([$b->id], self::ids($post->medias('gallery')));

        $this->assertSame(1, $post->detachMedia($a));
        $this->assertNull($post->media('cover'));
    }

    #[Test]
    public function the_usages_of_a_media_are_the_models_that_attach_it(): void
    {
        Relation::morphMap(['post' => Post::class]);
        $media = $this->createMedia();
        $post = Post::query()->create(['title' => 'Post']);
        $page = Page::query()->create(['title' => 'Page']);
        $post->attachMedia($media, 'cover');
        $page->syncMedias(['json:data' => [$media->id], 'cover' => $media]);

        $usages = $media->usages();

        $this->assertTrue($media->isUsed());
        $this->assertSame(
            [['post', $post->id, 'cover', 0], [Page::class, $page->id, 'json:data', 0], [Page::class, $page->id, 'cover', 0]],
            $usages->map(fn (MediaUsage $usage) => [$usage->type, $usage->id, $usage->handle, $usage->order])->all(),
        );
        $this->assertInstanceOf(Post::class, $usages->first()?->model);
        $this->assertTrue($page->is($usages->get(1)?->model));
        $this->assertFalse($this->createMedia()->isUsed());
    }

    #[Test]
    public function a_soft_deleted_model_still_uses_its_media(): void
    {
        $media = $this->createMedia();
        $post = Post::query()->create(['title' => 'Post']);
        $post->attachMedia($media, 'cover');

        $post->delete();

        $this->assertTrue($media->isUsed());
        $this->assertTrue($post->is($media->usages()->first()?->model), 'A usage is loaded without global scopes.');

        $post->forceDelete();

        $this->assertFalse($media->isUsed());
    }

    #[Test]
    public function a_deleted_model_no_longer_uses_its_media(): void
    {
        $media = $this->createMedia();
        $page = Page::query()->create(['title' => 'Page']);
        $page->attachMedia($media, 'cover');

        $page->delete();

        $this->assertFalse($media->isUsed());
    }

    #[Test]
    public function a_usage_whose_model_no_longer_exists_has_no_model(): void
    {
        $media = $this->createMedia();
        $page = Page::query()->create(['title' => 'Page']);
        $page->attachMedia($media, 'cover');
        DB::table('pages')->delete();

        $this->assertTrue($media->isUsed());
        $this->assertNull($media->usages()->first()?->model);
    }

    #[Test]
    public function a_media_in_use_cant_be_deleted(): void
    {
        $media = $this->createMedia();
        $post = Post::query()->create(['title' => 'Post']);
        $post->attachMedia($media, 'cover');

        try {
            $media->delete();
            $this->fail('A media in use was deleted.');
        } catch (MediaInUse $exception) {
            $this->assertSame($media, $exception->media);
        }

        $this->assertFalse($media->fresh()?->trashed());

        $post->detachMedia($media);
        $media->delete();

        $this->assertTrue($media->trashed());
    }

    #[Test]
    public function a_forced_deletion_deletes_a_media_in_use_and_its_usages(): void
    {
        $media = $this->createMedia();
        $post = Post::query()->create(['title' => 'Post']);
        $post->attachMedia($media, 'cover');

        $media->forceDelete();

        $this->assertNull(Media::query()->withTrashed()->find($media->id));
        $this->assertSame(0, DB::table('mediatheque_medias_pivot')->count());
        $this->assertNull($post->media('cover'));
    }

    #[Test]
    public function a_trashed_media_is_not_read_through_the_relation(): void
    {
        $media = $this->createMedia();
        $media->delete();
        $post = Post::query()->create(['title' => 'Post']);

        $post->syncMedias(['json:data' => [$media->id]]);

        $this->assertNull($post->media('json:data'));
        $this->assertTrue($media->isUsed(), 'A reference to a trashed media is kept, and counts as a usage.');
    }
}
