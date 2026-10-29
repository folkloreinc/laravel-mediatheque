<?php

namespace Folklore\Mediatheque\Support\Traits;

use Folklore\Mediatheque\Concerns\HasMedias as Concern;

/**
 * The 1.x name of the trait. Its `medias()` relation keeps working, with
 * `attach()` and `sync()`; 2.0 adds writes by handle (docs/decisions/0020).
 *
 * @deprecated Use Folklore\Mediatheque\Concerns\HasMedias. Removed in 3.0.
 */
trait HasMedias
{
    use Concern;
}
