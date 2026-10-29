<?php

namespace Folklore\Mediatheque\Upgrade;

/**
 * What `mediatheque:upgrade --check` found in a 1.x database.
 */
final readonly class Report
{
    /**
     * @param  array<string, int>  $orphans  Rows that refer to nothing, by table: the upgrade removes them
     * @param  array<string, array<string, int>>  $unknownMorphTypes  Morph types of metadata and runs that are neither media nor files, by table: not converted
     * @param  int  $unreadableDefinitions  Runs whose serialized definition can't be read: their renditions come from their jobs
     * @param  array<string, int>  $jobsWithoutOperation  1.x job classes with no 2.0 operation, with their number of runs
     * @param  array<string, int>  $invalidHandles  Handles that break the 2.0 rule once split, with their number of files
     * @param  array<string, int>  $filesOnOtherModels  Files attached to models other than media, by morph type: left as they are
     * @param  int  $filesOfSeveralMedia  Files attached to more than one media: each goes to the first
     * @param  array<string, array{count: int, reason: string}>  $typesWithoutRenditions  Types of media with no 2.0 renditions
     */
    public function __construct(
        public array $orphans,
        public array $unknownMorphTypes,
        public int $unreadableDefinitions,
        public array $jobsWithoutOperation,
        public array $invalidHandles,
        public array $filesOnOtherModels,
        public int $filesOfSeveralMedia,
        public array $typesWithoutRenditions,
    ) {}

    /**
     * Whether the database has nothing to remove, nothing the upgrade can't
     * convert, and nothing left to the application.
     */
    public function isClean(): bool
    {
        return array_sum($this->orphans) === 0
            && $this->unknownMorphTypes === []
            && $this->unreadableDefinitions === 0
            && $this->jobsWithoutOperation === []
            && $this->invalidHandles === []
            && $this->filesOnOtherModels === []
            && $this->filesOfSeveralMedia === 0
            && $this->typesWithoutRenditions === [];
    }
}
