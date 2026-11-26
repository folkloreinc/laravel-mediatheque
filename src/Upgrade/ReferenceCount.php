<?php

namespace Folklore\Mediatheque\Upgrade;

/**
 * The references to media by id that `mediatheque:upgrade --references`
 * found in a column.
 */
final readonly class ReferenceCount
{
    /**
     * @param  int  $rows  Rows with references to rewrite
     * @param  int  $references  References to rewrite
     * @param  list<int>  $missing  Ids that match no media: left as they are
     */
    public function __construct(
        public int $rows,
        public int $references,
        public array $missing,
    ) {}
}
