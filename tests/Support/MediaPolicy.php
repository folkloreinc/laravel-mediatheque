<?php

namespace Folklore\Mediatheque\Tests\Support;

/**
 * An application's policy for media: only users who may upload can create
 * media.
 */
class MediaPolicy
{
    public function create(User $user): bool
    {
        return $user->can_upload;
    }
}
