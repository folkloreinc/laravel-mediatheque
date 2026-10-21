<?php

namespace Folklore\Mediatheque\Uploads;

use Folklore\Mediatheque\Contracts\Ingestor;
use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Enums\RejectionReason;
use Folklore\Mediatheque\Enums\UploadStatus;
use Folklore\Mediatheque\Exceptions\FileRejected;
use Folklore\Mediatheque\Exceptions\UnknownS3Operation;
use Folklore\Mediatheque\Exceptions\UploadExpired;
use Folklore\Mediatheque\Exceptions\UploadIncomplete;
use Folklore\Mediatheque\Exceptions\UploadNotFound;
use Folklore\Mediatheque\Ingest\IngestOptions;
use Folklore\Mediatheque\Ingest\Source;
use Folklore\Mediatheque\Models\Upload;
use Folklore\Mediatheque\Support\MediathequeConfig;
use Folklore\Mediatheque\Support\Models;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;

/**
 * Direct uploads to an S3-compatible disk, as services: what the package's
 * upload routes do, for an application or a package that offers the same
 * flow under its own routes (docs/decisions/0015, 0016, 0018).
 *
 * - Every method authorizes the user with the `create` ability of the media
 *   model's policy (AuthorizationException); without a policy, every user is
 *   denied.
 * - An upload belongs to the user who created it: for anyone else it doesn't
 *   exist (UploadNotFound). An open upload that expired can't be continued
 *   (UploadExpired).
 * - `complete()` makes sure the file is on the storage; `ingest()` turns it
 *   into a media, with the options of `Mediatheque::uploadsUsing()`, and
 *   deletes a file ingest rejects (FileRejected).
 */
class DirectUploads
{
    public const int MAX_PARTS = 10000;

    public function __construct(
        private readonly Gate $gate,
        private readonly MultipartStorage $storage,
        private readonly UploadOptions $options,
        private readonly MediathequeConfig $config,
        private readonly Ingestor $ingestor,
        private readonly Filesystems $filesystems,
        private readonly Container $container,
    ) {}

    /**
     * Start a multipart upload the server completes (Uppy 5), for a file the
     * client declares. The size is checked against the largest type now,
     * and against the file's type at ingest.
     *
     * @throws AuthorizationException
     * @throws FileRejected When the size is larger than every type allows
     */
    public function create(Model $user, string $filename, int $size): Upload
    {
        $this->authorize($user);

        $limit = $this->config->largestSize();
        if ($limit !== null && $size > $limit) {
            throw FileRejected::because(RejectionReason::TooLarge, sprintf('Files can\'t be larger than %d bytes.', $limit));
        }

        $upload = $this->newUpload($user, $filename, $size);
        $upload->forceFill(['provider_upload_id' => $this->storage->start($upload->disk, $upload->path)])->save();

        return $upload;
    }

    /**
     * Presign one S3 request the client sends to the storage itself, for
     * Uppy 6's `signRequest` (docs/decisions/0016).
     *
     * - A request that starts an upload (`CreateMultipartUpload`: `POST`
     *   without `uploadId`; `PutObject`: `PUT` without `uploadId`) creates
     *   an upload for the user, with its own key `{uploads.path}/{upl_…}`,
     *   whatever key the client proposed.
     * - Any other request is signed only for the key of an open upload of
     *   the user, and only for the multipart upload that key started: the
     *   first `uploadId` signed for an upload is the only one it accepts.
     *
     * @throws AuthorizationException
     * @throws UnknownS3Operation
     * @throws UploadNotFound
     * @throws UploadExpired
     */
    public function sign(Model $user, string $method, string $key, ?string $uploadId = null, ?int $partNumber = null): SignedRequest
    {
        $this->authorize($user);

        $uploadId = $uploadId !== '' ? $uploadId : null;
        $operation = S3Operation::fromRequest($method, $uploadId !== null, $partNumber !== null) ?? throw UnknownS3Operation::make();
        $ttl = $this->config->uploadsUrlTtl();

        if ($operation->startsUpload()) {
            $upload = $this->newUpload($user, null, null);
            $url = $this->storage->sign($upload->disk, $upload->path, $operation, $ttl);
            $upload->save();

            return new SignedRequest($url, $this->key($upload), $upload, created: true);
        }

        $upload = $this->ownedByKey($user, $key, $uploadId);
        $url = $this->storage->sign($upload->disk, $upload->path, $operation, $ttl, $uploadId, $partNumber);

        return new SignedRequest($url, $this->key($upload), $upload, created: false);
    }

