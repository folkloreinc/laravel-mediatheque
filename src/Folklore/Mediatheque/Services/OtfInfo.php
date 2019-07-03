<?php

namespace Folklore\Mediatheque\Services;

use Folklore\Mediatheque\Contracts\Services\FontFamilyName;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class OtfInfo implements FontFamilyName
{
    /**
     * Get family name from a file
     *
     * @param  string  $path
     * @return string
     */
    public function getFontFamilyName($path)
    {
        try {
            $process = new Process([
                config('mediatheque.services.otfinfo.bin'),
                '-a',
                $path,
            ]);

            $process->run();

            if (!$process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            return trim($process->getOutput());
        } catch (ProcessFailedException $e) {
            $errorOutput = $process->getErrorOutput();
            if (preg_match('/(not an OpenType font|OTF file corrupted)/', $errorOutput)) {
                return null;
            }
            if (config('mediatheque.debug')) {
                throw $e;
            } else {
                Log::error($e);
            }
            return null;
        }
    }
}
