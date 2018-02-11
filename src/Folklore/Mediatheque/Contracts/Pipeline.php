<?php

namespace Folklore\Mediatheque\Contracts;

use Imagine\Image\ImageInterface;

interface Pipeline
{
    public function setName($name);

    public function getName();

    public function setOptions($options);

    public function getOptions();

    public function addJob($name, $job);

    public function setJobs($jobs);

    public function getJobs();
}
