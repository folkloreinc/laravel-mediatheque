<?php

namespace Folklore\Mediatheque\Probe;

use Folklore\Mediatheque\Enums\ProbeKind;
use Folklore\Mediatheque\Metadata\MetadataKey;
use Folklore\Mediatheque\Transcoders\TranscoderError;
use InvalidArgumentException;

/**
 * What a prober returns (docs/decisions/0012): the metadata, a pending
 * probe that the driver completes later through the recorder, or an error.
 */
final readonly class ProbeOutcome
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    private function __construct(
        public array $metadata,
        public ?string $externalId,
        public ?TranscoderError $error,
    ) {}

    /**
     * The probe's metadata. Its keys are normalized to the canonical keys,
     * and it must have a `kind` (ProbeKind).
     *
     * @param  array<array-key, mixed>  $metadata
     */
    public static function done(array $metadata): self
    {
        $metadata = MetadataKey::normalize($metadata);
        $kind = $metadata[MetadataKey::Kind->value] ?? null;

        if ($kind instanceof ProbeKind) {
            $metadata[MetadataKey::Kind->value] = $kind->value;
        } elseif (! is_string($kind) || ProbeKind::tryFrom($kind) === null) {
            throw new InvalidArgumentException('A probe must report a kind: '.implode(', ', array_column(ProbeKind::cases(), 'value')).'.');
        }

        return new self($metadata, null, null);
    }

    /**
     * The probe takes longer: the driver reports it later, as a run of the
     * `probe` pipeline with this external id.
     */
    public static function pending(string $externalId): self
    {
        if ($externalId === '') {
            throw new InvalidArgumentException('A pending probe needs the external id of its driver job.');
        }

        return new self([], $externalId, null);
    }

    public static function failed(TranscoderError $error): self
    {
        return new self([], null, $error);
    }

    public function isDone(): bool
    {
        return $this->externalId === null && $this->error === null;
    }

    public function isPending(): bool
    {
        return $this->externalId !== null;
    }

    public function isFailed(): bool
    {
        return $this->error !== null;
    }

    public function kind(): ?ProbeKind
    {
        $kind = $this->metadata[MetadataKey::Kind->value] ?? null;

        return is_string($kind) ? ProbeKind::tryFrom($kind) : null;
    }
}
