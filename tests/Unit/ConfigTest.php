<?php

namespace Folklore\Mediatheque\Tests\Unit;

use Folklore\Mediatheque\Tests\TestCase;

class ConfigTest extends TestCase
{
    public function testVideoPipelineSkipsMediaConvertWhenNotConfigured()
    {
        $this->assertArrayNotHasKey('media_convert', config('mediatheque.pipelines.video.jobs'));
    }

    public function testVideoPipelineRunsMediaConvertWhenConfigured()
    {
        putenv('AWS_MEDIACONVERT_ROLE=arn:aws:iam::123456789012:role/MediaConvert');
        try {
            $config = require __DIR__.'/../../src/config/config.php';
        } finally {
            putenv('AWS_MEDIACONVERT_ROLE');
        }

        $this->assertArrayHasKey('media_convert', $config['pipelines']['video']['jobs']);
    }
}
