<?php

namespace Folklore\Mediatheque\Embeds;

use Folklore\Mediatheque\Enums\ProbeKind;

/**
 * An embed that a provider recognized in a page URL (docs/decisions/0029).
 */
final readonly class EmbedMatch
{
    public function __construct(
        /** The provider's name: `youtube`, `vimeo`… */
        public string $provider,
        /** The video's or audio's id at the provider. */
        public string $id,
        /** The canonical URL of its page. */
        public string $url,
        /** What it plays, until the probe says otherwise. */
        public ProbeKind $kind,
    ) {}
}
