<?php

namespace Folklore\Mediatheque\Tests\Unit;

use Folklore\Mediatheque\Uploads\S3Operation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class S3OperationTest extends TestCase
{
    #[Test]
    public function each_request_of_uppy_maps_to_one_operation(): void
    {
        $this->assertSame(S3Operation::CreateMultipartUpload, S3Operation::fromRequest('POST', false));
        $this->assertSame(S3Operation::PutObject, S3Operation::fromRequest('put', false));
        $this->assertSame(S3Operation::UploadPart, S3Operation::fromRequest('PUT', true, true));
        $this->assertSame(S3Operation::ListParts, S3Operation::fromRequest('GET', true));
        $this->assertSame(S3Operation::CompleteMultipartUpload, S3Operation::fromRequest('POST', true));
        $this->assertSame(S3Operation::AbortMultipartUpload, S3Operation::fromRequest('DELETE', true));
        $this->assertSame(S3Operation::DeleteObject, S3Operation::fromRequest('DELETE', false));
    }

    #[Test]
    public function other_requests_map_to_none(): void
    {
        $this->assertNull(S3Operation::fromRequest('PUT', true));
        $this->assertNull(S3Operation::fromRequest('PUT', false, true));
        $this->assertNull(S3Operation::fromRequest('GET', false));
        $this->assertNull(S3Operation::fromRequest('POST', true, true));
        $this->assertNull(S3Operation::fromRequest('HEAD', false));
    }

    #[Test]
    public function only_the_first_request_starts_an_upload(): void
    {
        $starts = array_filter(S3Operation::cases(), fn (S3Operation $operation) => $operation->startsUpload());
        $this->assertSame([S3Operation::CreateMultipartUpload, S3Operation::PutObject], array_values($starts));
        $this->assertFalse(S3Operation::DeleteObject->needsUploadId());
        $this->assertTrue(S3Operation::UploadPart->needsUploadId());
    }
}
