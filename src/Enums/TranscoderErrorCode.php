<?php

namespace Folklore\Mediatheque\Enums;

/**
 * Error codes of transcoders and probers, the same as Eclair's task error
 * codes. Every driver maps its own errors to them.
 */
enum TranscoderErrorCode: string
{
    case InputUnreadable = 'input_unreadable';
    case UnsupportedInput = 'unsupported_input';
    case Timeout = 'timeout';
    case LimitExceeded = 'limit_exceeded';
    case OperationFailed = 'operation_failed';
    case StorageFailed = 'storage_failed';
    case ProviderFailed = 'provider_failed';

    /** The rendition it reads from failed. */
    case DependencyFailed = 'dependency_failed';

    /**
     * The code of a driver's error code. An unknown code is read as
     * `operation_failed`.
     */
    public static function fromDriver(?string $code): self
    {
        return self::tryFrom((string) $code) ?? self::OperationFailed;
    }
}
