<?php

namespace Folklore\Mediatheque\Support;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;

class Utils
{
    protected static function getBytes(string $path, int $offset, ?int $length = 1)
    {
        $isUrl = filter_var($path, FILTER_VALIDATE_URL);
        if ($isUrl) {
            $response = Http::withHeaders([
                'Range' => 'bytes=' . $offset . '-' . ($offset + ($length - 1)),
            ])->get($path);
            if (!$response->successful()) {
                return null;
            }
            $body = $response->body();
            return $response->status() === 206 || strlen($body) === $length
                ? $body
                : substr($body, $offset, $length);
        }

        return file_get_contents($path, false, null, $offset, $length);
    }

    public static function isPngBySignature(string $path): bool
    {
        return self::getBytes($path, 0, 8) === "\x89PNG\r\n\x1a\n";
    }

    public static function isPng(string $url): bool
    {
        $isUrl = filter_var($url, FILTER_VALIDATE_URL);
        $path = $isUrl ? parse_url($url, PHP_URL_PATH) ?? '' : $url;
        if (!Str::endsWith(strtolower($path), '.png') && !self::isPngBySignature($url)) {
            return false;
        }
        return true;
    }

    public static function hasTransparency(string $path): bool
    {
        if (self::isPng($path)) {
            $colorByte = self::getBytes($path, 25, 1);
            $colorType = !empty($colorByte) ? ord($colorByte[0]) : null;
            return $colorType === 4 || $colorType === 6;
        }
        return false;
    }
}
