<?php

namespace Folklore\Mediatheque\Facades;

use Folklore\Mediatheque\Contracts\Ingestor;
use Folklore\Mediatheque\Contracts\Mediatheque as MediathequeContract;
use Folklore\Mediatheque\Contracts\Prober;
use Folklore\Mediatheque\Contracts\Recorder;
use Folklore\Mediatheque\Contracts\UrlGenerator;
use Folklore\Mediatheque\Testing\MediathequeFake;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Folklore\Mediatheque\Contracts\Models\Media ingest(mixed $source, \Folklore\Mediatheque\Enums\Visibility|null $visibility = null, string|null $pipeline = null, string|null $name = null, array<string, mixed> $data = [], array<string, mixed> $attributes = [])
 * @method static \Folklore\Mediatheque\Contracts\Models\Media replaceOriginal(\Folklore\Mediatheque\Contracts\Models\Media $media, mixed $source, \Folklore\Mediatheque\Ingest\IngestOptions|null $options = null, bool $allowTypeChange = false)
 * @method static \Folklore\Mediatheque\Contracts\Models\Media|null resolve(\Folklore\Mediatheque\References\MediaReference|string $reference)
 * @method static \Illuminate\Support\Collection<string, \Folklore\Mediatheque\Contracts\Models\Media> resolveMany(iterable<\Folklore\Mediatheque\References\MediaReference|string> $references)
 * @method static \Folklore\Mediatheque\Contracts\Models\PipelineRun|null runPipeline(\Folklore\Mediatheque\Contracts\Models\Media $media, string|null $pipeline = null, list<string>|null $renditions = null, bool $force = false)
 * @method static \Folklore\Mediatheque\Contracts\Models\PipelineRun|null retry(\Folklore\Mediatheque\Contracts\Models\PipelineRun $run)
 * @method static bool cancel(\Folklore\Mediatheque\Contracts\Models\PipelineRun $run)
 * @method static bool refresh(\Folklore\Mediatheque\Contracts\Models\PipelineRun $run)
 * @method static bool probeAgain(\Folklore\Mediatheque\Contracts\Models\Media $media)
 * @method static void monitoringScope((\Closure(\Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>, string): mixed)|null $scope)
 * @method static void assertIngested(\Closure(\Folklore\Mediatheque\Contracts\Models\Media): bool|null $callback = null, int|null $times = null)
 * @method static void assertNotIngested(\Closure(\Folklore\Mediatheque\Contracts\Models\Media): bool|null $callback = null)
 * @method static void assertNothingIngested()
 * @method static void assertPipelineRan(\Folklore\Mediatheque\Contracts\Models\Media $media, string|null $pipeline = null)
 * @method static void assertPipelineNotRan(\Folklore\Mediatheque\Contracts\Models\Media $media, string|null $pipeline = null)
 * @method static void assertRendition(\Folklore\Mediatheque\Contracts\Models\Media $media, string $rendition, \Folklore\Mediatheque\Enums\JobStatus $status)
 *
 * @see MediathequeContract
 * @see MediathequeFake
 */
class Mediatheque extends Facade
{
    /**
     * Replace the mediatheque with a fake: ingest, probe and pipelines in
     * memory, files on fake disks. Calling it again returns the same fake.
     */
    public static function fake(): MediathequeFake
    {
        $root = static::isFake() ? static::getFacadeRoot() : null;
        if ($root instanceof MediathequeFake) {
            return $root;
        }

        $app = static::getFacadeApplication();
        if ($app === null) {
            throw new \RuntimeException('Mediatheque::fake() needs a Laravel application.');
        }

        $fake = new MediathequeFake(
            $app->make(Config::class),
            $app->make(Dispatcher::class),
            $app->make(Filesystems::class),
        );

        static::swap($fake);
        $app->instance(Ingestor::class, $fake->ingestor());
        $app->instance(Recorder::class, $fake->recorder());
        $app->instance(Prober::class, $fake->prober());
        $app->instance(UrlGenerator::class, $fake->urls());

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return MediathequeContract::class;
    }
}
