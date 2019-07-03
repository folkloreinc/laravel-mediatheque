<?php

namespace Folklore\Mediatheque\Contracts\Metadata;

interface Reader
{
    public function setName($name);

    public function getName();

    public function hasMultipleValues();

    public function getValue($path);
}
