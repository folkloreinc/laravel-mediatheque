<?php

namespace Folklore\Mediatheque\Contracts\Metadata;

interface Value
{
    public function getName();

    public function getValue();

    public function getType();
}
