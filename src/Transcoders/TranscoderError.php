<?php

namespace Folklore\Mediatheque\Transcoders;

use Folklore\Mediatheque\Enums\TranscoderErrorCode;

/**
 * Why a rendition or a probe failed: a code every driver shares, and a
 * message for humans. Stored as `{code, message}`.
 */
final readonly class TranscoderError
{
    public function __construct(
        public TranscoderErrorCode $code,
        public string $message,
    ) {}

    /**
     * From a driver's own code. An unknown code is read as `operation_failed`.
     */
    public static function fromDriver(?string $code, string $message): self
    {
        return new self(TranscoderErrorCode::fromDriver($code), $message);
    }

    /**
     * The error of a rendition whose input rendition failed.
     */
    public static function dependencyFailed(string $rendition): self
    {
        return new self(TranscoderErrorCode::DependencyFailed, sprintf('The rendition "%s" it reads from failed.', $rendition));
    }

    /**
     * @param  array{code?: mixed, message?: mixed}  $error
     */
    public static function fromArray(array $error): self
    {
        $code = $error['code'] ?? null;
        $message = $error['message'] ?? null;

        return self::fromDriver(is_string($code) ? $code : null, is_string($message) ? $message : '');
    }

    /**
     * @return array{code: string, message: string}
     */
    public function toArray(): array
    {
        return ['code' => $this->code->value, 'message' => $this->message];
    }
}
