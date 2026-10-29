<?php

namespace Folklore\Mediatheque\Compat\Concerns;

use Folklore\Mediatheque\Compat\Deprecation;
use Folklore\Mediatheque\Contracts\Models\File;
use Folklore\Mediatheque\Upgrade\LegacyHandle;
use Illuminate\Database\Eloquent\Model;

/**
 * The methods of the 1.x file model, kept until 3.0 as proxies of the 2.0
 * API (docs/decisions/0002, docs/upgrade.md). Each one reports its use on
 * the `deprecations` channel.
 *
 * @phpstan-require-extends Model
 *
 * @phpstan-require-implements File
 */
trait HasV1FileMethods
{
    /**
     * The 1.x handle: `thumbnails.0` for the first file of a group.
     *
     * @deprecated Use handle() and index().
     */
    public function getHandle(): string
    {
        Deprecation::trigger('File::getHandle() is deprecated: use handle() and index().');

        return LegacyHandle::format($this->handle(), $this->index());
    }

    /**
     * @deprecated Use url().
     */
    public function getUrl(): ?string
    {
        Deprecation::trigger('File::getUrl() is deprecated: use url().');

        return $this->url();
    }
}
