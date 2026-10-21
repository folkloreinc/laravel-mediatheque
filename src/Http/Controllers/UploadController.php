<?php

namespace Folklore\Mediatheque\Http\Controllers;

use Closure;
use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Enums\UploadStatus;
use Folklore\Mediatheque\Exceptions\FileRejected;
use Folklore\Mediatheque\Exceptions\UnknownS3Operation;
use Folklore\Mediatheque\Exceptions\UploadExpired;
use Folklore\Mediatheque\Exceptions\UploadIncomplete;
use Folklore\Mediatheque\Exceptions\UploadNotFound;
use Folklore\Mediatheque\Http\Problem;
use Folklore\Mediatheque\Models\Upload;
use Folklore\Mediatheque\Support\Models;
use Folklore\Mediatheque\Uploads\DirectUploads;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Validation\Factory as Validation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Presigned multipart uploads, for Uppy's `AwsS3` plugin and other clients
 * (docs/decisions/0006, 0008, 0015, 0016, 0018): an HTTP layer over
 * DirectUploads, which an application can also call from its own routes.
 *
 * - With Uppy 5, the server creates and completes the multipart upload, and
 *   signs its parts. With Uppy 6, the client sends every S3 request itself,
 *   presigned by `sign()`, then completes the upload here to ingest it.
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
    private const int PARTS_PER_REQUEST = 100;

    public function __construct(
        private readonly Gate $gate,
        private readonly Validation $validation,
        private readonly DirectUploads $uploads,
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

            $upload = $this->uploads->create($user, $this->string($input['filename'] ?? null), $this->integer($input['size'] ?? null));

            return new JsonResponse([
                'id' => $upload->uid,
                'expires_at' => $upload->expires_at->toIso8601String(),
            ], 201);
        });
    }

    /**
     * Presign one S3 request, for Uppy 6's `signRequest`: `{method, key,
     * uploadId?, partNumber?}` → `{url, key}`, and `upload`, the upload's
     * id, when the request starts an upload (docs/decisions/0016, 0018).
     */
    public function sign(Request $request): SymfonyResponse
    {
        return $this->respond($request, function (Model $user) use ($request) {
            $input = $this->validate($request, [
                'method' => ['required', 'string', 'in:GET,PUT,POST,DELETE'],
                'key' => ['required', 'string', 'max:1024'],
                'uploadId' => ['sometimes', 'nullable', 'string', 'max:1024'],
                'partNumber' => ['sometimes', 'nullable', 'integer', 'between:1,'.DirectUploads::MAX_PARTS],
                'contentType' => ['sometimes', 'nullable', 'string', 'max:255'],
            ]);

            return new JsonResponse($this->uploads->sign(
                $user,
                $this->string($input['method'] ?? null),
                $this->string($input['key'] ?? null),
                is_string($input['uploadId'] ?? null) ? $input['uploadId'] : null,
                isset($input['partNumber']) ? $this->integer($input['partNumber']) : null,
            )->toArray());
        });
    }

    /**
     * Sign parts: `{part_numbers: [1, 2…]}` → `{parts: [{number, url}]}`.
     */
    public function signParts(Request $request): SymfonyResponse
    {
        return $this->respond($request, function (Model $user) use ($request) {
            $upload = $this->uploads->find($user, $this->uploadParameter($request));

            $input = $this->validate($request, [
                'part_numbers' => ['required', 'array', 'min:1', 'max:'.self::PARTS_PER_REQUEST],
                'part_numbers.*' => ['integer', 'between:1,'.DirectUploads::MAX_PARTS, 'distinct'],
            ]);
            $numbers = array_values(array_map(fn (mixed $number) => $this->integer($number), is_array($input['part_numbers'] ?? null) ? $input['part_numbers'] : []));

            return new JsonResponse(['parts' => $this->uploads->signParts($user, $upload, $numbers)]);
        });
    }

    /**
     * The parts the storage received: `{parts: [{number, etag, size}]}`.
     */
    public function listParts(Request $request): SymfonyResponse
    {
        return $this->respond($request, fn (Model $user) => new JsonResponse([
            'parts' => $this->uploads->parts($user, $this->uploadParameter($request)),
        ]));
    }

    /**
     * Complete an upload and ingest it → the media.
     *
     * - With `{parts: [{number, etag}]}`, the server completes the multipart
     *   upload on the storage (Uppy 5).
     * - Without `parts`, the client already completed it on the storage, or
     *   sent the file in one `PutObject` (Uppy 6): the file must be there.
     *
     * `filename` names the media when the upload has no file name yet.
     */
    public function complete(Request $request): SymfonyResponse
    {
        return $this->respond($request, function (Model $user) use ($request) {
            $upload = $this->uploads->find($user, $this->uploadParameter($request), completed: true);

            if ($upload->status === UploadStatus::Completed) {
                return $this->media($this->uploads->ingest($user, $upload), 200);
            }

            $input = $this->validate($request, [
                'parts' => ['sometimes', 'array', 'min:1', 'max:'.DirectUploads::MAX_PARTS],
                'parts.*.number' => ['required', 'integer', 'between:1,'.DirectUploads::MAX_PARTS, 'distinct'],
                'parts.*.etag' => ['required', 'string', 'max:255'],
                'filename' => ['sometimes', 'nullable', 'string', 'max:255'],
            ]);

            $parts = null;
            if (is_array($input['parts'] ?? null)) {
                $parts = [];
                foreach ($input['parts'] as $part) {
                    $parts[] = [
                        'number' => $this->integer(is_array($part) ? ($part['number'] ?? null) : null),
                        'etag' => is_array($part) && is_string($part['etag'] ?? null) ? $part['etag'] : '',
                    ];
                }
            }

            $upload = $this->uploads->complete($user, $upload, $parts, is_string($input['filename'] ?? null) ? $input['filename'] : null);

            return $this->media($this->uploads->ingest($user, $upload), 201);
        });
    }

    /**
     * Abort an upload and remove its parts.
     */
    public function destroy(Request $request): SymfonyResponse
    {
        return $this->respond($request, function (Model $user) use ($request) {
            $this->uploads->abort($user, $this->uploadParameter($request));

            return new Response(null, 204);
        });
    }

    /**
     * Authorize the request, run the action, and turn errors into problems.
     * The policy is checked before the request is validated, and again by
     * each service.
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
        } catch (UnknownS3Operation $exception) {
            return Problem::response(422, 'The request is invalid.', $exception->getMessage(), ['reason' => UnknownS3Operation::REASON]);
        } catch (UploadNotFound) {
            return Problem::response(404, 'The upload doesn\'t exist.');
        } catch (UploadExpired) {
            return Problem::response(410, 'The upload expired.');
        } catch (UploadIncomplete $exception) {
            $title = $exception->reason === UploadIncomplete::NOT_MULTIPART ? 'The upload has no multipart upload.' : 'The upload is not complete.';

            return Problem::response(409, $title, $exception->getMessage(), ['reason' => $exception->reason]);
        } catch (FileRejected $exception) {
            return Problem::response(422, 'The file was rejected.', $exception->getMessage(), ['reason' => $exception->reason->value]);
        }
    }

    /**
     * The `{upload}` parameter of the route, read by name: an application
     * can put the routes under a prefix with its own parameters, which
     * Laravel would pass to the action first.
     */
    private function uploadParameter(Request $request): string
    {
        $upload = $request->route('upload');

        return match (true) {
            $upload instanceof Upload => $upload->uid,
            is_string($upload) => $upload,
            default => '',
        };
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

    private function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
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
