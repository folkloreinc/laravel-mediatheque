<?php

namespace Folklore\Mediatheque\Support;

use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;

/**
 * The binaries of `config('mediatheque.binaries')`, found before they run: a
 * missing binary is a server problem, which throws so the job is retried,
 * rather than a file that fails.
 */
final class Binaries
{
    public function __construct(
        private readonly MediathequeConfig $config,
    ) {}

    /**
     * The path of a binary: `ffmpeg`, `ffprobe`.
     *
     * @throws RuntimeException
     */
    public function path(string $name): string
    {
        $binary = $this->config->binary($name);

        $path = str_contains($binary, '/')
            ? (is_file($binary) && is_executable($binary) ? $binary : null)
            : (new ExecutableFinder)->find($binary);

        return $path ?? throw new RuntimeException(sprintf(
            'The binary "%s" (mediatheque.binaries.%s) is not installed or not executable.',
            $binary,
            $name,
        ));
    }

    public function has(string $name): bool
    {
        try {
            $this->path($name);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }
}
