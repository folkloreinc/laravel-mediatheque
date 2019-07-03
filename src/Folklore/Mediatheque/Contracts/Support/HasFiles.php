<?php namespace Folklore\Mediatheque\Contracts\Support;

interface HasFiles
{
    public function files();

    public function setOriginalFile($path, $file = array());

    public function getOriginalFile();
}
