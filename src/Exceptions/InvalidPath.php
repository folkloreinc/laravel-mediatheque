<?php

namespace Folklore\Mediatheque\Exceptions;

use InvalidArgumentException;

/**
 * The path formatter can't build a safe path: a placeholder names a missing
 * attribute, or a value would leave its directory.
 */
class InvalidPath extends InvalidArgumentException implements MediathequeException {}
