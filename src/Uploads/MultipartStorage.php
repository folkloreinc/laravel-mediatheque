<?php

namespace Folklore\Mediatheque\Uploads;

use Aws\S3\S3Client;
use Folklore\Mediatheque\Exceptions\InvalidConfig;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Filesystem\AwsS3V3Adapter;

/**
 * Multipart uploads on an S3-compatible disk, through the disk's own client:
 * the package signs requests, and the client sends the parts to the storage
 * directly. With Uppy 6, the client sends every S3 request itself, and the
 * package only presigns them (`sign()`). Needs `league/flysystem-aws-s3-v3`.
 */
class MultipartStorage
{
    public function __construct(
        private readonly Filesystems $filesystems,
    ) {}

    /**
     * Start a multipart upload. Returns the storage's upload id.
     */
    public function start(string $disk, string $path): string
    {
        [$client, $bucket, $key] = $this->target($disk, $path);

        $result = $client->createMultipartUpload([
            'Bucket' => $bucket,
            'Key' => $key,
            'ContentType' => 'application/octet-stream',
        ]);
        $uploadId = $result['UploadId'] ?? null;

        return is_string($uploadId) ? $uploadId : throw new \RuntimeException('The storage didn\'t start the upload.');
    }

    /**
     * A URL the client sends one part to, with a PUT request.
     */
    public function signPart(string $disk, string $path, string $uploadId, int $part, int $ttl): string
    {
        return $this->sign($disk, $path, S3Operation::UploadPart, $ttl, $uploadId, $part);
    }

    /**
     * A presigned URL for one S3 request the client sends to the storage
     * itself, with the operation's HTTP method. Signing needs no request to
     * the storage.
     */
    public function sign(string $disk, string $path, S3Operation $operation, int $ttl, ?string $uploadId = null, ?int $part = null): string
    {
        [$client, $bucket, $key] = $this->target($disk, $path);

        if ($operation->needsUploadId() && ($uploadId === null || $uploadId === '')) {
            throw new \InvalidArgumentException(sprintf('%s needs the id of a multipart upload.', $operation->value));
        }

        if ($operation === S3Operation::UploadPart && $part === null) {
            throw new \InvalidArgumentException('UploadPart needs a part number.');
        }

        $parameters = array_filter([
            'Bucket' => $bucket,
            'Key' => $key,
            'UploadId' => $operation->needsUploadId() ? $uploadId : null,
            'PartNumber' => $operation === S3Operation::UploadPart ? $part : null,
        ], fn (mixed $value) => $value !== null);

        $command = $client->getCommand($operation->value, $parameters);

        // Relative to the SDK's clock, which computes X-Amz-Expires: an
        // absolute time from Laravel's clock is off by any time between the
        // two reads, or by a frozen or traveled clock
        return (string) $client->createPresignedRequest($command, '+'.$ttl.' seconds')->getUri();
    }

    /**
     * The key of a file in the disk's bucket, with the disk's root.
     */
    public function key(string $disk, string $path): string
    {
        return $this->target($disk, $path)[2];
    }

    /**
     * The parts the storage received, so an interrupted upload resumes.
     *
     * @return list<array{number: int, etag: string, size: int}>
     */
    public function parts(string $disk, string $path, string $uploadId): array
    {
        [$client, $bucket, $key] = $this->target($disk, $path);
        $parts = [];
        $marker = 0;

        do {
            $result = $client->listParts(['Bucket' => $bucket, 'Key' => $key, 'UploadId' => $uploadId, 'PartNumberMarker' => $marker]);
            $listed = $result['Parts'] ?? [];

            foreach (is_array($listed) ? $listed : [] as $part) {
                if (is_array($part) && is_numeric($part['PartNumber'] ?? null) && is_string($part['ETag'] ?? null)) {
                    $parts[] = ['number' => (int) $part['PartNumber'], 'etag' => $part['ETag'], 'size' => is_numeric($part['Size'] ?? null) ? (int) $part['Size'] : 0];
                }
            }

            $next = $result['NextPartNumberMarker'] ?? null;
            $truncated = ($result['IsTruncated'] ?? false) === true && is_numeric($next) && (int) $next > $marker;
            $marker = is_numeric($next) ? (int) $next : $marker;
        } while ($truncated);

        return $parts;
    }

    /**
     * Assemble the parts into the file.
     *
     * @param  list<array{number: int, etag: string}>  $parts
     */
    public function complete(string $disk, string $path, string $uploadId, array $parts): void
    {
        [$client, $bucket, $key] = $this->target($disk, $path);
        usort($parts, fn (array $a, array $b) => $a['number'] <=> $b['number']);

        $client->completeMultipartUpload([
            'Bucket' => $bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
            'MultipartUpload' => [
                'Parts' => array_map(fn (array $part) => ['PartNumber' => $part['number'], 'ETag' => $part['etag']], $parts),
            ],
        ]);
    }

    /**
     * Abort an upload and remove its parts.
     */
    public function abort(string $disk, string $path, string $uploadId): void
    {
        [$client, $bucket, $key] = $this->target($disk, $path);

        $client->abortMultipartUpload(['Bucket' => $bucket, 'Key' => $key, 'UploadId' => $uploadId]);
    }

    /**
     * Whether a disk takes multipart uploads.
     */
    public function supports(string $disk): bool
    {
        return $this->filesystems->disk($disk) instanceof AwsS3V3Adapter;
    }

    /**
     * @return array{S3Client, string, string}
     */
    private function target(string $disk, string $path): array
    {
        $filesystem = $this->filesystems->disk($disk);

        if (! $filesystem instanceof AwsS3V3Adapter) {
            throw InvalidConfig::at('uploads.disk', sprintf('the disk "%s" is not an S3-compatible disk, which multipart uploads need', $disk));
        }

        $bucket = $filesystem->getConfig()['bucket'] ?? null;
        if (! is_string($bucket) || $bucket === '') {
            throw InvalidConfig::at('uploads.disk', sprintf('the disk "%s" has no bucket', $disk));
        }

        return [$filesystem->getClient(), $bucket, $filesystem->path($path)];
    }
}
