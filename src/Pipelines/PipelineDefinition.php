<?php

namespace Folklore\Mediatheque\Pipelines;

use Folklore\Mediatheque\Exceptions\InvalidPipeline;

/**
 * A named set of renditions, run by one transcoder (docs/decisions/0005).
 */
final readonly class PipelineDefinition
{
    /** The pipeline that records a pending probe (docs/decisions/0012). */
    public const string PROBE = 'probe';

    /** @var array<string, Rendition> */
    public array $renditions;

    /**
     * @param  array<string, Rendition>|list<Rendition>  $renditions
     * @param  string|null  $source  The source renditions are written to; the original's by default
     */
    public function __construct(
        public string $name,
        array $renditions,
        public string $transcoder = 'ffmpeg',
        public ?string $source = null,
    ) {
        $keyed = [];
        foreach ($renditions as $rendition) {
            if (isset($keyed[$rendition->name])) {
                throw new InvalidPipeline(sprintf('The pipeline "%s" has two renditions named "%s".', $name, $rendition->name));
            }
            $keyed[$rendition->name] = $rendition;
        }
        $this->renditions = $keyed;

        foreach ($keyed as $rendition) {
            if ($rendition->from !== null && ! isset($keyed[$rendition->from])) {
                throw new InvalidPipeline(sprintf(
                    'The rendition "%s" of the pipeline "%s" reads from "%s", which is not one of its renditions.',
                    $rendition->name,
                    $name,
                    $rendition->from,
                ));
            }
        }

        foreach ($keyed as $rendition) {
            $this->assertNoCycle($rendition);
        }
    }

    /**
     * From the config: `['transcoder' => 'ffmpeg', 'source' => null, 'renditions' => [...]]`.
     *
     * @param  array<array-key, mixed>  $config
     */
    public static function fromArray(string $name, array $config, string $defaultTranscoder = 'ffmpeg'): self
    {
        $transcoder = $config['transcoder'] ?? $defaultTranscoder;
        $source = $config['source'] ?? null;
        $renditions = $config['renditions'] ?? [];

        if (! is_string($transcoder) || $transcoder === '') {
            throw new InvalidPipeline(sprintf('The pipeline "%s" has an invalid `transcoder`.', $name));
        }
        if ($source !== null && ! is_string($source)) {
            throw new InvalidPipeline(sprintf('The pipeline "%s" has an invalid `source`.', $name));
        }
        if (! is_array($renditions)) {
            throw new InvalidPipeline(sprintf('The pipeline "%s" has an invalid `renditions`: expected an array keyed by name.', $name));
        }

        $parsed = [];
        foreach ($renditions as $renditionName => $definition) {
            if (! is_string($renditionName) || ! is_array($definition)) {
                throw new InvalidPipeline(sprintf('The pipeline "%s" has an invalid rendition: renditions are keyed by name.', $name));
            }
            $parsed[] = Rendition::fromArray($renditionName, $definition);
        }

        return new self($name, $parsed, $transcoder, $source);
    }

    public function rendition(string $name): ?Rendition
    {
        return $this->renditions[$name] ?? null;
    }

    /**
     * The renditions a run must produce to produce these ones: the run is
     * closed under `from` (docs/decisions/0012). A rendition in the run reads
     * from a rendition of the same run, which is produced again even if it
     * exists. In the pipeline's order.
     *
     * @param  list<string>  $names
     * @return array<string, Rendition>
     */
    public function closeUnderFrom(array $names): array
    {
        $included = [];
        foreach ($names as $name) {
            $rendition = $this->renditions[$name] ?? throw new InvalidPipeline(sprintf('The pipeline "%s" has no rendition "%s".', $this->name, $name));

            while (! isset($included[$rendition->name])) {
                $included[$rendition->name] = true;
                if ($rendition->from === null) {
                    break;
                }
                $rendition = $this->renditions[$rendition->from];
            }
        }

        return array_filter($this->renditions, fn (Rendition $rendition) => isset($included[$rendition->name]));
    }

    /**
     * The renditions that read from this one, directly or through others.
     *
     * @return list<string>
     */
    public function dependentsOf(string $name): array
    {
        $dependents = [];
        $queue = [$name];

        while ($queue !== []) {
            $current = array_shift($queue);
            foreach ($this->renditions as $rendition) {
                if ($rendition->from === $current && ! in_array($rendition->name, $dependents, true)) {
                    $dependents[] = $rendition->name;
                    $queue[] = $rendition->name;
                }
            }
        }

        return $dependents;
    }

    /**
     * The rendition a media is played from: the first one with the `main`
     * role that keeps its output. `MediaReady` waits for it.
     */
    public function mainRendition(): ?Rendition
    {
        foreach ($this->renditions as $rendition) {
            if ($rendition->role === Rendition::DEFAULT_ROLE && $rendition->keep) {
                return $rendition;
            }
        }

        return null;
    }

    /**
     * @return array{transcoder: string, source: string|null, renditions: array<string, array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'transcoder' => $this->transcoder,
            'source' => $this->source,
            'renditions' => array_map(fn (Rendition $rendition) => $rendition->toArray(), $this->renditions),
        ];
    }

    private function assertNoCycle(Rendition $rendition): void
    {
        $seen = [$rendition->name => true];
        $current = $rendition;

        while ($current->from !== null) {
            if (isset($seen[$current->from])) {
                throw new InvalidPipeline(sprintf('The renditions of the pipeline "%s" read from each other in a cycle, through "%s".', $this->name, $current->from));
            }
            $seen[$current->from] = true;
            $current = $this->renditions[$current->from];
        }
    }
}
