<?php

namespace Folklore\Mediatheque\Support;

use DateTimeInterface;
use Folklore\Mediatheque\Exceptions\InvalidUid;
use Illuminate\Support\Str;

/**
 * Prefixed ULIDs: `med_01JAB3QX…` for media, `upl_…` for uploads
 * (docs/decisions/0003).
 */
final class Uid
{
    private const string PREFIX_PATTERN = '[a-z][a-z0-9]*';

    /** Crockford's base 32, first character 0 to 7. */
    private const string ULID_PATTERN = '[0-7][0-9A-HJKMNP-TV-Z]{25}';

    /**
     * A new uid. With a time, the ULID sorts at that time: uids backfilled
     * for existing media use their creation date.
     */
    public static function generate(string $prefix, ?DateTimeInterface $time = null): string
    {
        self::assertPrefix($prefix);

        return $prefix.'_'.Str::ulid($time)->toBase32();
    }

    /**
     * Whether a value is a uid, with this prefix when one is given.
     */
    public static function isValid(string $value, ?string $prefix = null): bool
    {
        if ($prefix !== null) {
            self::assertPrefix($prefix);
        }

        $pattern = $prefix !== null ? preg_quote($prefix, '/') : self::PREFIX_PATTERN;

        return preg_match('/^'.$pattern.'_'.self::ULID_PATTERN.'$/', $value) === 1;
    }

    /**
     * The prefix of a uid, without its underscore.
     */
    public static function prefixOf(string $uid): ?string
    {
        return self::isValid($uid) ? Str::before($uid, '_') : null;
    }

    private static function assertPrefix(string $prefix): void
    {
        if (preg_match('/^'.self::PREFIX_PATTERN.'$/', $prefix) !== 1) {
            throw InvalidUid::prefix($prefix);
        }
    }
}
