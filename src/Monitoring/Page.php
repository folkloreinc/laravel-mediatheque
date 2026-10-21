<?php

namespace Folklore\Mediatheque\Monitoring;

/**
 * One page of a list, newest first. `nextCursor` gets the next page, and is
 * null on the last one.
 *
 * @template T
 */
final readonly class Page
{
    /**
     * @param  list<T>  $items
     */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
    ) {}
}
