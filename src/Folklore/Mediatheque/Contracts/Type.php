<?php

namespace Folklore\Mediatheque\Contracts;

use Imagine\Image\ImageInterface;

interface Type
{
    public function setName($name);

    public function getName();

    public function setModel($model);

    public function getModel();

    public function setMimes($mimes);

    public function getMimes();

    public function setPipeline($pipeline);

    public function getPipeline();

    public function isType($path, $mime = null);
}
