<?php

namespace Folklore\Mediatheque\Monitoring;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;

/**
 * The period a monitoring query covers, from and to included.
 */
final readonly class Period
{
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
    ) {
        if ($from->isAfter($to)) {
            throw new InvalidArgumentException('A period starts before it ends.');
        }
    }

    /**
     * The last hours, up to now.
     */
    public static function lastHours(int $hours): self
    {
        if ($hours < 1) {
            throw new InvalidArgumentException('A period lasts at least an hour.');
        }

        $now = CarbonImmutable::instance(Date::now());

        return new self($now->subHours($hours), $now);
    }

    public static function between(DateTimeInterface $from, DateTimeInterface $to): self
    {
        return new self(CarbonImmutable::instance($from), CarbonImmutable::instance($to));
    }

    /**
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    public function bounds(): array
    {
        return [$this->from, $this->to];
    }
}
