<?php

namespace Folklore\Mediatheque\Probe;

use Folklore\Mediatheque\Enums\TranscoderErrorCode;
use RuntimeException;

/**
 * A provider's answer that fails an embed's probe, with its code.
 *
 * @internal
 */
final class ProviderError extends RuntimeException
{
    public function __construct(
        public readonly TranscoderErrorCode $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
