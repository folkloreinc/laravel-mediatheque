<?php

namespace Folklore\Mediatheque\Services;

use Folklore\Mediatheque\Contracts\Services\AnimatedImage;

class Webp implements AnimatedImage
{
    /**
     * Check if a gif is animated
     *
     * @param  string  $path
     * @return bool
     */
    public function isAnimated(string $path): bool
    {
        $info = $this->webpInfo($path);
        return !is_null($info) ? $info['animated'] : false;
    }

    /**
     * Get the number of frames of a gif
     *
     * @param  string  $path
     * @return int|null
     */
    public function framesCount(string $path): ?int
    {
        return null;
    }

    protected function webpInfo(string $path)
    {
        // https://github.com/webmproject/libwebp/blob/master/src/dec/webp_dec.c
        // https://developers.google.com/speed/webp/docs/riff_container
        // https://developers.google.com/speed/webp/docs/webp_lossless_bitstream_specification
        // https://stackoverflow.com/questions/61221874/detect-if-a-webp-image-is-transparent-in-php

        $fp = fopen($path, 'rb');
        if (!$fp) {
            return null;
        }
        $buf = fread($fp, 25);
        fclose($fp);

        switch (true) {
            case !is_string($buf):
            case strlen($buf) < 25:
            case substr($buf, 0, 4) != 'RIFF':
            case substr($buf, 8, 4) != 'WEBP':
            case substr($buf, 12, 3) != 'VP8':
                return null;

            case $buf[15] == ' ':
                // Simple File Format (Lossy)
                return [
                    'type' => 'VP8',
                    'animated' => false,
                    'transparent' => false,
                ];

            case $buf[15] == 'L':
                // Simple File Format (Lossless)
                return [
                    'type' => 'VP8L',
                    'animated' => false,
                    'transparent' => (bool) !!(ord($buf[24]) & 0x00000010),
                ];
            case $buf[15] == 'X':
                // Extended File Format
                return [
                    'type' => 'VP8X',
                    'animated' => (bool) !!(ord($buf[20]) & 0x00000002),
                    'transparent' => (bool) !!(ord($buf[20]) & 0x00000010),
                ];

            default:
                return null;
        }
    }
}
