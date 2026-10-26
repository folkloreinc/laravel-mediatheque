# 0022: The transform, focal point and orientation of an image

- Status: accepted
- Date: 2026-10-26
- Amends: [0013](0013-media-resolver-for-laravel-image.md)

## Context

[0013](0013-media-resolver-for-laravel-image.md) left the orientation, the dominant color, the focal point and the `transform` of an image to [laravel-image#88](https://github.com/folkloreinc/laravel-image/issues/88). Panneau 5's image editor writes non-destructive edits on a media (`Media.transform` in its protocol, folkloreinc/panneau-js#80), and `laravel-image` 2 renders them. The three packages agreed on one shape in laravel-image#88, which `laravel-image` records in [its decision 0013](https://github.com/folkloreinc/laravel-image/blob/v2.x/docs/decisions/0013-transform-focal-point-and-metadata.md) and implements in folkloreinc/laravel-image#101. This is the mediatheque's side ([#107](https://github.com/folkloreinc/laravel-mediatheque/issues/107)).

## Decision

### A `transform` on the media, outside `metadata`

- The media has one nullable **`transform`** column (JSON, `jsonb` on PostgreSQL), added by a new migration that `mediatheque:install` publishes. It holds Panneau 5's `MediaTransform`, focal point included:

  ```php
  [
      'crop' => ['x' => 0, 'y' => 120, 'width' => 1600, 'height' => 900],  // or null
      'rotate' => 90,                                                     // 0, 90, 180 or 270, clockwise
      'flipX' => false,                                                   // left to right
      'flipY' => false,                                                   // top to bottom
      'focalPoint' => ['x' => 0.3, 'y' => 0.6],                           // or null
  ]
  ```

- The crop is in whole pixels of the **upright** original (after its EXIF orientation), and the focal point from 0 to 1 relative to the upright original, not to the crop. Edits apply in the order crop, rotate, flip.
- It is outside `metadata` because it is editorial: a probe or a transcoder writes `metadata`, an editor writes `transform`, and neither overwrites the other.
- The `Media` contract reads it, `transform(): ?array`. Applications and Panneau write it through the model's attribute (`$media->transform = [...]`). Its cast checks the shape and stores it as it is given: every key is optional, and unknown keys are kept, as Panneau's schema allows them. A value of another shape throws `InvalidTransform`. The mediatheque doesn't normalize it: `laravel-image`'s `Transform::fromArray()` reads it.
- `replaceOriginal()` keeps the transform, as it keeps the media's `data`. A crop past the edge of a smaller original stops at its edge; an application that wants to reset the edits sets `transform` to null.
- `laravel-image` stays a suggested dependency: the cast doesn't use its classes.

### `orientation`, a canonical key

- **`orientation`** is the EXIF orientation of an image, from 1 to 8, when the image declares one. The probe writes it to the media's metadata and to its original's.
- The original is **not** turned upright at ingest: it is stored as it was uploaded, and `laravel-image` turns every rendering upright. `width` and `height` stay the upright size, as before.
- The **dominant color** is the first entry of `palette`: there is no separate key.

### The `media` resolver passes them to the `ImageSource`

- The resolver passes the file's `orientation` (the media's, for its original, when the file has none) as the source's `orientation`.
- The media's `transform` applies to **its image only**: the original of an `image` media, as `med_…` or `med_…/original`. A video's poster or thumbnails resolve without it, since the crop is in pixels of the file the editor showed. It is passed as `transform: Transform::fromArray($media->transform())`, so its focal point becomes the source's `focalPoint`.
- The `version` stays the first 8 hex characters of the file's checksum. `laravel-image` adds a hash of the edits to the version of URLs (`ImageSource::urlVersion()`), so editing a media gives new URLs; the resolver must not add it again.
- A stored transform that `laravel-image` can't read, such as one written before this cast or with both `flip` and `flipX`, throws when the image resolves, rather than rendering an image without its edits.

### Clearing derivatives

The listener that clears a media's derivatives lives in `laravel-image` (`ClearMediaDerivatives`), on `MediaVisibilityChanged` and `OriginalReplaced`, which this package dispatches with the media. Changing the `transform` needs no event: it changes the URLs.

## Consequences

- The `Media` contract has `transform()`: a class that implements it implements it too.
- Run `mediatheque:install`, then `migrate`, for the `transform` column.
- The resolver needs the `laravel-image` pre-release that ships folkloreinc/laravel-image#101: `Transform::fromArray()` reading `flipX`, `flipY` and `focalPoint`, and `urlVersion()`. With an earlier one, the source carries the crop, rotation and focal point, but its flips are lost and its URLs don't change with the edits.
- `MediaResource`, with the protocol's `Media.transform`, is still to do ([roadmap.md](../roadmap.md#protected-playback-next)).
