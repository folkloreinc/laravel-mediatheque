# Changelog

All notable changes to `folklore/laravel-mediatheque` 1.x are documented here.

## 1.1.3

### Changed

- The package no longer requires `guzzlehttp/guzzle`, which it never used (#21). Applications on Laravel 13 can install Guzzle 8. Guzzle is still installed through `aws/aws-sdk-php` and `laravel/framework`. If your application uses Guzzle directly, require it in its own `composer.json`.

## 1.1.2

### Security

- Stored files get an extension derived from their content. The extension of the client's file name is kept only when it matches the content, or when the content is generic (`text/plain`, `application/octet-stream`) and the extension is not blocked (`php`, `phtml`, `html`, `svg`, `js`…). See the security advisory [GHSA-cfwc-q2m5-j68c](https://github.com/folkloreinc/laravel-mediatheque/security/advisories/GHSA-cfwc-q2m5-j68c).
- SVG files are sanitized before they are stored: scripts, event handlers, `javascript:` and non-image `data:` URLs, `<foreignObject>` and external `<use>` references are removed.
- The upload routes no longer replace the original file of an existing media with the `id` parameter, unless the application allows it (see below).
- The upload routes validate the file: an unsupported type or an invalid SVG returns a 422 validation error instead of a 500 error, and an optional maximum size can be set.

### Upgrading

Most applications need no change. Check the following if they apply to you:

- **Replacing a media's file through the upload routes** (`POST /mediatheque` with `id`) now returns 403. Define a `mediatheque.replace` gate to authorize each replacement, or set `mediatheque.uploads.allow_replace` to `true` if your routes are already behind authentication:

  ```php
  Gate::define('mediatheque.replace', function ($user, $media) {
      return $user->can('update', $media);
  });
  ```

- **Stored extensions** now follow the content: a PNG uploaded as `photo.jpg` is stored as `.png`. Existing files are not renamed.
- **Invalid SVG files** are rejected: `setOriginalFile()` and `File::setFile()` throw `Folklore\Mediatheque\Exception\InvalidFileException`. Set `mediatheque.uploads.sanitize_svg` to `false` to store SVG files unchanged.
- **The upload routes accept files from anyone who can reach them.** If your application doesn't use them, set `mediatheque.routes.map` to `null`. Otherwise, add authentication with `mediatheque.routes.middleware` or define a `mediatheque.upload` gate.

### Added

- `mediatheque.uploads` config: `max_size`, `allow_replace`, `sanitize_svg` and `blocked_extensions`. The defaults apply when a published config file doesn't have these keys.
- `mediatheque.upload` and `mediatheque.replace` gates, checked by the upload routes when they are defined.
- `Folklore\Mediatheque\Contracts\Services\SvgSanitizer`, bound to `mediatheque.services.svg_sanitizer`.
- Dependency on [`rhukster/dom-sanitizer`](https://github.com/rhukster/dom-sanitizer) (MIT).

## 1.1.1

**Requirements:** PHP 8.2 or later, Laravel 9 or later (#20). Tested on PHP 8.2–8.5 with Laravel 9 to 13 (#17).

### Changed

- `illuminate/support` now requires Laravel 9 or later, and the `symfony/*` constraints no longer allow Symfony 5 (#20). Projects on Laravel 7 or 8 that require `^1.1` stay on `v1.1.0`.
- CI also tests Laravel 9 and 10, and installs tagged framework releases instead of `x.x-dev` branches (#17).

No code change since `v1.1.0`.

## 1.1.0

First tagged release of the 1.x line. Projects that required `v1.1.x-dev` can now require `^1.1` (#15).

**Requirements:** PHP 8.2 or later (#2). Tested on PHP 8.2–8.5 with Laravel 11, 12 and 13.

### Fixed and changed

- CI on GitHub Actions, with Pint and Larastan (#5).
- The default `video` pipeline only adds `media_convert` when MediaConvert is configured (#5).
- `ColorExtractor::getColors()` returns its result, and `Definition::jsonSerialize()` no longer triggers a deprecation (#5).
- `Pipeline::allJobsEnded()` only looks at its own jobs; the source, type and metadata managers no longer miss imports or the `InvalidSourceException` class; `Media::setType()` accepts a `Type` object; `File::search()` matches name, path or handle (#7).
- Async pipelines: a failed job fails the jobs waiting for its file and ends its pipeline; each job is dispatched once; new `mediatheque.jobs.timeout` and `mediatheque.jobs.tries` options, with `failOnTimeout`; temporary files are deleted (#9).
- The 2023 migration honors the table prefix, and `LocalSource` and `Router` declare their properties (#11).
- `File::setFile()` stores the file on the requested source and records the default one; `Pipeline::getModelToProcess()` honors the morph map and ignores global scopes (#14).

### Upgrade notes

- Upload routes are now named `mediatheque.upload.{type}` instead of `mediatheque..upload.{type}` (#11).
- New files record their source name instead of `null` (#14). Existing rows keep resolving to the default source.
