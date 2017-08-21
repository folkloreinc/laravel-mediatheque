<?php namespace Folklore\Mediatheque\Support\Interfaces;

interface HasDuration
{
    public function getDurationFromFile($file);

    public function getDurationColumns();

    public function setDurationColumns(array $columns);

    public function getDurationColumnName();

    public function getDuration();
}
