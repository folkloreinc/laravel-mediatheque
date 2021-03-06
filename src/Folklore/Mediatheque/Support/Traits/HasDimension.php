<?php namespace Folklore\Mediatheque\Support\Traits;

use Folklore\Mediatheque\Contracts\DimensionGetter;

trait HasDimension
{
    public function getDimensionFromFile($file)
    {
        $path = $file->getRealPath();
        return app(DimensionGetter::class)->getDimension($path);
    }

    public function getDimensionColumns()
    {
        return $this->dimension_columns ? $this->dimension_columns:[
            'width' => 'width',
            'height' => 'height'
        ];
    }

    public function setDimensionColumns(array $columns)
    {
        return $this->dimension_columns = $columns;
    }

    public function getWidthColumnName()
    {
        $columns = $this->getDimensionColumns();
        return $columns['width'];
    }

    public function getHeightColumnName()
    {
        $columns = $this->getDimensionColumns();
        return $columns['height'];
    }

    public function getWidth()
    {
        $columnName = $this->getWidthColumnName();
        return $this->{$columnName};
    }

    public function getHeight()
    {
        $columnName = $this->getHeightColumnName();
        return $this->{$columnName};
    }

    public function getRatio()
    {
        $width = $this->getWidth();
        $height = $this->getHeight();
        return $width/$height;
    }

    public function getWidthFromHeight($height)
    {
        $ratio = $this->getRatio();
        return $height*$ratio;
    }

    public function getHeightFromWidth($width)
    {
        $ratio = $this->getRatio();
        return $width/$ratio;
    }

    public function getDimensionHumanAttribute()
    {
        $columns = $this->getDimensionColumns();
        $width = array_get($this->attributes, $columns['width'], -1);
        $height = array_get($this->attributes, $columns['height'], -1);
        return $width.'x'.$height;
    }
}
