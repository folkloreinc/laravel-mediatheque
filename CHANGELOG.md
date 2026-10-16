# Changelog

All notable changes to `folklore/laravel-mediatheque` 1.x are documented here.

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
