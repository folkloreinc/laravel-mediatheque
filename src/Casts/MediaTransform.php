<?php

namespace Folklore\Mediatheque\Casts;

use Folklore\Mediatheque\Exceptions\InvalidTransform;
use Illuminate\Database\Eloquent\Model;

/**
 * A media's `transform`: the non-destructive edits of its image, in the shape
 * of Panneau 5's `MediaTransform` (docs/decisions/0022), stored as it is
 * given once it is checked.
 *
 *     ['crop' => ['x' => 0, 'y' => 120, 'width' => 1600, 'height' => 900],
 *      'rotate' => 90, 'flipX' => false, 'flipY' => false,
 *      'focalPoint' => ['x' => 0.3, 'y' => 0.6]]
 *
 * - `crop`: whole pixels of the upright original, `x` and `y` from 0,
 *   `width` and `height` from 1, or null;
 * - `rotate`: 0, 90, 180 or 270, clockwise;
 * - `flipX`, `flipY`: booleans;
 * - `focalPoint`: `x` and `y` from 0 to 1, relative to the upright original,
 *   or null.
 *
 * Every key is optional, and other keys are kept, as Panneau's schema allows
 * them. A value of another shape throws InvalidTransform.
 */
class MediaTransform extends Json
{
    /**
     * @param  mixed  $value  what the application or a form gives, checked here
     * @param  array<string, mixed>  $attributes
     *
     * @throws InvalidTransform
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidTransform('A transform is an object of crop, rotate, flipX, flipY and focalPoint.');
        }

        self::check($value);

        return parent::set($model, $key, $value, $attributes);
    }

    /**
     * @param  array<array-key, mixed>  $transform
     */
    private static function check(array $transform): void
    {
        $crop = $transform['crop'] ?? null;
        if ($crop !== null && ! self::isCrop($crop)) {
            throw new InvalidTransform('A crop has whole numbers of pixels: x and y from 0, width and height from 1.');
        }

        if (array_key_exists('rotate', $transform) && ! in_array($transform['rotate'], [0, 90, 180, 270], true)) {
            throw new InvalidTransform('A rotation is 0, 90, 180 or 270 degrees.');
        }

        foreach (['flipX', 'flipY'] as $flip) {
            if (array_key_exists($flip, $transform) && ! is_bool($transform[$flip])) {
                throw new InvalidTransform(sprintf('%s is true or false.', $flip));
            }
        }

        $focalPoint = $transform['focalPoint'] ?? null;
        if ($focalPoint !== null && ! self::isFocalPoint($focalPoint)) {
            throw new InvalidTransform('A focal point has an x and a y from 0 to 1.');
        }
    }

    private static function isCrop(mixed $crop): bool
    {
        return is_array($crop)
            && is_int($crop['x'] ?? null) && $crop['x'] >= 0
            && is_int($crop['y'] ?? null) && $crop['y'] >= 0
            && is_int($crop['width'] ?? null) && $crop['width'] >= 1
            && is_int($crop['height'] ?? null) && $crop['height'] >= 1;
    }

    private static function isFocalPoint(mixed $point): bool
    {
        if (! is_array($point)) {
            return false;
        }

        foreach (['x', 'y'] as $axis) {
            $value = $point[$axis] ?? null;
            if ((! is_int($value) && ! is_float($value)) || $value < 0 || $value > 1) {
                return false;
            }
        }

        return true;
    }
}
