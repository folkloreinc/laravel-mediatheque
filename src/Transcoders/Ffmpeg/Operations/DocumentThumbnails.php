<?php

namespace Folklore\Mediatheque\Transcoders\Ffmpeg\Operations;

use Folklore\Mediatheque\Enums\TranscoderErrorCode;
use Folklore\Mediatheque\Pipelines\Rendition;
use Folklore\Mediatheque\Support\Binaries;
use Folklore\Mediatheque\Transcoders\Ffmpeg\Ffmpeg;
use Folklore\Mediatheque\Transcoders\Ffmpeg\LocalOutput;
use Folklore\Mediatheque\Transcoders\Ffmpeg\Operation;
use Folklore\Mediatheque\Transcoders\Ffmpeg\OperationContext;
use Folklore\Mediatheque\Transcoders\Ffmpeg\OperationFailed;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * `document.thumbnails`: one image per page of a PDF, as a group with
 * indexes from 0, rendered by Poppler's `pdftoppm` (`binaries.pdftoppm`),
 * never by ImageMagick and Ghostscript (docs/decisions/0025). As Eclair's
 * operation and 1.x's `Thumbnails` job: every page by default, or the first
 * `count`, at `resolution` dots per inch (150), in `format` `jpeg` (the
 * default) or `png`, at `quality` (100), on white. 1000 pages at most.
 *
 * Poppler is optional: a document is ready without its thumbnails, so a
 * server without pdftoppm fails the rendition, which a run can produce once
 * it is installed, instead of retrying it.
 */
class DocumentThumbnails implements Operation
{
    /**
     * The most pages one rendition renders, as Eclair's operation.
     */
    public const int MAX_PAGES = 1000;

    private const array FORMATS = ['jpeg' => ['jpg', 'image/jpeg'], 'png' => ['png', 'image/png']];

    public function __construct(
        private readonly Binaries $binaries,
    ) {}

    public function extension(Rendition $rendition): string
    {
        return self::format($rendition->name, $rendition->options['format'] ?? null)[0];
    }

    public function run(OperationContext $context): array
    {
        [$extension, $mime] = self::format($context->rendition->name, $context->option('format'));

        if (! $this->binaries->has('pdftoppm')) {
            throw OperationFailed::because(TranscoderErrorCode::OperationFailed, 'Poppler\'s pdftoppm (mediatheque.binaries.pdftoppm) is not installed: install poppler-utils, then run the document pipeline again.');
        }

        $count = min(self::MAX_PAGES, (int) (Ffmpeg::intOption($context, 'count', null) ?? self::MAX_PAGES));
        $resolution = (int) Ffmpeg::intOption($context, 'resolution', 150);
        $quality = (int) Ffmpeg::intOption($context, 'quality', 100);
        $prefix = $context->outputDirectory.'/page';

        $process = new Process([
            $this->binaries->path('pdftoppm'),
            '-progress',
            '-r', (string) min($resolution, 600),
            '-f', '1',
            '-l', (string) $count,
            ...($extension === 'jpg' ? ['-jpeg', '-jpegopt', 'quality='.min($quality, 100)] : ['-png']),
            $context->input,
            $prefix,
        ]);
        $process->setTimeout($context->timeout);

        try {
            $process->run(function (string $type, string $output) use ($context, $count) {
                // `-progress` writes "page last-page file" for each page
                if ($type === Process::ERR && preg_match_all('/^(\d+) (\d+) /m', $output, $matches) > 0) {
                    ($context->progress)(min(0.99, (int) end($matches[1]) / max(1, min($count, (int) end($matches[2])))));
                }
            });
        } catch (ProcessTimedOutException) {
            throw OperationFailed::because(TranscoderErrorCode::Timeout, sprintf('pdftoppm took longer than %d seconds.', $context->timeout));
        }

        if (! $process->isSuccessful()) {
            throw OperationFailed::because(TranscoderErrorCode::InputUnreadable, 'pdftoppm can\'t read the file as a PDF: '.Str::limit(trim(preg_replace('/^\d+ \d+ .*$/m', '', $process->getErrorOutput()) ?? ''), 1000));
        }

        $pages = self::pages($prefix, $extension);
        if ($pages === []) {
            throw OperationFailed::because(TranscoderErrorCode::UnsupportedInput, 'The document has no page to render.');
        }

        $outputs = [];
        foreach (array_values($pages) as $index => $path) {
            $outputs[] = new LocalOutput($path, $mime, $index);
        }

        return $outputs;
    }

    /**
     * The pages pdftoppm wrote, by page number: `page-1.jpg`, or
     * `page-01.jpg` when the document has ten pages or more.
     *
     * @return array<int, string>
     */
    private static function pages(string $prefix, string $extension): array
    {
        $pages = [];
        foreach (glob($prefix.'-*.'.$extension) ?: [] as $path) {
            if (preg_match('/-(\d+)\.'.$extension.'$/', $path, $matches) === 1) {
                $pages[(int) $matches[1]] = $path;
            }
        }
        ksort($pages);

        return $pages;
    }

    /**
     * The extension and mime of a format.
     *
     * @return array{string, string}
     */
    private static function format(string $rendition, mixed $format): array
    {
        return self::FORMATS[is_string($format) ? $format : 'jpeg']
            ?? throw OperationFailed::because(TranscoderErrorCode::OperationFailed, sprintf('The option "format" of the rendition "%s" must be %s.', $rendition, implode(', ', array_keys(self::FORMATS))));
    }
}
