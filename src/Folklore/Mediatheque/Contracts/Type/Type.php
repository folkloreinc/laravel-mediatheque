<?php

namespace Folklore\Mediatheque\Contracts\Type;

interface Type
{
    public function setName($name);

    public function getName();

    public function setModel($model);

    public function getModel();

    public function setMimes($mimes);

    public function getMimes();

    public function setMetadatas($metadatas);

    public function getMetadatas();

    public function setPipeline($pipeline);

    public function getPipeline();

    public function pathIsType($path, $mime = null);
}
