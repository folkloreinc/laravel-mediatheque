<?php

namespace Folklore\Mediatheque\Upgrade;

use Closure;
use PhpToken;
use RuntimeException;

/**
 * A 1.x `config/mediatheque.php` as it is written, not as this machine's
 * environment makes it: `mediatheque:upgrade --config` writes a file that
 * every environment of the application loads.
 *
 * The file is evaluated with each `env()` call giving its default, as on a
 * machine where none of the variables is set, and the keys whose value is
 * an `env()` call are listed with their variable, so the rewritten file
 * keeps reading it.
 */
final class LegacyConfigFile
{
    /**
     * The variables that `env()` calls read while a file is evaluated.
     *
     * @var list<string>
     */
    private static array $read = [];

    /**
     * @param  array<array-key, mixed>  $config  The file's array, with the default of each env() call
     * @param  array<string, string>  $env  The environment variable of each key whose value is an env() call, by dot path
     * @param  list<string>  $variables  Every environment variable the file reads
     */
    private function __construct(
        public readonly array $config,
        public readonly array $env,
        public readonly array $variables,
    ) {}

    /**
     * Evaluate a config file with the defaults of its `env()` calls.
     *
     * @throws RuntimeException when the file doesn't return an array
     */
    public static function read(string $path): self
    {
        $contents = (string) file_get_contents($path);
        $tokens = array_values(PhpToken::tokenize($contents));

        $copy = tempnam(sys_get_temp_dir(), 'mediatheque-config-');
        if ($copy === false) {
            throw new RuntimeException('Can\'t create a temporary file to read '.$path.'.');
        }

        self::$read = [];

        try {
            file_put_contents($copy, self::withDefaults($tokens, $path));
            $config = (static fn (string $file): mixed => require $file)($copy);
        } finally {
            @unlink($copy);
        }

        if (! is_array($config)) {
            throw new RuntimeException($path.' doesn\'t return an array.');
        }

        return new self($config, self::envKeys($tokens), array_values(array_unique(self::$read)));
    }

    /**
     * `env()` while a file is read: its default, as when the variable is
     * not set. Called by the evaluated copy of the file.
     *
     * @internal
     */
    public static function env(string $key, mixed $default = null): mixed
    {
        self::$read[] = $key;

        return $default instanceof Closure ? $default() : $default;
    }

    /**
     * The file's code, with each `env()` call made to this class, and
     * `__DIR__` and `__FILE__` kept, so the copy evaluates as the file.
     *
     * @param  list<PhpToken>  $tokens
     */
    private static function withDefaults(array $tokens, string $path): string
    {
        $code = '';

        foreach ($tokens as $index => $token) {
            $code .= match (true) {
                $token->is(T_DIR) => var_export(dirname($path), true),
                $token->is(T_FILE) => var_export($path, true),
                self::isEnvCall($tokens, $index) => '\\'.self::class.'::env',
                default => $token->text,
            };
        }

        return $code;
    }

    /**
     * The keys whose whole value is an `env()` call, by dot path, with the
     * variable it reads. A value that only uses `env()`, like
     * `env('APP_URL').'/storage'`, is not one.
     *
     * @param  list<PhpToken>  $tokens
     * @return array<string, string>
     */
    private static function envKeys(array $tokens): array
    {
        $tokens = array_values(array_filter($tokens, fn (PhpToken $token) => ! $token->isIgnorable()));

        // For each open bracket: whether it opens an array, and the key of
        // its current item
        $arrays = [];
        $itemKeys = [];
        $keys = [];

        foreach ($tokens as $index => $token) {
            $previous = $tokens[$index - 1] ?? null;
            $last = array_key_last($itemKeys);

            if ($token->text === '[' || $token->text === '(') {
                $arrays[] = $token->text === '[' ? $previous === null || ! self::isValue($previous) : $previous?->is(T_ARRAY) === true;
                $itemKeys[] = null;
            } elseif (($token->text === ']' || $token->text === ')') && $last !== null) {
                array_pop($arrays);
                array_pop($itemKeys);
            } elseif ($token->text === ',' && $last !== null) {
                $itemKeys[$last] = null;
            } elseif ($token->is('=>') && $last !== null && $previous !== null) {
                $itemKeys[$last] = self::literal($previous);
            } elseif (self::isEnvCall($tokens, $index) && $previous?->is('=>') === true) {
                $variable = self::literal($tokens[$index + 2] ?? null);
                $close = self::closingParenthesis($tokens, $index + 1);
                $next = $close !== null ? ($tokens[$close + 1] ?? null) : null;
                $path = self::path($arrays, $itemKeys);

                if (is_string($variable) && $path !== null && $next !== null && in_array($next->text, [',', ']', ')'], true)) {
                    $keys[$path] = $variable;
                }
            }
        }

        return $keys;
    }

    /**
     * The dot path of the item that the innermost array is at, through
     * arrays only; null when an array item has no literal key.
     *
     * @param  array<int, bool>  $arrays  Whether each open bracket opens an array
     * @param  array<int, string|int|null>  $itemKeys  The key of the current item of each
     */
    private static function path(array $arrays, array $itemKeys): ?string
    {
        $keys = [];
        foreach ($arrays as $depth => $isArray) {
            if (! $isArray) {
                continue;
            }
            if (($itemKeys[$depth] ?? null) === null) {
                return null;
            }
            $keys[] = $itemKeys[$depth];
        }

        return $keys !== [] ? implode('.', $keys) : null;
    }

    /**
     * @param  list<PhpToken>  $tokens
     */
    private static function isEnvCall(array $tokens, int $index): bool
    {
        $token = $tokens[$index];
        if (! $token->is([T_STRING, T_NAME_FULLY_QUALIFIED]) || strtolower(ltrim($token->text, '\\')) !== 'env') {
            return false;
        }

        $next = self::significant($tokens, $index, 1);
        $previous = self::significant($tokens, $index, -1);

        return $next?->text === '(' && ($previous === null || ! $previous->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST, T_USE]));
    }

    /**
     * @param  list<PhpToken>  $tokens
     */
    private static function significant(array $tokens, int $index, int $step): ?PhpToken
    {
        for ($index += $step; isset($tokens[$index]); $index += $step) {
            if (! $tokens[$index]->isIgnorable()) {
                return $tokens[$index];
            }
        }

        return null;
    }

    /**
     * @param  list<PhpToken>  $tokens
     */
    private static function closingParenthesis(array $tokens, int $open): ?int
    {
        $depth = 0;
        for ($index = $open; isset($tokens[$index]); $index++) {
            $text = $tokens[$index]->text;
            if (in_array($text, ['(', '[', '{'], true) || $tokens[$index]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif (in_array($text, [')', ']', '}'], true) && --$depth === 0) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Whether a token ends a value, so a `[` after it reads an offset.
     */
    private static function isValue(PhpToken $token): bool
    {
        return $token->is([T_VARIABLE, T_STRING, T_CONSTANT_ENCAPSED_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED])
            || in_array($token->text, [')', ']', '}'], true);
    }

    /**
     * The value of a string or integer literal; null for anything else.
     */
    private static function literal(?PhpToken $token): string|int|null
    {
        if ($token?->is(T_LNUMBER) === true) {
            return (int) $token->text;
        }

        if ($token?->is(T_CONSTANT_ENCAPSED_STRING) !== true) {
            return null;
        }

        $quote = $token->text[0];
        $inner = substr($token->text, 1, -1);

        return $quote === "'" ? strtr($inner, ['\\\\' => '\\', "\\'" => "'"]) : stripcslashes($inner);
    }
}
