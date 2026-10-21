<?php

namespace Folklore\Mediatheque\Dashboard\Controllers;

use Folklore\Mediatheque\Dashboard\Gates;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The dashboard's page and its precompiled assets, served from `dist/`:
 * the application builds nothing.
 */
final class PageController
{
    private const array TYPES = ['js' => 'text/javascript; charset=utf-8', 'css' => 'text/css; charset=utf-8'];

    public function show(Request $request, Gates $gates): Response
    {
        $base = rtrim($request->url(), '/');
        $script = self::dist('dashboard.js');
        $style = self::dist('dashboard.css');

        if (! is_file($script)) {
            return new Response('The dashboard is not built: run `npm ci && npm run build` in the package\'s dashboard/ folder.', 503, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $config = json_encode([
            'api' => $base.'/api',
            'csrfToken' => $request->hasSession() ? $request->session()->token() : null,
            'canOperate' => $gates->allows($request, Gates::OPERATE),
        ], JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        $href = e($base);
        $scriptVersion = self::version($script);
        $stylesheet = is_file($style)
            ? sprintf('<link rel="stylesheet" href="%s/assets/dashboard.css?v=%s">', $href, self::version($style))
            : '';

        $html = <<<HTML
            <!doctype html>
            <html lang="en">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <meta name="robots" content="noindex">
                <title>Mediatheque</title>
                {$stylesheet}
            </head>
            <body>
                <div id="mediatheque-dashboard" data-config='{$config}'></div>
                <script type="module" src="{$href}/assets/dashboard.js?v={$scriptVersion}"></script>
            </body>
            </html>
            HTML;

        return new Response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Frame-Options' => 'DENY',
        ]);
    }

    public function asset(Request $request): BinaryFileResponse|Response
    {
        $file = $request->route('file');
        $path = is_string($file) ? self::dist($file) : '';

        if (! is_string($file) || ! is_file($path)) {
            return new Response('Not found', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        return new BinaryFileResponse($path, 200, [
            'Content-Type' => self::TYPES[pathinfo($file, PATHINFO_EXTENSION)] ?? 'application/octet-stream',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Part of the file's hash, so a new build is never served from a cache.
     */
    private static function version(string $file): string
    {
        return substr((string) md5_file($file), 0, 12);
    }

    private static function dist(string $file): string
    {
        return dirname(__DIR__, 3).'/dist/'.basename($file);
    }
}
