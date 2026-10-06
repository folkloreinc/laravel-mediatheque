<?php

namespace Folklore\Mediatheque\Services;

use Exception;
use Folklore\Mediatheque\Contracts\Services\FontFamilyName;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class OtfInfo implements FontFamilyName
{
    /**
     * Get family name from a file
     */
    public function getFontFamilyName(string $path): ?string
    {
        try {
            $process = new Process([
                config('mediatheque.services.otfinfo.bin'),
                '-a',
                $path,
            ]);

            $process->run();

            if (! $process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            return trim($process->getOutput());
        } catch (Exception $e) {
            if (config('mediatheque.debug')) {
                throw $e;
            } else {
                Log::error($e);
            }

            return null;
        }
    }
}
