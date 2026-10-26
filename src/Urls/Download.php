<?php

namespace Folklore\Mediatheque\Urls;

use Folklore\Mediatheque\Contracts\Models\File;
use Folklore\Mediatheque\Contracts\Models\Media;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * What a temporary URL that downloads its file asks for (#102): the name to
 * save the file under, and the `Content-Disposition` header the source
 * sends with it.
 */
final readonly class Download
{
    private function __construct(
        public string $filename,
    ) {}

    /**
     * The download a temporary URL of a file asks for, or null to show the
     * file.
     *
     * - `true` takes the file's name, which can come from a client: it is
     *   cleaned, and the last segment of the file's path replaces a name
     *   that is empty.
     * - A string is the name, given by the application: it must be a file
     *   name, without a directory or control characters.
     *
     * @throws InvalidArgumentException when the name given is not a file name
     */
    public static function of(File $file, bool|string $download): ?self
    {
        if ($download === false) {
            return null;
        }

        if (is_string($download)) {
            if (! self::isFilename($download)) {
                throw new InvalidArgumentException(sprintf('"%s" is not a file name to download a file under.', $download));
            }

            return new self($download);
        }

        foreach ([$file->name(), basename((string) $file->path())] as $name) {
            $name = self::clean($name);

            if ($name !== null) {
                return new self($name);
            }
        }

        return new self('download');
    }

    /**
     * What a media's temporary URL asks for: as of(), except that `true`
     * takes the media's name, with the extension of the file it serves
     * (`episode-1` gives `episode-1.mp4`), unless the name already ends with
     * it. A media without a name downloads under its file's name.
     *
     * @throws InvalidArgumentException when the name given is not a file name
     */
    public static function ofMedia(Media $media, File $file, bool|string $download): ?self
    {
        $name = $download === true ? self::clean($media->name()) : null;

        if ($name === null) {
            return self::of($file, $download);
        }

        $extension = pathinfo((string) $file->path(), PATHINFO_EXTENSION);
        if ($extension === '' || preg_match('/^[A-Za-z0-9]+$/', $extension) !== 1 || str_ends_with(strtolower($name), '.'.strtolower($extension))) {
            return new self($name);
        }

        return new self($name.'.'.$extension);
    }

    /**
     * The `Content-Disposition` header of the download, as RFC 6266 writes
     * it: `filename*` has the name in UTF-8, and `filename` an ASCII
     * fallback for the clients that don't read it.
     */
    public function contentDisposition(): string
    {
        return sprintf(
            'attachment; filename="%s"; filename*=UTF-8\'\'%s',
            $this->asciiFilename(),
            rawurlencode($this->filename),
        );
    }

    /**
     * The name in printable ASCII, without the characters a quoted string
     * escapes, or `%`, which some clients decode.
     */
    private function asciiFilename(): string
    {
        $ascii = (string) preg_replace('/[^\x20-\x7E]|["\\\\%]/', '_', Str::ascii($this->filename));

        if (preg_match('/[A-Za-z0-9]/', pathinfo($ascii, PATHINFO_FILENAME)) === 1) {
            return $ascii;
        }

        $extension = pathinfo($ascii, PATHINFO_EXTENSION);

        return 'download'.(preg_match('/^[A-Za-z0-9]+$/', $extension) === 1 ? '.'.$extension : '');
    }

    /**
     * A name that can come from a client, as a file name: control
     * characters and slashes replaced, or null when nothing is left.
     */
    private static function clean(?string $name): ?string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F\/\\\\]+/', '_', (string) $name));

        return self::isFilename($name) ? $name : null;
    }

    private static function isFilename(string $name): bool
    {
        return trim($name) !== ''
            && $name !== '.'
            && $name !== '..'
            && preg_match('/[\x00-\x1F\x7F\/\\\\]/', $name) !== 1;
    }
}
