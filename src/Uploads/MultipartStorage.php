<?php

namespace Folklore\Mediatheque\Uploads;

use Aws\S3\S3Client;
use Folklore\Mediatheque\Exceptions\InvalidConfig;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Date;

/**
 * Multipart uploads on an S3-compatible disk, through the disk's own client:
 * the package signs requests, and the client sends the parts to the storage
 * directly. Needs `league/flysystem-aws-s3-v3`.
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
        [$client, $bucket, $key] = $this->target($disk, $path);

        $command = $client->getCommand('UploadPart', [
            'Bucket' => $bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
            'PartNumber' => $part,
        ]);

        return (string) $client->createPresignedRequest($command, Date::now()->addSeconds($ttl))->getUri();
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
