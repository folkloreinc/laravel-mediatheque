<?php

namespace Folklore\Mediatheque\Pipelines;

use Folklore\Mediatheque\Contracts\Models\File;
use Folklore\Mediatheque\Exceptions\InvalidPipeline;

/**
 * A declarative description of one output (docs/decisions/0005, 0011): an
 * operation in Eclair's vocabulary (`video.transcode`), its options, the
 * rendition it reads from, and a condition.
 */
final readonly class Rendition
{
    /**
     * Handles start with a letter, then letters, digits, `_` and `-`, 64
     * characters at most: the rule of Eclair's task names.
     */
    public const string HANDLE_PATTERN = '/^[A-Za-z][A-Za-z0-9_-]{0,63}$/';

    public const string DEFAULT_ROLE = 'main';

    private const array KEYS = ['op', 'from', 'when', 'keep', 'queue', 'role'];

    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public string $name,
        public string $operation,
        public array $options = [],
        public ?string $from = null,
        public RenditionCondition $when = new RenditionCondition,
        public bool $keep = true,
        public ?string $queue = null,
        public string $role = self::DEFAULT_ROLE,
    ) {
        self::assertHandle($name);

        if ($operation === '') {
            throw new InvalidPipeline(sprintf('The rendition "%s" has no operation (`op`).', $name));
        }

        if ($from === $name) {
            throw new InvalidPipeline(sprintf('The rendition "%s" reads from itself.', $name));
        }
    }

    /**
     * From the config: `['op' => 'video.transcode', 'codec' => 'h264', 'from' => …]`.
     * Keys other than op, from, when, keep, queue and role are the
     * operation's options.
     *
     * @param  array<array-key, mixed>  $definition
     */
    public static function fromArray(string $name, array $definition): self
    {
        self::assertHandle($name);

        $operation = $definition['op'] ?? null;
        $from = $definition['from'] ?? null;
        $when = $definition['when'] ?? [];
        $keep = $definition['keep'] ?? true;
        $queue = $definition['queue'] ?? null;
        $role = $definition['role'] ?? self::DEFAULT_ROLE;

        if (! is_string($operation)) {
            throw new InvalidPipeline(sprintf('The rendition "%s" has no operation (`op`).', $name));
        }
        if ($from !== null && ! is_string($from)) {
            throw new InvalidPipeline(sprintf('The rendition "%s" has an invalid `from`: expected a rendition name.', $name));
        }
        if (! is_array($when)) {
            throw new InvalidPipeline(sprintf('The rendition "%s" has an invalid `when`: expected an array.', $name));
        }
        if (! is_bool($keep)) {
            throw new InvalidPipeline(sprintf('The rendition "%s" has an invalid `keep`: expected a boolean.', $name));
        }
        if ($queue !== null && ! is_string($queue)) {
            throw new InvalidPipeline(sprintf('The rendition "%s" has an invalid `queue`: expected a queue name.', $name));
        }
        if (! is_string($role) || $role === '') {
            throw new InvalidPipeline(sprintf('The rendition "%s" has an invalid `role`.', $name));
        }

        $options = [];
        foreach ($definition as $key => $value) {
            if (! in_array($key, self::KEYS, true)) {
                $options[(string) $key] = $value;
            }
        }

        return new self(
            name: $name,
            operation: $operation,
            options: $options,
            from: $from,
            when: RenditionCondition::fromArray($name, $when),
            keep: $keep,
            queue: $queue,
            role: $role,
        );
    }

    /**
     * The rendition with options of one run (docs/decisions/0027): they
     * replace the options of the same name, others keep their value. An
     * option can't be named like one of the rendition's own keys, and its
     * value must be one JSON can hold, since the run's snapshot stores it.
     *
     * @param  array<array-key, mixed>  $options
     *
     * @throws InvalidPipeline
     */
    public function withOptions(array $options): self
    {
        foreach ($options as $key => $value) {
            if (! is_string($key) || $key === '') {
                throw new InvalidPipeline(sprintf('The options of the rendition "%s" must be keyed by option name.', $this->name));
            }
            if (in_array($key, self::KEYS, true)) {
                throw new InvalidPipeline(sprintf('The option "%s" of the rendition "%s" is one of the rendition\'s own keys (%s), which a run can\'t change.', $key, $this->name, implode(', ', self::KEYS)));
            }
            if (! self::isJsonValue($value)) {
                throw new InvalidPipeline(sprintf('The option "%s" of the rendition "%s" must be a value JSON can hold: null, a boolean, a number, a string or an array of these.', $key, $this->name));
            }
        }

        if ($options === []) {
            return $this;
        }

        return new self(
            name: $this->name,
            operation: $this->operation,
            options: array_replace($this->options, $options),
            from: $this->from,
            when: $this->when,
            keep: $this->keep,
            queue: $this->queue,
            role: $this->role,
        );
    }

    public static function isValidHandle(string $handle): bool
    {
        return preg_match(self::HANDLE_PATTERN, $handle) === 1 && $handle !== File::ORIGINAL;
    }

    /**
     * The definition as stored in a run's JSON snapshot. fromArray() reads it
     * back.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'op' => $this->operation,
            ...$this->sortedOptions(),
            'from' => $this->from,
            'when' => $this->when->isEmpty() ? null : $this->when->toArray(),
            'keep' => $this->keep ? null : false,
            'queue' => $this->queue,
            'role' => $this->role === self::DEFAULT_ROLE ? null : $this->role,
        ], fn (mixed $value) => $value !== null);
    }

    /**
     * A hash of what the output depends on: the operation, its options and
     * the rendition it reads from. The order of options doesn't change it;
     * `when`, `keep`, `queue` and `role` don't either. A file records the
     * hash of the rendition that produced it, so a rendition whose hash
     * didn't change is not produced again.
     */
    public function definitionHash(): string
    {
        return hash('sha256', (string) json_encode([
            'op' => $this->operation,
            'options' => $this->sortedOptions(),
            'from' => $this->from,
        ], JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function assertHandle(string $name): void
    {
        if (! self::isValidHandle($name)) {
            throw new InvalidPipeline(sprintf(
                'The rendition name "%s" is invalid: it must start with a letter, then letters, digits, "_" and "-", 64 characters at most, and not be "original".',
                $name,
            ));
        }
    }

    private static function isJsonValue(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if (! self::isJsonValue($item)) {
                    return false;
                }
            }

            return true;
        }

        return $value === null
            || is_bool($value)
            || is_int($value)
            || (is_float($value) && is_finite($value))
            || (is_string($value) && preg_match('//u', $value) === 1);
    }

    /**
     * The options, with their keys sorted at every level.
     *
     * @return array<string, mixed>
     */
    private function sortedOptions(): array
    {
        $options = $this->options;
        ksort($options);

        return array_map(fn (mixed $value) => is_array($value) ? self::sortKeys($value) : $value, $options);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private static function sortKeys(array $values): array
    {
        if (! array_is_list($values)) {
            ksort($values);
        }

        return array_map(fn (mixed $value) => is_array($value) ? self::sortKeys($value) : $value, $values);
    }
}
