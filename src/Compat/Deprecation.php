<?php

namespace Folklore\Mediatheque\Compat;

/**
 * Reports the use of a 1.x API that the compatibility layer keeps working
 * until 3.0 (docs/decisions/0002).
 *
 * It triggers an `E_USER_DEPRECATED` error, which Laravel logs on the
 * `deprecations` channel (`LOG_DEPRECATIONS_CHANNEL`), and which tests can
 * show (`LOG_DEPRECATIONS_WHILE_TESTING`). Each message is reported once per
 * process, so a loop over media logs it once.
 */
final class Deprecation
{
    /**
     * @var array<string, true>
     */
    private static array $reported = [];

    public static function trigger(string $message): void
    {
        if (isset(self::$reported[$message])) {
            return;
        }

        self::$reported[$message] = true;

        @trigger_error('folklore/laravel-mediatheque: '.$message, E_USER_DEPRECATED);
    }

    /**
     * The messages reported since the last flush().
     *
     * @return list<string>
     */
    public static function reported(): array
    {
        return array_keys(self::$reported);
    }

    /**
     * Report every message again, in tests.
     */
    public static function flush(): void
    {
        self::$reported = [];
    }
}
