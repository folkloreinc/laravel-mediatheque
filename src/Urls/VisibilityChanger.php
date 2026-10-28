<?php

namespace Folklore\Mediatheque\Urls;

use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Enums\Visibility;
use Folklore\Mediatheque\Events\MediaVisibilityChanged;
use Folklore\Mediatheque\Jobs\ApplyVisibility;
use Folklore\Mediatheque\Support\Models;
use Illuminate\Contracts\Bus\Dispatcher as Jobs;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Changes a media's visibility (docs/decisions/0007), on the Eloquent
 * models: see Mediatheque::setVisibility().
 *
 * The media's row is locked and read again, so two changes run one after
 * the other and only one of them dispatches the event. Once committed, its
 * files follow in a queued job (ApplyVisibility). The files of a run in
 * progress are written with the visibility of its start; the recorder
 * queues the same job when it records them.
 */
class VisibilityChanger
{
    public function __construct(
        private readonly ConnectionResolverInterface $database,
        private readonly Events $events,
        private readonly Jobs $jobs,
    ) {}

    public function change(Media $media, Visibility $visibility): bool
    {
        if (! $media instanceof Model) {
            throw new InvalidArgumentException(sprintf('The visibility of %s can\'t be changed: it is not an Eloquent media.', $media->uid()));
        }

        $previous = $this->database->connection($media->getConnectionName())->transaction(function () use ($media, $visibility) {
            $locked = Models::media()::query()->withoutGlobalScopes()->whereKey($media->getKey())->lockForUpdate()->first()
                ?? throw new InvalidArgumentException(sprintf('The media %s is gone.', $media->uid()));
            $previous = $locked->visibility();

            if ($previous !== $visibility) {
                $locked->forceFill(['visibility' => $visibility])->save();
            }

            return $previous;
        });

        // The instance follows at once, so its URLs do
        $media->forceFill(['visibility' => $visibility])->syncOriginalAttribute('visibility');

        if ($previous === $visibility) {
            return false;
        }

        $this->jobs->dispatch(new ApplyVisibility($media->uid()));
        $this->events->dispatch(new MediaVisibilityChanged($media, $previous, $visibility));

        return true;
    }
}
