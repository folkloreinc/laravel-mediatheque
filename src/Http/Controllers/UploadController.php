<?php

namespace Folklore\Mediatheque\Http\Controllers;

use Closure;
use Folklore\Mediatheque\Contracts\Ingestor;
use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Enums\UploadStatus;
use Folklore\Mediatheque\Exceptions\FileRejected;
use Folklore\Mediatheque\Http\Problem;
use Folklore\Mediatheque\Ingest\Source;
use Folklore\Mediatheque\Models\Upload;
use Folklore\Mediatheque\Support\MediathequeConfig;
use Folklore\Mediatheque\Support\Models;
use Folklore\Mediatheque\Uploads\MultipartStorage;
use Folklore\Mediatheque\Uploads\UploadOptions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Contracts\Validation\Factory as Validation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Presigned multipart uploads, for Uppy's `AwsS3` plugin and other clients
 * (docs/decisions/0006, 0008, 0015).
 *
 * - Every step is authorized with the `create` ability of the media
 *   model's policy; without a policy, every request is denied.
 * - An upload belongs to the user who created it: for anyone else it
 *   doesn't exist. Guests can't upload.
 * - Completing an upload ingests the file from the disk, with the options
 *   of `Mediatheque::uploadsUsing()`. A rejected file is deleted.
 * - Errors are `application/problem+json`.
 */
class UploadController
{
    private const int MAX_PARTS = 10000;

    private const int PARTS_PER_REQUEST = 100;

    public function __construct(
        private readonly Gate $gate,
        private readonly Validation $validation,
        private readonly MultipartStorage $storage,
        private readonly UploadOptions $options,
        private readonly MediathequeConfig $config,
        private readonly Ingestor $ingestor,
        private readonly Filesystems $filesystems,
    ) {}

    /**
     * Create an upload: `{filename, size}` → `{id, expires_at}`.
     */
    public function store(Request $request): SymfonyResponse
    {
        return $this->respond($request, function (Model $user) use ($request) {
            $input = $this->validate($request, [
                'filename' => ['required', 'string', 'max:255'],
                'size' => ['required', 'integer', 'min:1'],
            ]);

            $size = $this->integer($input['size'] ?? null);
            $limit = $this->config->largestSize();
            if ($limit !== null && $size > $limit) {
                return Problem::response(422, 'The file is too large.', sprintf('Files can\'t be larger than %d bytes.', $limit), ['reason' => 'too_large']);
            }

            $upload = Models::upload()::query()->getModel()->newInstance();
            $disk = $this->config->uploadsDisk();
            $uid = $upload->newUniqueId();
            $path = $this->config->uploadsPath().'/'.$uid;

            $upload->forceFill([
                'uid' => $uid,
                'status' => UploadStatus::Pending,
                'disk' => $disk,
                'path' => $path,
                'filename' => is_string($input['filename'] ?? null) ? $input['filename'] : null,
                'size' => $size,
                'creator_type' => $user->getMorphClass(),
                'creator_id' => $user->getKey(),
                'expires_at' => Date::now()->addSeconds($this->config->uploadsExpireAfter()),
                'provider_upload_id' => $this->storage->start($disk, $path),
            ])->save();

            return new JsonResponse([
                'id' => $upload->uid,
                'expires_at' => $upload->expires_at->toIso8601String(),
            ], 201);
        });
    }

    /**
     * Sign parts: `{part_numbers: [1, 2…]}` → `{parts: [{number, url}]}`.
     */
    public function signParts(Request $request, string $upload): SymfonyResponse
    {
        return $this->respond($request, function (Model $user) use ($request, $upload) {
            $upload = $this->find($user, $upload);
            if (! $upload instanceof Upload) {
                return $upload;
            }

            $input = $this->validate($request, [
                'part_numbers' => ['required', 'array', 'min:1', 'max:'.self::PARTS_PER_REQUEST],
                'part_numbers.*' => ['integer', 'between:1,'.self::MAX_PARTS, 'distinct'],
            ]);

            $parts = [];
            foreach (is_array($input['part_numbers'] ?? null) ? $input['part_numbers'] : [] as $number) {
                $number = $this->integer($number);
                $parts[] = [
                    'number' => $number,
                    'url' => $this->storage->signPart($upload->disk, $upload->path, (string) $upload->provider_upload_id, $number, $this->config->uploadsUrlTtl()),
                ];
            }

            return new JsonResponse(['parts' => $parts]);
        });
    }

    /**
     * The parts the storage received: `{parts: [{number, etag, size}]}`.
     */
    public function listParts(Request $request, string $upload): SymfonyResponse
    {
        return $this->respond($request, function (Model $user) use ($upload) {
            $upload = $this->find($user, $upload);
            if (! $upload instanceof Upload) {
                return $upload;
            }

            return new JsonResponse(['parts' => $this->storage->parts($upload->disk, $upload->path, (string) $upload->provider_upload_id)]);
        });
    }

