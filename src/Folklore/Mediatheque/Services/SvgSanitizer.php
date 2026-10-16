<?php

namespace Folklore\Mediatheque\Services;

use DOMDocument;
use DOMElement;
use Folklore\Mediatheque\Contracts\Services\SvgSanitizer as SvgSanitizerContract;
use Rhukster\DomSanitizer\DOMSanitizer;

class SvgSanitizer implements SvgSanitizerContract
{
    /**
     * Remove scripts, event handlers and external references from an SVG
     */
    public function sanitize(string $contents): ?string
    {
        $sanitizer = new DOMSanitizer(DOMSanitizer::SVG);
        // <use> is kept when it references an element of the same document
        $sanitizer->addAllowedTags(['use']);
        $sanitizer->setDisallowedTags(array_values(array_diff($sanitizer->getDisallowedTags(), ['use'])));
        $sanitized = $sanitizer->sanitize($contents, [
            'compress-output' => false,
        ]);

        $document = $this->loadDocument($sanitized);
        $root = ! is_null($document) ? $document->documentElement : null;
        if (is_null($root) || strtolower($root->localName) !== 'svg') {
            return null;
        }

        foreach (iterator_to_array($document->getElementsByTagNameNS('*', 'use')) as $element) {
            if (! $this->hasLocalReference($element)) {
                $element->parentNode->removeChild($element);
            }
        }

        $output = $document->saveXML($root);

        return $output !== false ? $output : null;
    }

    protected function loadDocument(string $contents): ?DOMDocument
    {
        if (trim($contents) === '') {
            return null;
        }

        $document = new DOMDocument;
        $internalErrors = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadXML($contents, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($internalErrors);
        }

        return $loaded ? $document : null;
    }

    protected function hasLocalReference(DOMElement $element): bool
    {
        foreach ($element->attributes as $attribute) {
            if (strtolower($attribute->localName) === 'href' && strpos(trim($attribute->value), '#') !== 0) {
                return false;
            }
        }

        return true;
    }
}
