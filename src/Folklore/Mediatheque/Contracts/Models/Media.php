<?php

namespace Folklore\Mediatheque\Contracts\Models;

interface Media
{
    public function getTypeName();

    public function getType();

    public function setType($type);
}