    /**
     * An upload of the user by its id, still open, or completed too with
     * `$completed`.
     *
     * @throws AuthorizationException
     * @throws UploadNotFound
     * @throws UploadExpired
     */
    public function find(Model $user, Upload|string $upload, bool $completed = false): Upload
    {
        $this->authorize($user);

        return $this->owned($user, $upload, $completed);
    }

    /**
     * The open upload of the user that has this key on its disk. With an
     * `$uploadId`, the upload must be that multipart upload: the first id
     * given for an upload is recorded, and any other is refused.
     *
     * @throws AuthorizationException
     * @throws UploadNotFound
     * @throws UploadExpired
     */
    public function findByKey(Model $user, string $key, ?string $uploadId = null): Upload
    {
        $this->authorize($user);

        return $this->ownedByKey($user, $key, $uploadId !== '' ? $uploadId : null);
    }

    /**
     * The key of an upload in its disk's bucket, with the disk's root: the
     * key the client sends its requests for.
     */
    public function key(Upload $upload): string
    {
        return $this->storage->key($upload->disk, $upload->path);
    }

    /**
     * URLs the client sends parts of a multipart upload to, with PUT
     * requests (Uppy 5).
     *
     * @param  list<int>  $numbers
     * @return list<array{number: int, url: string}>
     *
     * @throws AuthorizationException
     * @throws UploadNotFound
     * @throws UploadExpired
     * @throws UploadIncomplete When the upload has no multipart upload
     */
    public function signParts(Model $user, Upload|string $upload, array $numbers): array
    {
        $this->authorize($user);
        $upload = $this->owned($user, $upload);
        $uploadId = $upload->provider_upload_id ?? throw UploadIncomplete::notMultipart();
        $ttl = $this->config->uploadsUrlTtl();

        return array_map(fn (int $number) => [
            'number' => $number,
            'url' => $this->storage->signPart($upload->disk, $upload->path, $uploadId, $number, $ttl),
        ], $numbers);
    }

    /**
     * The parts the storage received, so an interrupted upload resumes.
     *
     * @return list<array{number: int, etag: string, size: int}>
     *
     * @throws AuthorizationException
     * @throws UploadNotFound
     * @throws UploadExpired
     * @throws UploadIncomplete When the upload has no multipart upload
     */
    public function parts(Model $user, Upload|string $upload): array
    {
        $this->authorize($user);
        $upload = $this->owned($user, $upload);

        return $this->storage->parts($upload->disk, $upload->path, $upload->provider_upload_id ?? throw UploadIncomplete::notMultipart());
    }

    /**
     * Make sure the upload's file is on the storage, without ingesting it.
     *
     * - With `$parts`, the server assembles the multipart upload (Uppy 5).
     * - Without, the client already completed it on the storage, or sent the
     *   file in one `PutObject` (Uppy 6): the file must be there.
     *
     * `$filename` names the upload when it has no file name yet, and the
     * size of the stored file is recorded when the client didn't declare
     * one. The upload stays open until it is ingested or expires. A
     * completed upload is returned as it is.
     *
     * @param  list<array{number: int, etag: string}>|null  $parts
     *
     * @throws AuthorizationException
     * @throws UploadNotFound
     * @throws UploadExpired
     * @throws UploadIncomplete
     */
    public function complete(Model $user, Upload|string $upload, ?array $parts = null, ?string $filename = null): Upload
    {
        $this->authorize($user);
        $upload = $this->owned($user, $upload, completed: true);

        if ($upload->status === UploadStatus::Completed) {
            return $upload;
        }

        $disk = $this->filesystems->disk($upload->disk);

        if ($parts !== null) {
            $this->storage->complete($upload->disk, $upload->path, $upload->provider_upload_id ?? throw UploadIncomplete::notMultipart(), $parts);
        } elseif (! $disk->exists($upload->path)) {
            throw UploadIncomplete::notOnStorage();
        }

        $upload->forceFill(array_filter([
            'filename' => $upload->filename === null && $filename !== null && $filename !== '' ? $filename : null,
            'size' => $upload->size === null ? $disk->size($upload->path) : null,
        ], fn (mixed $value) => $value !== null))->save();

        return $upload;
    }

