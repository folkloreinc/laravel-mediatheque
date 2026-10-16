<?php

namespace Folklore\Mediatheque\Transcoders\Ffmpeg;

use Folklore\Mediatheque\Enums\TranscoderErrorCode;
use Folklore\Mediatheque\Support\Binaries;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Runs ffmpeg with `symfony/process`, without a shell. Reports progress
 * from `-progress` when the input's duration is known, and turns failures
 * into `OperationFailed` with Eclair's error codes.
 */
class Ffmpeg
{
    public function __construct(
        private readonly Binaries $binaries,
    ) {}

    /**
     * @param  list<string>  $arguments  What follows `ffmpeg`, with the output last
     */
    public function run(array $arguments, OperationContext $context, bool $reportsProgress = false): void
    {
        $duration = $reportsProgress ? $context->duration() : null;
        $command = [
            $this->binaries->path('ffmpeg'),
            '-hide_banner',
            '-nostdin',
            '-loglevel', 'error',
            '-y',
            ...($duration !== null && $duration > 0 ? ['-progress', 'pipe:1', '-nostats'] : []),
            ...$arguments,
        ];

        $process = new Process($command);
        $process->setTimeout($context->timeout);
        $reported = 0.0;

        try {
            $process->run(function (string $type, string $output) use ($duration, $context, &$reported) {
                if ($type !== Process::OUT || $duration === null || $duration <= 0) {
                    return;
                }

                if (preg_match_all('/out_time_us=(\d+)/', $output, $matches) > 0) {
                    $progress = min(0.99, ((int) end($matches[1])) / 1_000_000 / $duration);
                    if ($progress - $reported >= 0.05) {
                        $reported = $progress;
                        ($context->progress)($progress);
                    }
                }
            });
        } catch (ProcessTimedOutException) {
            throw OperationFailed::because(TranscoderErrorCode::Timeout, sprintf('ffmpeg took longer than %d seconds.', $context->timeout));
        }

        if (! $process->isSuccessful()) {
            throw OperationFailed::because(TranscoderErrorCode::OperationFailed, 'ffmpeg failed: '.Str::limit(trim($process->getErrorOutput()), 1000));
        }
    }

    /**
     * A `scale` filter that fits the frame in the maximum size, keeps its
     * aspect ratio and even dimensions, and never upscales.
     */
    public static function fit(?int $maxWidth, ?int $maxHeight): ?string
    {
        if ($maxWidth === null && $maxHeight === null) {
            return 'scale=trunc(iw/2)*2:trunc(ih/2)*2';
        }

        return sprintf(
            'scale=w=min(iw\,%d):h=min(ih\,%d):force_original_aspect_ratio=decrease:force_divisible_by=2',
            $maxWidth ?? 100000,
            $maxHeight ?? 100000,
        );
    }

    public static function intOption(OperationContext $context, string $key, ?int $default): ?int
    {
        $value = $context->option($key, $default);

        if ($value !== null && (! is_int($value) || $value <= 0)) {
            throw OperationFailed::because(TranscoderErrorCode::OperationFailed, sprintf('The option "%s" of the rendition "%s" must be a positive integer.', $key, $context->rendition->name));
        }

        return $value;
    }
}
