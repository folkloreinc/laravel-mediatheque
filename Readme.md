# Laravel Mediatheque


[![Latest Stable Version](https://poser.pugx.org/folklore/laravel-mediatheque/v/stable.svg)](https://packagist.org/packages/folklore/laravel-mediatheque)
[![CI](https://github.com/folkloreinc/laravel-mediatheque/actions/workflows/ci.yml/badge.svg?branch=v1.1)](https://github.com/folkloreinc/laravel-mediatheque/actions/workflows/ci.yml)
[![Total Downloads](https://poser.pugx.org/folklore/laravel-mediatheque/downloads.svg)](https://packagist.org/packages/folklore/laravel-mediatheque)

## Upload routes

The package registers upload routes (`POST /mediatheque`, `POST /mediatheque/{type}`) unless `mediatheque.routes.map` is `null`. They accept files from anyone who can reach them, so protect them:

- Set `mediatheque.routes.map` to `null` if your application doesn't use them.
- Add authentication and throttling with `mediatheque.routes.middleware`, or define a `mediatheque.upload` gate.
- Replacing the file of an existing media with the `id` parameter is refused unless a `mediatheque.replace` gate authorizes it, or `mediatheque.uploads.allow_replace` is `true`.

```php
Gate::define('mediatheque.upload', function ($user) {
    return $user->can('create', Media::class);
});

Gate::define('mediatheque.replace', function ($user, $media) {
    return $user->can('update', $media);
});
```

Laravel denies guests unless the gate's `$user` parameter is nullable (`?User $user`).

Stored files get an extension derived from their content, never from the client's file name alone, and SVG files are sanitized. See the `uploads` section of `config/mediatheque.php` and the [changelog](CHANGELOG.md).