    /**
     * Ingest the upload's file into a media, and complete the upload.
     *
     * `$options` are the media's: by default, those of
     * `Mediatheque::uploadsUsing()` for the current request. Pass them only
     * from what the application decides, never from fields the client
     * sends. A rejected file is deleted, and the upload aborted with the
     * reason. A completed upload gives the media it created.
     *
     * @throws AuthorizationException
     * @throws UploadNotFound When the upload, or the media it created, doesn't exist
     * @throws UploadExpired
     * @throws UploadIncomplete When the file isn't on the storage yet
     * @throws FileRejected
     */
    public function ingest(Model $user, Upload|string $upload, ?IngestOptions $options = null): Media
    {
        $this->authorize($user);
        $upload = $this->owned($user, $upload, completed: true);

        if ($upload->status === UploadStatus::Completed) {
            $media = $upload->media()->first();

            return $media instanceof Media ? $media : throw UploadNotFound::make();
        }

        $disk = $this->filesystems->disk($upload->disk);
        if (! $disk->exists($upload->path)) {
            throw UploadIncomplete::notOnStorage();
        }

        try {
            $media = $this->ingestor->ingest(
                Source::disk($upload->disk, $upload->path, $upload->filename),
                $options ?? $this->options->resolve($this->request(), $upload),
            );
        } catch (FileRejected $exception) {
            $disk->delete($upload->path);
            $upload->markAborted($exception->reason);

            throw $exception;
        }

        $disk->delete($upload->path);
        $mediaId = $media instanceof Model ? $media->getKey() : null;
        $upload->markCompleted(is_int($mediaId) || is_string($mediaId) ? $mediaId : null);

        return $media;
    }

    /**
     * Abort an open upload: its multipart upload and its parts, or the file
     * the client already sent.
     *
     * @throws AuthorizationException
     * @throws UploadNotFound
     * @throws UploadExpired
     */
    public function abort(Model $user, Upload|string $upload): void
    {
        $this->authorize($user);
        $upload = $this->owned($user, $upload);

        if ($upload->provider_upload_id !== null) {
            $this->storage->abort($upload->disk, $upload->path, $upload->provider_upload_id);
        }

        $this->filesystems->disk($upload->disk)->delete($upload->path);
        $upload->markAborted(Upload::ABORTED);
    }

    /**
     * @throws AuthorizationException
     */
    private function authorize(Model $user): void
    {
        $this->gate->forUser($user)->authorize('create', Models::media());
    }

    /**
     * A new upload of this user, not saved yet, with its own path.
     */
    private function newUpload(Model $user, ?string $filename, ?int $size): Upload
    {
        $upload = Models::upload()::query()->getModel()->newInstance();
        $uid = $upload->newUniqueId();

        return $upload->forceFill([
            'uid' => $uid,
            'status' => UploadStatus::Pending,
            'disk' => $this->config->uploadsDisk(),
            'path' => $this->config->uploadsPath().'/'.$uid,
            'filename' => $filename,
            'size' => $size,
            'creator_type' => $user->getMorphClass(),
            'creator_id' => $user->getKey(),
            'expires_at' => Date::now()->addSeconds($this->config->uploadsExpireAfter()),
        ]);
    }

    /**
     * An upload of this user that is still open, or completed too. Another
     * user's upload doesn't exist for this one. An upload given as a model
     * is looked up again, so it is checked like an id.
     *
     * @throws UploadNotFound
     * @throws UploadExpired
     */
    private function owned(Model $user, Upload|string $upload, bool $completed = false): Upload
    {
        $upload = Models::upload()::query()
            ->where('uid', $upload instanceof Upload ? $upload->uid : $upload)
            ->where('creator_type', $user->getMorphClass())
            ->where('creator_id', $user->getKey())
            ->first();

        if ($upload === null || $upload->status === UploadStatus::Aborted || ($upload->status === UploadStatus::Completed && ! $completed)) {
            throw UploadNotFound::make();
        }

        if ($upload->status === UploadStatus::Pending && $upload->isExpired()) {
            throw UploadExpired::make();
        }

        return $upload;
    }

    /**
     * @throws UploadNotFound
     * @throws UploadExpired
     */
    private function ownedByKey(Model $user, string $key, ?string $uploadId): Upload
    {
        $upload = $this->owned($user, Str::afterLast($key, '/'));

        if ($this->key($upload) !== $key) {
            throw UploadNotFound::make();
        }

        if ($uploadId !== null && $upload->provider_upload_id === null) {
            // Only the first multipart upload of a key is recorded, even when two requests race
            $upload->newQuery()->whereKey($upload->getKey())->whereNull('provider_upload_id')->update(['provider_upload_id' => $uploadId]);
            $upload->refresh();
        }

        if ($uploadId !== null && $upload->provider_upload_id !== $uploadId) {
            throw UploadNotFound::make();
        }

        return $upload;
    }

    /**
     * The current request, which `Mediatheque::uploadsUsing()` receives; an
     * empty one outside HTTP.
     */
    private function request(): Request
    {
        $request = $this->container->bound('request') ? $this->container->make('request') : null;

        return $request instanceof Request ? $request : new Request;
    }
}
