<?php namespace Folklore\Mediatheque\Support\Interfaces;

interface HasDimension
{
    public function getDimensionColumns();

    public function setDimensionColumns(array $columns);

    public function getWidthColumnName();

    public function getHeightColumnName();

    public function getWidth();

    public function getHeight();

    public function getRatio();

    public function getWidthFromHeight($height);

    public function getHeightFromWidth($width);

    public function getDimensionFromFile($file);
}
