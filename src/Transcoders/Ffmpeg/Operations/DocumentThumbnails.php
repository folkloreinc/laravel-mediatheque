<?php

namespace Folklore\Mediatheque\Transcoders\Ffmpeg\Operations;

use Folklore\Mediatheque\Enums\TranscoderErrorCode;
use Folklore\Mediatheque\Pipelines\Rendition;
use Folklore\Mediatheque\Support\Binaries;
use Folklore\Mediatheque\Transcoders\Ffmpeg\ChecksRequirements;
use Folklore\Mediatheque\Transcoders\Ffmpeg\Ffmpeg;
use Folklore\Mediatheque\Transcoders\Ffmpeg\LocalOutput;
use Folklore\Mediatheque\Transcoders\Ffmpeg\Operation;
use Folklore\Mediatheque\Transcoders\Ffmpeg\OperationContext;
use Folklore\Mediatheque\Transcoders\Ffmpeg\OperationFailed;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * `document.thumbnails`: images of the pages of a PDF, as a group with
 * indexes from 0, rendered by Poppler (`binaries.pdfinfo` and
 * `binaries.pdftoppm`), never by ImageMagick and Ghostscript
 * (docs/decisions/0025). As Eclair's operation: every page, or the first
 * `count` (a number, or `all`), at `resolution` dots per inch (150), lowered
 * so a page fits in `max_width` and `max_height` (1920 by default), in
 * `format` `jpeg` (the default) or `png`, at `quality` (100), on white.
 * 1000 pages at most. The default `document` pipeline renders the first.
 *
 * Poppler is optional: a document is ready without its thumbnails, so a
 * server without it fails the rendition, before its input is copied, and a
 * run produces it once Poppler is installed.
 */
class DocumentThumbnails implements ChecksRequirements, Operation
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

    public function checkRequirements(Rendition $rendition): void
    {
        self::format($rendition->name, $rendition->options['format'] ?? null);

        foreach (['pdfinfo', 'pdftoppm'] as $binary) {
            if (! $this->binaries->has($binary)) {
                throw OperationFailed::because(TranscoderErrorCode::OperationFailed, sprintf('Poppler\'s %s (mediatheque.binaries.%s) is not installed: install poppler-utils, then run the document pipeline again.', $binary, $binary));
            }
        }
    }

    public function run(OperationContext $context): array
    {
        $this->checkRequirements($context->rendition);
        [$extension, $mime] = self::format($context->rendition->name, $context->option('format'));
        $resolution = min(600, (int) Ffmpeg::intOption($context, 'resolution', 150));
        $quality = min(100, (int) Ffmpeg::intOption($context, 'quality', 100));
        $maxWidth = Ffmpeg::intOption($context, 'max_width', 1920);
        $maxHeight = Ffmpeg::intOption($context, 'max_height', 1920);

        $pages = $this->pages($context, self::count($context));
        if ($pages === []) {
            throw OperationFailed::because(TranscoderErrorCode::UnsupportedInput, 'The document has no page to render.');
        }

        $outputs = [];
        foreach ($pages as $index => [$number, $width, $height]) {
            $prefix = sprintf('%s/page-%d', $context->outputDirectory, $index);
            $this->poppler(['pdftoppm', '-r', sprintf('%.3F', self::fit($resolution, $width, $height, $maxWidth, $maxHeight)), '-f', (string) $number, '-l', (string) $number, '-singlefile', ...($extension === 'jpg' ? ['-jpeg', '-jpegopt', 'quality='.$quality] : ['-png']), $context->input, $prefix], $context);

            $output = $prefix.'.'.$extension;
            $size = is_file($output) ? getimagesize($output) : false;
            if ($size === false || $size[0] <= 1 || $size[1] <= 1) {
                // pdftoppm writes a pixel for a page it can't allocate
                throw OperationFailed::because(TranscoderErrorCode::LimitExceeded, sprintf('Page %d can\'t be rendered: it is too large.', $number));
            }

            $outputs[] = new LocalOutput($output, $mime, $index);
            ($context->progress)(min(0.99, ($index + 1) / count($pages)));
        }

        return $outputs;
    }

    /**
     * The pages to render, in order: their number, from 1, and their size
     * in points as shown, the rotation applied. From pdfinfo.
     *
     * @return list<array{int, float, float}>
     */
    private function pages(OperationContext $context, int $count): array
    {
        $output = $this->poppler(['pdfinfo', '-f', '1', '-l', (string) $count, $context->input], $context);

        $total = preg_match('/^Pages:\s+(\d+)/m', $output, $matches) === 1 ? (int) $matches[1] : 0;
        $rotated = [];
        preg_match_all('/^Page\s+(\d+) rot:\s+(\d+)/m', $output, $rotations, PREG_SET_ORDER);
        foreach ($rotations as [, $number, $degrees]) {
            $rotated[(int) $number] = ((int) $degrees / 90) % 2 === 1;
        }

        $pages = [];
        preg_match_all('/^Page\s+(\d+) size:\s+([0-9.]+) x ([0-9.]+) pts/m', $output, $sizes, PREG_SET_ORDER);
        foreach ($sizes as [, $number, $width, $height]) {
            if ((int) $number <= min($count, $total)) {
                $pages[] = ($rotated[(int) $number] ?? false) ? [(int) $number, (float) $height, (float) $width] : [(int) $number, (float) $width, (float) $height];
            }
        }

        return $pages;
    }

    /**
     * Run a Poppler binary, and return what it writes.
     *
     * @param  non-empty-list<string>  $command  The binary's name, then its arguments
     */
    private function poppler(array $command, OperationContext $context): string
    {
        $command[0] = $this->binaries->path($command[0]);
        $process = new Process($command);
        $process->setTimeout($context->timeout);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw OperationFailed::because(TranscoderErrorCode::Timeout, sprintf('%s took longer than %d seconds.', basename($command[0]), $context->timeout));
        }

        if (! $process->isSuccessful()) {
            throw OperationFailed::because(TranscoderErrorCode::InputUnreadable, 'Poppler can\'t read the file as a PDF: '.Str::limit(trim($process->getErrorOutput()), 1000));
        }

        return $process->getOutput();
    }

    /**
     * The number of pages to render: `count`, a positive number or `all`.
     */
    private static function count(OperationContext $context): int
    {
        $count = $context->option('count', 'all');

        return $count === 'all' ? self::MAX_PAGES : min(self::MAX_PAGES, (int) Ffmpeg::intOption($context, 'count', null));
    }

    /**
     * The resolution that renders a page of this size, in points, within
     * the maximum size, in pixels.
     */
    private static function fit(int $resolution, float $width, float $height, ?int $maxWidth, ?int $maxHeight): float
    {
        $scale = min(
            1.0,
            $maxWidth !== null && $width > 0 ? $maxWidth / ($width / 72 * $resolution) : 1.0,
            $maxHeight !== null && $height > 0 ? $maxHeight / ($height / 72 * $resolution) : 1.0,
        );

        return max(1.0, floor($resolution * $scale * 1000) / 1000);
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
