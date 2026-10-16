<?php

namespace Folklore\Mediatheque\Exceptions;

use InvalidArgumentException;

/**
 * A pipeline or rendition definition that breaks the rules of
 * docs/decisions/0011. Thrown when the config is read, so a mistake shows
 * at deploy time.
 */
class InvalidPipeline extends InvalidArgumentException implements MediathequeException {}
