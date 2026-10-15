<?php

namespace Folklore\Mediatheque\Contracts\Services;

interface SvgSanitizer
{
    /**
     * Mimes of SVG files
     */
    public const MIMES = ['image/svg+xml', 'image/svg'];

    /**
     * Remove scripts, event handlers and external references from an SVG
     *
     * @return string|null The sanitized SVG, or null if it is not a valid SVG
     */
    public function sanitize(string $contents): ?string;
}
