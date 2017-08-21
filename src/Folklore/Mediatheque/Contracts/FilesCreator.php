<?php

namespace Folklore\Mediatheque\Contracts;

interface FilesCreator
{
    /**
     * Return a list of file keys that should be created by running
     * this creator on $file.
     *
     * If the creator creates a single, un-keyed file, return true.
     *
     * @param  string           $file
     * @return array|boolean    $keys
     */
    public function getKeysOfFilesToCreate($file);

    /**
     * Create files from path
     *
     * @param  string           $file
     * @param  array|boolean    $keys
     * @return array            $files
     */
    public function createFiles($file, $keys = null);
}
