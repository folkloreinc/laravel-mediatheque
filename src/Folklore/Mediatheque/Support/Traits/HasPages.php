<?php namespace Folklore\Mediatheque\Support\Traits;

use Folklore\Mediatheque\Contracts\PagesCountGetter;

trait hasPages
{
    public function getPagesCountFromFile($file)
    {
        $path = $file->getRealPath();
        return app(PagesCountGetter::class)->getPagesCount($path);
    }

    public function getPagesColumns()
    {
        return $this->pages_columns ? $this->pages_columns:[
            'pages' => 'pages'
        ];
    }

    public function setPagesColumns($columns)
    {
        return $this->pages_columns = $columns;
    }

    public function getPagesColumnName()
    {
        $columns = $this->getPagesColumns();
        return $columns['pages'];
    }

    public function getPages()
    {
        $columnName = $this->getPagesColumnName();
        return $this->{$columnName};
    }
}
