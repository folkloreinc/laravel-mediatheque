<?php namespace Folklore\Mediatheque\Support\Traits;

use Folklore\Mediatheque\Contracts\DurationGetter;
use FFMpeg\Coordinate\TimeCode;

trait HasDuration
{
    /**
     * Timeable
     */
    public function getDurationFromFile($file)
    {
        $path = is_string($file) ? $file : $file->getRealPath();
        return app(DurationGetter::class)->getDuration($path);
    }

    public function getDurationColumns()
    {
        return $this->duration_columns ? $this->duration_columns:[
            'duration' => 'duration'
        ];
    }

    public function setDurationColumns(array $columns)
    {
        return $this->duration_columns = $columns;
    }

    public function getDurationColumnName()
    {
        $columns = $this->getDurationColumns();
        return $columns['duration'];
    }

    public function getDuration()
    {
        $columnName = $this->getDurationColumnName();
        return $this->{$columnName};
    }

    public function getDurationHumanAttribute()
    {
        $columnName = $this->getDurationColumnName();
        $duration = array_get($this->attributes, $columnName, 0);
        $timeCode = TimeCode::fromSeconds($duration);
        return (string)$timeCode;
    }
}
