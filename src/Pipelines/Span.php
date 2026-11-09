<?php

namespace Folklore\Mediatheque\Pipelines;

/**
 * The span of the input that a rendition keeps, in seconds: from `start`
 * (0 by default) for `duration` (until the end by default), as the
 * `start` and `duration` options of `video.transcode` say
 * (docs/decisions/0028). Every other operation keeps the whole input.
 */
final readonly class Span
{
    /** The operations that take `start` and `duration`. */
    public const array OPERATIONS = ['video.transcode'];

    public function __construct(
        public float $start = 0.0,
        public ?float $duration = null,
    ) {}

    /**
     * The rendition's span. Options that aren't a number of seconds (a
     * negative `start`, a `duration` of 0) are left out here: the transcoder
     * refuses them when it runs the rendition.
     */
    public static function of(Rendition $rendition): self
    {
        if (! in_array($rendition->operation, self::OPERATIONS, true)) {
            return new self;
        }

        $start = self::seconds($rendition->options['start'] ?? null);
        $duration = self::seconds($rendition->options['duration'] ?? null);

        return new self($start ?? 0.0, $duration !== null && $duration > 0 ? $duration : null);
    }

    public function isWhole(): bool
    {
        return $this->start === 0.0 && $this->duration === null;
    }

    /**
     * How long the output lasts, from the input's duration: the span, cut at
     * the end of the input. Null when neither is known.
     */
    public function length(?float $input): ?float
    {
        $remaining = $input === null ? null : max(0.0, $input - $this->start);

        if ($this->duration === null) {
            return $remaining;
        }

        return $remaining === null ? $this->duration : min($this->duration, $remaining);
    }

    private static function seconds(mixed $value): ?float
    {
        return (is_int($value) || is_float($value)) && $value >= 0 ? (float) $value : null;
    }
}
