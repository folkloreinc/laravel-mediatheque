<?php

namespace Folklore\Mediatheque\Tests\Unit\Ingest;

use Folklore\Mediatheque\Ingest\SvgSanitizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The cases of 1.1.2's SVG sanitizer, written again for 2.0.
 */
class SvgSanitizerTest extends TestCase
{
    private function sanitize(string $content, string $attributes = ''): string
    {
        $svg = (new SvgSanitizer)->sanitize(
            '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"'.$attributes.'>'.$content.'</svg>',
        );
        $this->assertNotNull($svg);

        return $svg;
    }

    #[Test]
    public function it_removes_scripts_event_handlers_and_foreign_content(): void
    {
        $svg = $this->sanitize(
            '<script>alert(1)</script>'
                .'<foreignObject><body xmlns="http://www.w3.org/1999/xhtml"><img src="x" onerror="alert(2)"/></body></foreignObject>'
                .'<rect width="10" height="10" onclick="alert(3)"/>'
                .'<a href="javascript:alert(4)"><circle r="2"/></a>'
                .'<a xlink:href="data:text/html;base64,PHNjcmlwdD5hbGVydCg1KTwvc2NyaXB0Pg=="><circle r="3"/></a>'
                .'<set attributeName="href" to="javascript:alert(6)"/>',
            ' onload="alert(7)"',
        );

        $this->assertStringNotContainsString('alert', $svg);
        $this->assertStringNotContainsString('script', $svg);
        $this->assertStringNotContainsString('foreignObject', $svg);
        $this->assertStringNotContainsString('data:text/html', $svg);
        $this->assertStringContainsString('<rect', $svg);
        $this->assertStringContainsString('<circle', $svg);
    }

    #[Test]
    public function it_removes_processing_instructions(): void
    {
        $svg = (string) (new SvgSanitizer)->sanitize(
            '<?xml version="1.0"?><?xml-stylesheet type="text/xsl" href="https://example.com/x.xsl"?>'
                .'<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>',
        );

        $this->assertStringNotContainsString('xml-stylesheet', $svg);
        $this->assertStringContainsString('<rect', $svg);
    }

    #[Test]
    public function it_keeps_local_references_only(): void
    {
        $svg = $this->sanitize(
            '<defs><path id="shape" d="M0 0L10 10"/></defs>'
                .'<use href="#shape"/>'
                .'<use xlink:href="#shape" x="2"/>'
                .'<use href="https://example.com/sprite.svg#icon" x="3"/>'
                .'<use xlink:href="data:image/svg+xml;base64,PHN2Zy8+#a" x="4"/>',
        );

        $this->assertStringContainsString('href="#shape"', $svg);
        $this->assertStringContainsString('xlink:href="#shape"', $svg);
        $this->assertStringNotContainsString('example.com', $svg);
        $this->assertStringNotContainsString('data:image', $svg);
    }

    #[Test]
    public function it_keeps_the_drawing(): void
    {
        $original = (string) file_get_contents(__DIR__.'/../../fixtures/media/image.svg');
        $svg = (string) (new SvgSanitizer)->sanitize($original);

        $this->assertStringContainsString('xmlns="http://www.w3.org/2000/svg"', $svg);
        $this->assertSame(substr_count($original, '<rect'), substr_count($svg, '<rect'));
    }

    #[Test]
    public function it_rejects_files_that_are_not_valid_svg_documents(): void
    {
        $this->assertNull((new SvgSanitizer)->sanitize('not an svg'));
        $this->assertNull((new SvgSanitizer)->sanitize(''));
        $this->assertNull((new SvgSanitizer)->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><rect></svg>'));
        $this->assertNull((new SvgSanitizer)->sanitize('<html><body>test</body></html>'));
    }

    #[Test]
    public function it_never_loads_external_entities(): void
    {
        $svg = (new SvgSanitizer)->sanitize('<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg"><text>&x;</text></svg>');

        $this->assertStringNotContainsString('root:', (string) $svg);
    }
}
