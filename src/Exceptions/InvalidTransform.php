<?php

namespace Folklore\Mediatheque\Exceptions;

use InvalidArgumentException;

/**
 * A media's `transform` is not in the shape of Panneau 5's `MediaTransform`
 * (docs/decisions/0022).
 */
class InvalidTransform extends InvalidArgumentException implements MediathequeException {}
