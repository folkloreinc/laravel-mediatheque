# Laravel Mediatheque

[![Latest Stable Version](https://poser.pugx.org/folklore/laravel-mediatheque/v/stable.svg)](https://packagist.org/packages/folklore/laravel-mediatheque)
[![CI](https://github.com/folkloreinc/laravel-mediatheque/actions/workflows/ci.yml/badge.svg?branch=v2.x)](https://github.com/folkloreinc/laravel-mediatheque/actions/workflows/ci.yml)
[![Total Downloads](https://poser.pugx.org/folklore/laravel-mediatheque/downloads.svg)](https://packagist.org/packages/folklore/laravel-mediatheque)

> **2.0 is in development on this branch**, released as alphas. An alpha can break the previous one: read the [CHANGELOG](CHANGELOG.md) before you update. [`docs/`](docs/README.md) has the design of 2.0 and [`docs/roadmap.md`](docs/roadmap.md) the plan. 1.x is maintained on [`v1.1`](https://github.com/folkloreinc/laravel-mediatheque/tree/v1.1).

`folklore/laravel-mediatheque` stores media (video, audio, images, documents, fonts, captions), probes them, produces their renditions in your queue, and serves them through public or temporary URLs.

## What 2.0 does today

The alphas are for new projects; the upgrade from 1.x comes in the beta. They ingest files (the application, or Panneau 5, takes the uploads: [decisions/0018](docs/decisions/0018-ingest-only.md)), probe them, render videos and audio with `ffmpeg` in your queue, serve public and temporary URLs (which can download a file under a name), give images URLs through `laravel-image` 2 with the edits of Panneau 5's image editor, replace a media's file while it keeps its uid, attach media to your models by handle and keep a media in use from being deleted, and monitor the runs with `mediatheque:status`, actions and a dashboard. What each alpha added is in the [CHANGELOG](CHANGELOG.md).

### Requirements

- PHP 8.3+ and Laravel 12+.
- SQLite, MySQL 8.4+ or PostgreSQL 17+: CI runs the tests on each.
- ffmpeg and ffprobe on the servers that run your queue. The Imagick extension is optional: without it, image sizes come from `getimagesize()`.
- A queue worker: probes and renditions run in jobs.
- For S3-compatible disks: `league/flysystem-aws-s3-v3`.
- For image URLs: `folklore/laravel-image` 2.0.0-alpha.2 or later.

### Install

```bash
composer require "folklore/laravel-mediatheque:^2.0@alpha"
php artisan mediatheque:install
php artisan migrate
```

`mediatheque:install` publishes `config/mediatheque.php` and the migrations. The package never loads migrations itself: add your own columns (a tenant id, for example) to the published migration. Your config only needs your overrides; it is merged with the package's defaults.

### Ingest a file

```php
use Folklore\Mediatheque\Enums\Visibility;
use Folklore\Mediatheque\Facades\Mediatheque;
use Folklore\Mediatheque\Ingest\Source;

$media = Mediatheque::ingest($request->file('file'));
$media = Mediatheque::ingest(Source::disk('s3', 'incoming/clip.mov'), name: 'Episode 1');
$media = Mediatheque::ingest($path, visibility: Visibility::Private, attributes: ['tenant_id' => $tenant->id]);
```

The type comes from the file's content, never from its name: a file of no type, or larger than its type allows, is rejected with `FileRejected`. SVG files are sanitized. The media is `pending`, then `processing` once probed, then `ready` once its main rendition is produced (`failed` otherwise). Videos get `h264`, `poster` and `thumbnails`; audio gets `mp3` and `waveform`; images are ready once probed.

```php
$media->uid();                    // "med_01JAB3…"
$media->status();                 // MediaStatus::Ready
$media->metadata('duration');     // 12.5
$media->url();                    // the h264 rendition, or the original until it exists
$media->file('thumbnails', 2);    // a file of a group
$media->thumbnail();              // the poster, or the first thumbnail

Mediatheque::resolve('media://med_01JAB3…');
```

A private media only gets temporary URLs, signed by its disk (S3, or a local disk with `'serve' => true`). Listen to `MediaReady` and `MediaFailed` to follow a media.

### Files uploaded directly to storage

The package takes no uploads: it ingests files ([decisions/0018](docs/decisions/0018-ingest-only.md)). Large files go from the browser to an S3-compatible disk through your application's routes, or Panneau 5's direct uploads, which sign each request. Once a file is on the disk, ingest it from there. On the media's disk, it is moved without going through PHP:

```php
$media = Mediatheque::ingest(
    Source::disk('s3', $upload->path, $upload->name),
    visibility: Visibility::Private,
    attributes: ['tenant_id' => $request->user()->current_tenant_id],
);
```

The type comes from the file's content, never from the type the browser stored it with.

### Replace a media's file

```php
Mediatheque::replaceOriginal($media, $request->file('file'));
$media->replaceOriginal(Source::disk('s3', $upload->path, $upload->name));
```

The media keeps its uid, so `media://` references keep working. It also keeps its visibility, its data and your attributes. The new file is validated as at ingest, and a file of another type is refused unless you pass `allowTypeChange: true`. The media goes back to `pending`: its renditions are produced again from the new file, and `OriginalReplaced` is dispatched. See [decisions/0019](docs/decisions/0019-replace-original.md).

### Attach media to your models

```php
use Folklore\Mediatheque\Concerns\HasMedias;

class Post extends Model
{
    use HasMedias;
}

$post->attachMedia($media, 'cover');
$post->syncMedias(['gallery' => [$a, $b]]);    // replaces the gallery only
$post->media('cover');
$post->medias('gallery');
```

Each attachment is a usage: `$media->usages()` lists the models that use a media, and `$media->delete()` throws `MediaInUse` while one does, unless you force the deletion with `forceDelete()`. See [decisions/0020](docs/decisions/0020-attachments-by-handle-and-usages.md).

### Monitoring dashboard

A dashboard of probes, pipeline runs and renditions, with retry, cancel and refresh, in the spirit of Horizon. It is precompiled: your application builds nothing. Register its routes in your own middleware, and say who can use it:

```php
Route::middleware(['web', 'auth'])->group(function () {
    Route::mediathequeDashboard();   // at /mediatheque
});
```

```bash
php artisan mediatheque:install --dashboard
```

The command publishes `app/Providers/MediathequeServiceProvider.php`, which defines the `viewMediathequeDashboard` and `operateMediathequeDashboard` gates. Until they are defined, the dashboard is open in the `local` environment only. `php artisan mediatheque:status` shows the same overview in the terminal.

### Images with laravel-image

With `folklore/laravel-image` 2 installed, the package registers its `media` resolver: image URLs name a media, never a storage path. `med_…` is an image's original, or the poster of a video; `med_…/thumbnails/2` is one of its files. Each URL's version is the file's checksum, so new content gets new URLs, and private media get signed URLs. See [decisions/0013](docs/decisions/0013-media-resolver-for-laravel-image.md).

### Testing

```php
Mediatheque::fake();

$media = Mediatheque::ingest(UploadedFile::fake()->create('clip.mp4', 2048, 'video/mp4'));

Mediatheque::assertIngested(fn (Media $media) => $media->type() === 'video');
Mediatheque::assertRendition($media, 'h264', JobStatus::Completed);
```

## License

MIT
