<?php

namespace Folklore\Mediatheque\Ingest;

use DOMDocument;
use DOMElement;
use DOMNode;
use Rhukster\DomSanitizer\DOMSanitizer;

/**
 * Removes scripts, event handlers, foreign content and external references
 * from SVG files before they are stored, so they can be served from the
 * application's domain.
 */
class SvgSanitizer
{
    /**
     * The sanitized SVG, or null when the file is not a valid SVG document.
     */
    public function sanitize(string $contents): ?string
    {
        if ($this->load($contents) === null) {
            return null;
        }

        $sanitizer = new DOMSanitizer(DOMSanitizer::SVG);
        // <use> is kept when it references an element of the same document
        $sanitizer->addAllowedTags(['use']);
        $sanitizer->setDisallowedTags(array_values(array_diff($sanitizer->getDisallowedTags(), ['use'])));

        $document = $this->load($sanitizer->sanitize($contents, ['compress-output' => false]));
        $root = $document?->documentElement;

        if ($document === null || $root === null || strtolower($root->localName ?? '') !== 'svg') {
            return null;
        }

        foreach (iterator_to_array($document->getElementsByTagNameNS('*', 'use')) as $element) {
            if (! self::hasLocalReference($element) && $element->parentNode instanceof DOMNode) {
                $element->parentNode->removeChild($element);
            }
        }

        $output = $document->saveXML($root);

        return $output !== false ? $output : null;
    }

    private function load(string $contents): ?DOMDocument
    {
        if (trim($contents) === '') {
            return null;
        }

        $document = new DOMDocument;
        $errors = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadXML($contents, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($errors);
        }

        return $loaded && strtolower($document->documentElement->localName ?? '') === 'svg' ? $document : null;
    }

    private static function hasLocalReference(DOMElement $element): bool
    {
        foreach ($element->attributes as $attribute) {
            if (strtolower($attribute->localName ?? '') === 'href' && ! str_starts_with(trim($attribute->value ?? ''), '#')) {
                return false;
            }
        }

        return true;
    }
}
