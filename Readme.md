# Laravel Mediatheque

[![Latest Stable Version](https://poser.pugx.org/folklore/laravel-mediatheque/v/stable.svg)](https://packagist.org/packages/folklore/laravel-mediatheque)
[![CI](https://github.com/folkloreinc/laravel-mediatheque/actions/workflows/ci.yml/badge.svg?branch=v2.x)](https://github.com/folkloreinc/laravel-mediatheque/actions/workflows/ci.yml)
[![Total Downloads](https://poser.pugx.org/folklore/laravel-mediatheque/downloads.svg)](https://packagist.org/packages/folklore/laravel-mediatheque)

> **2.0 is in development on this branch**, released as alphas. An alpha can break the previous one: read the [CHANGELOG](CHANGELOG.md) before you update. [`docs/`](docs/README.md) has the design of 2.0 and [`docs/roadmap.md`](docs/roadmap.md) what each alpha contains. 1.x is maintained on [`v1.1`](https://github.com/folkloreinc/laravel-mediatheque/tree/v1.1).

`folklore/laravel-mediatheque` stores media (video, audio, images, documents, fonts), probes them, produces their renditions in your queue, and serves them through public or temporary URLs.

## The alphas: upload and play a video, images through laravel-image

The alphas are for new projects; the upgrade from 1.x comes in the beta. 2.0.0-alpha.1 uploads and plays videos; 2.0.0-alpha.2 adds image URLs through `laravel-image` 2.

### Requirements

- PHP 8.3+ and Laravel 12+.
- ffmpeg and ffprobe on the servers that run your queue. The Imagick extension is optional: without it, image sizes come from `getimagesize()`.
- A queue worker: probes and renditions run in jobs.
- For direct uploads: an S3-compatible disk (`league/flysystem-aws-s3-v3`).

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

### Direct uploads

Large files go from the client to an S3-compatible disk in parts, without going through PHP. The routes are off until you register them inside your own middleware:

```php
// routes/web.php
Route::middleware(['web', 'auth'])->group(function () {
    Route::mediatheque();
});
```

Every request needs a user, and the `create` ability of your media policy: without a policy, everything is denied.

```php
Gate::policy(\Folklore\Mediatheque\Models\Media::class, MediaPolicy::class);
```

Choose, on the server, what the media of an upload gets:

```php
Mediatheque::uploadsUsing(fn (Request $request, Upload $upload) => new IngestOptions(
    attributes: ['tenant_id' => $request->user()->current_tenant_id],
    visibility: Visibility::Private,
));
```

With Uppy 5's `AwsS3` plugin, each of its functions calls one route, and the server creates and completes the multipart upload ([architecture.md](docs/architecture.md#http)). The bucket's CORS rules must allow `PUT` from your origin and expose the `ETag` header.

With Uppy 6 (`@uppy/aws-s3` 6.1 or later), the browser sends every S3 request itself: `POST uploads/sign` presigns each one, and gives a new upload its own key. Complete the upload once Uppy is done, so the package ingests it:

```js
uppy.use(AwsS3, {
  shouldUseMultipart: true,
  signRequest: (request) => api.post('/mediatheque/uploads/sign', request), // → {url, key}
})
uppy.on('upload-success', (file, { body }) => {
  const id = body.key.split('/').pop() // the key ends with the upload's id
  return api.post(`/mediatheque/uploads/${id}/complete`, { filename: file.name })
})
```

The bucket's CORS rules must then allow `PUT`, `POST`, `GET` and `DELETE` from your origin, allow the `Content-Type` header, and expose `ETag`:

```json
[{
  "AllowedOrigins": ["https://app.example.com"],
  "AllowedMethods": ["PUT", "POST", "GET", "DELETE"],
  "AllowedHeaders": ["Content-Type"],
  "ExposeHeaders": ["ETag"]
}]
```

The routes work under a prefix with your own parameters, such as `Route::prefix('workspaces/{workspace}')->group(fn () => Route::mediatheque())`.

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
