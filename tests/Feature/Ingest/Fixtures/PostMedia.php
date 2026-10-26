<?php

namespace Folklore\Mediatheque\Tests\Feature\Ingest\Fixtures;

use Folklore\Mediatheque\Models\Media;
use Folklore\Mediatheque\Tests\Support\Post;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * An application's media model with accessors, for path placeholders: one
 * that reads a relation, as a tenant's public id would, one that reads the
 * media's own columns, and one that shadows a column.
 *
 * @property int|null $post_id
 * @property string $uid
 */
class PostMedia extends Media
{
    /**
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * @return Attribute<string|null, never>
     */
    protected function postSlug(): Attribute
    {
        return Attribute::get(fn () => $this->post !== null ? Str::slug($this->post->title) : null);
    }

    /**
     * @return Attribute<string, never>
     */
    protected function shortUid(): Attribute
    {
        return Attribute::get(fn () => substr($this->uid, -6));
    }

    /**
     * An accessor of the old style, named like a column.
     */
    public function getSpaceIdAttribute(mixed $value): string
    {
        return 'space-'.(is_scalar($value) ? $value : '');
    }
}
