<?php

namespace Folklore\Mediatheque\Upgrade;

/**
 * The definition of a 1.x pipeline run, which 1.x stored with `serialize()`:
 * a `Support\Pipeline` object with its name, queue, input file and jobs.
 *
 * It is read without creating any object (`allowed_classes: false`): the
 * 1.x classes don't exist any more, and a stored value must never run code.
 * The properties of the object are read from the incomplete object PHP
 * makes of it.
 */
final readonly class LegacyPipelineDefinition
{
    /**
     * @param  array<string, mixed>  $jobs  The jobs by name, as 1.x defined them
     */
    private function __construct(
        public ?string $name,
        public ?string $fromFile,
        public array $jobs,
    ) {}

    /**
     * The definition, or null when the value can't be read: empty,
     * truncated, or not a serialized pipeline.
     */
    public static function parse(?string $serialized): ?self
    {
        if ($serialized === null || $serialized === '') {
            return null;
        }

        $value = @unserialize($serialized, ['allowed_classes' => false]);

        if (! is_object($value)) {
            return null;
        }

        $properties = [];
        foreach ((array) $value as $key => $property) {
            // Protected and private properties are prefixed with "\0*\0" or
            // "\0Class\0"
            $properties[(string) preg_replace('/^\x00[^\x00]*\x00/', '', (string) $key)] = $property;
        }

        $jobs = $properties['jobs'] ?? null;
        if (! is_array($jobs)) {
            return null;
        }

        $named = [];
        foreach ($jobs as $name => $job) {
            if (is_string($name) && (is_string($job) || is_array($job))) {
                $named[$name] = $job;
            }
        }

        $name = $properties['name'] ?? null;
        $fromFile = $properties['fromFile'] ?? null;

        return new self(
            is_string($name) ? $name : null,
            is_string($fromFile) ? $fromFile : null,
            $named,
        );
    }

    /**
     * The classes of its jobs that have no 2.0 operation.
     *
     * @return list<string>
     */
    public function jobsWithoutOperation(): array
    {
        $classes = [];

        foreach ($this->jobs as $job) {
            $class = LegacyJobs::jobClass($job);
            if ($class !== null && LegacyJobs::rendition($job) === null) {
                $classes[] = $class;
            }
        }

        return array_values(array_unique($classes));
    }
}
