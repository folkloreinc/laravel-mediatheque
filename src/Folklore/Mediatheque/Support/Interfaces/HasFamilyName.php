<?php namespace Folklore\Mediatheque\Support\Interfaces;

interface HasFamilyName
{
    public function getFamilyNameFromFile($file);

    public function getFamilyNameColumns();

    public function setFamilyNameColumns(array $columns);

    public function getFamilyNameColumnName();

    public function getFamilyName();
}
