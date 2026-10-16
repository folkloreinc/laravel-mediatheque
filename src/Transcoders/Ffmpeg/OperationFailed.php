<?php

namespace Folklore\Mediatheque\Transcoders\Ffmpeg;

use Folklore\Mediatheque\Enums\TranscoderErrorCode;
use Folklore\Mediatheque\Exceptions\MediathequeException;
use Folklore\Mediatheque\Transcoders\TranscoderError;
use RuntimeException;

/**
 * An operation that can't produce its rendition from this input. Retrying
 * won't help: the rendition fails at once.
 */
class OperationFailed extends RuntimeException implements MediathequeException
{
    public function __construct(
        public readonly TranscoderError $error,
    ) {
        parent::__construct($error->message);
    }

    public static function because(TranscoderErrorCode $code, string $message): self
    {
        return new self(new TranscoderError($code, $message));
    }
}
