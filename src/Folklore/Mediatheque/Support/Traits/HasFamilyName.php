<?php namespace Folklore\Mediatheque\Support\Traits;

use Folklore\Mediatheque\Contracts\FamilyNameGetter;

trait HasFamilyName
{
    /**
     * Timeable
     */
    public function getFamilyNameFromFile($file)
    {
        $path = is_string($file) ? $file : $file->getRealPath();
        return app(FamilyNameGetter::class)->getFamilyName($path);
    }

    public function getFamilyNameColumns()
    {
        return $this->familyName_columns ? $this->familyName_columns:[
            'family_name' => 'family_name'
        ];
    }

    public function setFamilyNameColumns(array $columns)
    {
        return $this->familyName_columns = $columns;
    }

    public function getFamilyNameColumnName()
    {
        $columns = $this->getFamilyNameColumns();
        return $columns['family_name'];
    }

    public function getFamilyName()
    {
        $columnName = $this->getFamilyNameColumnName();
        return $this->{$columnName};
    }

    public function getFamilyNameHumanAttribute()
    {
        $columnName = $this->getFamilyNameColumnName();
        $name = array_get($this->attributes, $columnName);
        return $name;
    }
}
