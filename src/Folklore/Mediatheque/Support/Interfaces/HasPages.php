<?php namespace Folklore\Mediatheque\Support\Interfaces;

interface HasPages
{
    public function getPagesCountFromFile($file);

    public function getPagesColumns();

    public function setPagesColumns($columns);

    public function getPagesColumnName();

    public function getPages();
}