    /**
     * Complete an upload: `{parts: [{number, etag}]}` → the media.
     */
    public function complete(Request $request, string $upload): SymfonyResponse
    {
        return $this->respond($request, function (Model $user) use ($request, $upload) {
            $upload = $this->find($user, $upload, allowCompleted: true);
            if (! $upload instanceof Upload) {
                return $upload;
            }

            if ($upload->status === UploadStatus::Completed) {
                $media = $upload->media()->first();

                return $media instanceof Media ? $this->media($media, 200) : Problem::response(404, 'The upload doesn\'t exist.');
            }

            $input = $this->validate($request, [
                'parts' => ['required', 'array', 'min:1', 'max:'.self::MAX_PARTS],
                'parts.*.number' => ['required', 'integer', 'between:1,'.self::MAX_PARTS, 'distinct'],
                'parts.*.etag' => ['required', 'string', 'max:255'],
            ]);

            $parts = [];
            foreach (is_array($input['parts'] ?? null) ? $input['parts'] : [] as $part) {
                $parts[] = [
                    'number' => $this->integer(is_array($part) ? ($part['number'] ?? null) : null),
                    'etag' => is_array($part) && is_string($part['etag'] ?? null) ? $part['etag'] : '',
                ];
            }

            $this->storage->complete($upload->disk, $upload->path, (string) $upload->provider_upload_id, $parts);
            $disk = $this->filesystems->disk($upload->disk);

            try {
                $media = $this->ingestor->ingest(
                    Source::disk($upload->disk, $upload->path, $upload->filename),
                    $this->options->resolve($request, $upload),
                );
            } catch (FileRejected $exception) {
                $disk->delete($upload->path);
                $upload->forceFill(['status' => UploadStatus::Aborted])->save();

                return Problem::response(422, 'The file was rejected.', $exception->getMessage(), ['reason' => $exception->reason->value]);
            }

            $disk->delete($upload->path);
            $upload->forceFill([
                'status' => UploadStatus::Completed,
                'media_id' => $media instanceof Model ? $media->getKey() : null,
            ])->save();

            return $this->media($media, 201);
        });
    }

    /**
     * Abort an upload and remove its parts.
     */
    public function destroy(Request $request, string $upload): SymfonyResponse
    {
        return $this->respond($request, function (Model $user) use ($upload) {
            $upload = $this->find($user, $upload);
            if (! $upload instanceof Upload) {
                return $upload;
            }

            $this->storage->abort($upload->disk, $upload->path, (string) $upload->provider_upload_id);
            $upload->forceFill(['status' => UploadStatus::Aborted])->save();

            return new Response(null, 204);
        });
    }

    /**
     * Authorize the request, run the action, and turn errors into problems.
     *
     * @param  Closure(Model): SymfonyResponse  $action
     */
    private function respond(Request $request, Closure $action): SymfonyResponse
    {
        $user = $request->user();

        if (! $user instanceof Model) {
            return Problem::response(401, 'Authentication is required.');
        }

        try {
            $this->gate->forUser($user)->authorize('create', Models::media());

            return $action($user);
        } catch (AuthorizationException $exception) {
            return Problem::response(403, 'This action is unauthorized.', $exception->getMessage() !== 'This action is unauthorized.' ? $exception->getMessage() : null);
        } catch (ValidationException $exception) {
            return Problem::response(422, 'The request is invalid.', $exception->getMessage(), ['errors' => $exception->errors()]);
        }
    }

    /**
     * An upload of this user that is still open, or the problem to answer.
     * Another user's upload doesn't exist for this one.
     */
    private function find(Model $user, string $uid, bool $allowCompleted = false): Upload|JsonResponse
    {
        $upload = Models::upload()::query()
            ->where('uid', $uid)
            ->where('creator_type', $user->getMorphClass())
            ->where('creator_id', $user->getKey())
            ->first();

        if ($upload === null || $upload->status === UploadStatus::Aborted || ($upload->status === UploadStatus::Completed && ! $allowCompleted)) {
            return Problem::response(404, 'The upload doesn\'t exist.');
        }

        if ($upload->status === UploadStatus::Pending && $upload->isExpired()) {
            return Problem::response(410, 'The upload expired.');
        }

        return $upload;
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validate(Request $request, array $rules): array
    {
        $validated = $this->validation->make($request->all(), $rules)->validate();
        $input = [];

        foreach ($validated as $key => $value) {
            $input[(string) $key] = $value;
        }

        return $input;
    }

    private function integer(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function media(Media $media, int $status): JsonResponse
    {
        return new JsonResponse([
            'id' => $media->uid(),
            'type' => $media->type(),
            'name' => $media->name(),
            'status' => $media->status()->value,
            'visibility' => $media->visibility()->value,
        ], $status);
    }
}
