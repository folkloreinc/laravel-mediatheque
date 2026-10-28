<?php

namespace Folklore\Mediatheque\Tests\Unit;

use Folklore\Mediatheque\Contracts\Models\File;
use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Contracts\Models\PipelineJob;
use Folklore\Mediatheque\Contracts\Models\PipelineRun;
use Folklore\Mediatheque\Enums\RejectionReason;
use Folklore\Mediatheque\Enums\Visibility;
use Folklore\Mediatheque\Pipelines\Outputs;
use Folklore\Mediatheque\Transcoders\TranscoderError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Events carry contracts, not concrete classes, so they work with the
 * application's models (docs/architecture.md#events).
 */
class EventsTest extends TestCase
{
    private const array EVENTS = [
        'MediaCreated', 'MediaUpdated', 'MediaDeleted', 'MediaRestored', 'MediaProbed',
        'MediaReady', 'MediaFailed', 'MediaVisibilityChanged', 'OriginalReplaced',
        'PipelineStarted', 'PipelineCompleted', 'PipelineFailed', 'PipelineCanceled',
        'RenditionProgressed', 'RenditionCompleted', 'RenditionFailed', 'RenditionSkipped', 'RenditionCanceled',
        'IngestRejected',
    ];

    private const array ALLOWED_TYPES = [
        Media::class, File::class, PipelineRun::class, PipelineJob::class,
        Outputs::class, TranscoderError::class, Visibility::class, RejectionReason::class, 'array', 'string', 'float',
    ];

    #[Test]
    public function every_event_of_the_architecture_exists(): void
    {
        $files = glob(__DIR__.'/../../src/Events/*.php') ?: [];
        $this->assertEqualsCanonicalizing(self::EVENTS, array_map(fn (string $file) => basename($file, '.php'), $files));
    }

    #[Test]
    public function events_carry_contracts(): void
    {
        foreach (self::EVENTS as $name) {
            $class = new ReflectionClass('Folklore\\Mediatheque\\Events\\'.$name);
            $this->assertTrue($class->isReadOnly(), "{$name} is readonly");

            $constructor = $class->getConstructor();
            $this->assertNotNull($constructor);

            foreach ($constructor->getParameters() as $parameter) {
                $type = $parameter->getType();
                $this->assertInstanceOf(ReflectionNamedType::class, $type);
                $this->assertContains($type->getName(), self::ALLOWED_TYPES, "{$name}::\${$parameter->getName()}");
            }
        }
    }
}
