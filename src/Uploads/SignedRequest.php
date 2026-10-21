<?php

namespace Folklore\Mediatheque\Uploads;

use Folklore\Mediatheque\Models\Upload;
use JsonSerializable;

/**
 * One S3 request presigned for a client, as Uppy 6's `signRequest` expects
 * it (docs/decisions/0016, 0018): the URL, the key of the upload, and the
 * upload. `created` is true when the request started the upload.
 */
final readonly class SignedRequest implements JsonSerializable
{
    public function __construct(
        public string $url,
        public string $key,
        public Upload $upload,
        public bool $created,
    ) {}

    /**
     * `{url, key}`, and `upload`, the upload's id, when the request started
     * it: clients keep it to complete the upload, rather than reading it
     * from the key.
     *
     * @return array{url: string, key: string, upload?: string}
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'key' => $this->key,
            ...($this->created ? ['upload' => $this->upload->uid] : []),
        ];
    }

    /**
     * @return array{url: string, key: string, upload?: string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
