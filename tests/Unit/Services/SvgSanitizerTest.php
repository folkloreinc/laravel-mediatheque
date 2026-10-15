<?php

namespace Folklore\Mediatheque\Tests\Unit\Services;

use Folklore\Mediatheque\Contracts\Services\SvgSanitizer as SvgSanitizerContract;
use Folklore\Mediatheque\Services\SvgSanitizer;
use Folklore\Mediatheque\Tests\TestCase;

class SvgSanitizerTest extends TestCase
{
    protected function sanitize(string $body, string $attributes = ''): ?string
    {
        return (new SvgSanitizer)->sanitize(
            '<?xml version="1.0" encoding="UTF-8"?>'
                .'<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 10 10"'.$attributes.'>'
                .$body
                .'</svg>'
        );
    }

    public function test_service_is_bound()
    {
        $this->assertInstanceOf(SvgSanitizer::class, app(SvgSanitizerContract::class));
    }

    public function test_removes_scripts_and_event_handlers()
    {
        $svg = $this->sanitize(
            '<script>alert(1)</script>'
                .'<foreignObject><body xmlns="http://www.w3.org/1999/xhtml"><img src="x" onerror="alert(2)"/></body></foreignObject>'
                .'<rect width="10" height="10" onclick="alert(3)"/>'
                .'<a href="javascript:alert(4)"><circle r="2"/></a>'
                .'<a xlink:href="data:text/html;base64,PHNjcmlwdD5hbGVydCg1KTwvc2NyaXB0Pg=="><circle r="3"/></a>'
                .'<set attributeName="href" to="javascript:alert(6)"/>',
            ' onload="alert(7)"'
        );

        $this->assertNotNull($svg);
        $this->assertStringNotContainsString('alert', $svg);
        $this->assertStringNotContainsString('script', $svg);
        $this->assertStringNotContainsString('foreignObject', $svg);
        $this->assertStringNotContainsString('data:text/html', $svg);
        $this->assertStringContainsString('<rect', $svg);
        $this->assertStringContainsString('<circle', $svg);
    }

    public function test_removes_processing_instructions()
    {
        $svg = (new SvgSanitizer)->sanitize(
            '<?xml version="1.0"?><?xml-stylesheet type="text/xsl" href="https://example.com/x.xsl"?>'
                .'<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>'
        );

        $this->assertStringNotContainsString('xml-stylesheet', $svg);
    }

    public function test_keeps_local_references_only()
    {
        $svg = $this->sanitize(
            '<defs><path id="shape" d="M0 0L10 10"/></defs>'
                .'<use href="#shape"/>'
                .'<use xlink:href="#shape" x="2"/>'
                .'<use href="https://example.com/sprite.svg#icon" x="3"/>'
                .'<use xlink:href="data:image/svg+xml;base64,PHN2Zy8+#a" x="4"/>'
        );

        $this->assertStringContainsString('href="#shape"', $svg);
        $this->assertStringContainsString('xlink:href="#shape"', $svg);
        $this->assertStringNotContainsString('example.com', $svg);
        $this->assertStringNotContainsString('data:', $svg);
    }

    public function test_keeps_the_drawing()
    {
        $original = file_get_contents(public_path('image.svg'));
        $svg = (new SvgSanitizer)->sanitize($original);

        $this->assertNotNull($svg);
        $this->assertStringContainsString('xmlns="http://www.w3.org/2000/svg"', $svg);
        $this->assertSame(substr_count($original, '<path'), substr_count($svg, '<path'));
    }

    public function test_rejects_invalid_files()
    {
        $this->assertNull((new SvgSanitizer)->sanitize('not an svg'));
        $this->assertNull((new SvgSanitizer)->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><rect></svg>'));
        $this->assertNull((new SvgSanitizer)->sanitize('<html><body>test</body></html>'));
    }
}
