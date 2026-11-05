<?php

namespace Folklore\Mediatheque\Tests\Support;

use Folklore\Mediatheque\Enums\TranscoderErrorCode;
use Folklore\Mediatheque\Pipelines\Rendition;
use Folklore\Mediatheque\Transcoders\Ffmpeg\ChecksRequirements;
use Folklore\Mediatheque\Transcoders\Ffmpeg\Ffmpeg;
use Folklore\Mediatheque\Transcoders\Ffmpeg\LocalOutput;
use Folklore\Mediatheque\Transcoders\Ffmpeg\Operation;
use Folklore\Mediatheque\Transcoders\Ffmpeg\OperationContext;
use Folklore\Mediatheque\Transcoders\Ffmpeg\OperationFailed;

/**
 * An application's operation of the ffmpeg driver (docs/decisions/0027):
 * `app.trim` cuts `duration` seconds from `start`, as a 1.x custom job did
 * with options built for one run.
 */
class TrimOperation implements ChecksRequirements, Operation
{
    public function __construct(
        private readonly Ffmpeg $ffmpeg,
    ) {}

    public function checkRequirements(Rendition $rendition): void
    {
        self::seconds($rendition, 'start', 0.0);
        if (self::seconds($rendition, 'duration', null) === null) {
            throw OperationFailed::because(TranscoderErrorCode::OperationFailed, sprintf('The rendition "%s" needs a duration.', $rendition->name));
        }
    }

    public function extension(Rendition $rendition): string
    {
        return 'mp4';
    }

    public function run(OperationContext $context): array
    {
        $output = $context->outputDirectory.'/trim.mp4';

        $this->ffmpeg->run([
            '-ss', (string) self::seconds($context->rendition, 'start', 0.0),
            '-i', $context->input,
            '-t', (string) self::seconds($context->rendition, 'duration', null),
            '-c:v', 'libx264', '-preset', 'ultrafast',
            '-an',
            $output,
        ], $context);

        return [new LocalOutput($output, 'video/mp4')];
    }

    private static function seconds(Rendition $rendition, string $key, ?float $default): ?float
    {
        $value = $rendition->options[$key] ?? $default;

        if ($value !== null && (! is_int($value) && ! is_float($value) || $value < 0 || ($key === 'duration' && $value <= 0))) {
            throw OperationFailed::because(TranscoderErrorCode::OperationFailed, sprintf('The option "%s" of the rendition "%s" must be a positive number of seconds.', $key, $rendition->name));
        }

        return $value === null ? null : (float) $value;
    }
}
