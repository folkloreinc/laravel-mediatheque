<?php

namespace Folklore\Mediatheque\Ingest;

use Symfony\Component\Mime\MimeTypes;

/**
 * The type of a file, read from its content (docs/decisions/0006): its mime,
 * sniffed with `fileinfo`, and the extension it is stored with, derived from
 * that mime. The client's file name, extension and `Content-Type` are never
 * read.
 */
final class ContentType
{
    public const string SVG = 'image/svg+xml';

    /**
     * Extensions a web server may execute, or a browser render as an active
     * document. A file whose content maps to one of them is never stored.
     */
    public const array BLOCKED_EXTENSIONS = [
        'php', 'php2', 'php3', 'php4', 'php5', 'php6', 'php7', 'php8', 'pht', 'phtm', 'phtml',
        'phar', 'phps', 'pgif', 'inc', 'cgi', 'fcgi', 'pl', 'py', 'pyc', 'rb', 'sh', 'bash',
        'asp', 'aspx', 'asa', 'ascx', 'ashx', 'asmx', 'axd', 'cer', 'config', 'jsp', 'jspx',
        'jsw', 'jsv', 'jspf', 'cfm', 'cfml', 'htaccess', 'htpasswd', 'html', 'htm', 'xhtml',
        'xht', 'shtml', 'shtm', 'stm', 'mht', 'mhtml', 'hta', 'xml', 'xsl', 'xslt', 'svgz',
        'js', 'mjs', 'swf', 'exe', 'dll', 'bat', 'cmd', 'com', 'msi', 'jar',
    ];

    /**
     * Mimes whose files are active documents, whatever their extension.
     */
    public const array BLOCKED_MIMES = [
        'text/html', 'application/xhtml+xml', 'text/xml', 'application/xml',
        'application/javascript', 'text/javascript', 'application/x-javascript',
        'application/x-httpd-php', 'text/x-php', 'application/x-php', 'application/x-sh',
        'text/x-shellscript', 'application/x-msdownload', 'application/java-archive',
        'application/x-shockwave-flash',
    ];

    /**
     * Mimes `fileinfo` gives that have no extension, or a misleading first
     * one, in Symfony's list.
     */
    private const array EXTENSIONS = [
        'application/vnd.ms-opentype' => 'otf',
        'application/font-sfnt' => 'ttf',
        'application/x-font-ttf' => 'ttf',
        'font/sfnt' => 'ttf',
        'font/ttf' => 'ttf',
        'font/woff' => 'woff',
        'font/woff2' => 'woff2',
        'image/jpeg' => 'jpg',
        'audio/mpeg' => 'mp3',
        'audio/mp4' => 'm4a',
        'image/svg+xml' => 'svg',
    ];

    /**
     * The mime of a local file, sniffed from its content.
     */
    public static function sniff(string $path): string
    {
        return strtolower(MimeTypes::getDefault()->guessMimeType($path) ?? 'application/octet-stream');
    }

    /**
     * The extension a file of this mime is stored with.
     */
    public static function extension(string $mime): string
    {
        $mime = strtolower($mime);

        return self::EXTENSIONS[$mime] ?? MimeTypes::getDefault()->getExtensions($mime)[0] ?? 'bin';
    }

    /**
     * Whether files of this mime are never stored, whatever the types allow.
     */
    public static function isBlocked(string $mime): bool
    {
        $mime = strtolower($mime);

        return in_array($mime, self::BLOCKED_MIMES, true)
            || in_array(self::extension($mime), self::BLOCKED_EXTENSIONS, true);
    }

    public static function isSvg(string $mime): bool
    {
        return strtolower($mime) === self::SVG;
    }
}
