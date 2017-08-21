<?php namespace Folklore\Mediatheque\Support\Interfaces;

interface HasFiles
{
    public function setOriginalFile($path, $file = array());

    public function getOriginalFile();
}
