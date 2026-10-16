<?php

namespace Folklore\Mediatheque\Pipelines;

use Folklore\Mediatheque\Enums\ProbeKind;
use Folklore\Mediatheque\Exceptions\InvalidPipeline;
use Folklore\Mediatheque\Metadata\MetadataKey;
use Illuminate\Support\Str;

/**
 * The `when` of a rendition: the rendition is produced only if the probed
 * input matches, and is `skipped` otherwise (docs/decisions/0011).
 */
final readonly class RenditionCondition
{
    private const array KEYS = ['kind', 'mime', 'min_duration', 'max_duration'];

    /**
     * @param  list<ProbeKind>  $kinds
     * @param  list<string>  $mimes  Patterns, such as `video/*`
     */
    public function __construct(
        public array $kinds = [],
        public array $mimes = [],
        public ?float $minDuration = null,
        public ?float $maxDuration = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $when
     */
    public static function fromArray(string $rendition, array $when): self
    {
        $unknown = array_diff(array_map('strval', array_keys($when)), self::KEYS);
        if ($unknown !== []) {
            throw new InvalidPipeline(sprintf(
                'The rendition "%s" has an unknown condition "%s": `when` takes %s.',
                $rendition,
                implode('", "', $unknown),
                implode(', ', self::KEYS),
            ));
        }

        $kinds = [];
        foreach (self::strings($rendition, 'kind', $when['kind'] ?? []) as $kind) {
            $kinds[] = ProbeKind::tryFrom($kind) ?? throw new InvalidPipeline(sprintf(
                'The rendition "%s" has the condition `when.kind: %s`, which is not a probe kind. Use %s; a pipeline is already selected by the media type.',
                $rendition,
                $kind,
                implode(', ', array_column(ProbeKind::cases(), 'value')),
            ));
        }

        return new self(
            kinds: $kinds,
            mimes: self::strings($rendition, 'mime', $when['mime'] ?? []),
            minDuration: self::duration($rendition, 'min_duration', $when['min_duration'] ?? null),
            maxDuration: self::duration($rendition, 'max_duration', $when['max_duration'] ?? null),
        );
    }

    public function isEmpty(): bool
    {
        return $this->kinds === [] && $this->mimes === [] && $this->minDuration === null && $this->maxDuration === null;
    }

    /**
     * Whether the probed input matches. A condition on a value the probe did
     * not report doesn't match.
     *
     * @param  array<string, mixed>  $metadata  Canonical keys
     */
    public function matches(array $metadata): bool
    {
        $kind = $metadata[MetadataKey::Kind->value] ?? null;
        if ($this->kinds !== [] && ! in_array(is_string($kind) ? ProbeKind::tryFrom($kind) : null, $this->kinds, true)) {
            return false;
        }

        $mime = $metadata[MetadataKey::Mime->value] ?? null;
        if ($this->mimes !== [] && (! is_string($mime) || ! Str::is($this->mimes, $mime))) {
            return false;
        }

        if ($this->minDuration === null && $this->maxDuration === null) {
            return true;
        }

        $duration = $metadata[MetadataKey::Duration->value] ?? null;
        if (! is_int($duration) && ! is_float($duration)) {
            return false;
        }

        return ($this->minDuration === null || $duration >= $this->minDuration)
            && ($this->maxDuration === null || $duration <= $this->maxDuration);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'kind' => array_map(fn (ProbeKind $kind) => $kind->value, $this->kinds),
            'mime' => $this->mimes,
            'min_duration' => $this->minDuration,
            'max_duration' => $this->maxDuration,
        ], fn (mixed $value) => $value !== null && $value !== []);
    }

    /**
     * @return list<string>
     */
    private static function strings(string $rendition, string $key, mixed $value): array
    {
        $values = is_array($value) ? array_values($value) : [$value];

        foreach ($values as $item) {
            if (! is_string($item) || $item === '') {
                throw new InvalidPipeline(sprintf('The rendition "%s" has an invalid `when.%s`: expected a string or a list of strings.', $rendition, $key));
            }
        }

        return $values;
    }

    private static function duration(string $rendition, string $key, mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if ((! is_int($value) && ! is_float($value)) || $value < 0) {
            throw new InvalidPipeline(sprintf('The rendition "%s" has an invalid `when.%s`: expected seconds.', $rendition, $key));
        }

        return (float) $value;
    }
}
