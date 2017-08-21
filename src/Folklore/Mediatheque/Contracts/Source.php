<?php

namespace Folklore\Mediatheque\Contracts;

use Imagine\Image\ImageInterface;

interface Source
{
    public function exists($path);

    public function putFromContents($path, $contents);

    public function putFromLocalPath($path, $localPath);

    public function delete($path);

    public function move($source, $destination);

    public function copy($source, $destination);

    public function copyToLocalPath($path, $localPath);

    public function getUrl($path);
}
