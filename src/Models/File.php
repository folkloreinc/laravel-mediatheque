<?php

namespace Folklore\Mediatheque\Models;

use DateTimeInterface;
use Folklore\Mediatheque\Casts\Json;
use Folklore\Mediatheque\Contracts\Models\File as FileContract;
use Folklore\Mediatheque\Contracts\Models\Media as MediaContract;
use Folklore\Mediatheque\Contracts\Models\PipelineJob as PipelineJobContract;
use Folklore\Mediatheque\Contracts\UrlGenerator;
use Folklore\Mediatheque\Enums\Visibility;
use Folklore\Mediatheque\Support\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A file of a media, stored in the `files` table: its original, or a file of
 * one of its renditions.
 *
 * @property int $id
 * @property int $media_id
 * @property string $handle
 * @property int|null $index
 * @property string|null $role
 * @property string|null $source
 * @property string|null $path
 * @property string|null $name
 * @property string|null $mime
 * @property int|null $size
 * @property string|null $checksum
 * @property int|null $width
 * @property int|null $height
 * @property float|null $duration
 * @property int|null $bitrate
 * @property int|null $parts_count
 * @property array<string, mixed>|null $metadata
 * @property string|null $definition_hash
 * @property int|null $pipeline_job_id
 * @property string|null $provider
 * @property string|null $provider_ref
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read (Model&MediaContract)|null $media
 */
class File extends Model implements FileContract
{
    /**
     * Every column read by a contract method of the same name has a cast, so
     * Eloquent reads it as an attribute, never as a relation.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'handle' => 'string',
            'index' => 'integer',
            'role' => 'string',
            'source' => 'string',
            'path' => 'string',
            'mime' => 'string',
            'checksum' => 'string',
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'duration' => 'float',
            'bitrate' => 'integer',
            'parts_count' => 'integer',
            'metadata' => Json::class,
        ];
    }

    public function getTable(): string
    {
        return $this->table ?? Models::table('files');
    }

    /**
     * Its media, deleted or not, whatever the application's global scopes.
     *
     * @return BelongsTo<Model&MediaContract, $this>
     */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Models::media(), 'media_id')->withoutGlobalScopes();
    }

    /**
     * The pipeline job that produced it.
     *
     * @return BelongsTo<Model&PipelineJobContract, $this>
     */
    public function pipelineJob(): BelongsTo
    {
        return $this->belongsTo(Models::pipelineJob(), 'pipeline_job_id');
    }

    public function handle(): string
    {
        return $this->handle;
    }

    public function index(): ?int
    {
        return $this->index;
    }

    public function role(): ?string
    {
        return $this->role;
    }

    public function source(): ?string
    {
        return $this->source;
    }

    public function path(): ?string
    {
        return $this->path;
    }

    public function mime(): ?string
    {
        return $this->mime;
    }

    public function size(): ?int
    {
        return $this->size;
    }

    public function checksum(): ?string
    {
        return $this->checksum;
    }

    public function width(): ?int
    {
        return $this->width;
    }

    public function height(): ?int
    {
        return $this->height;
    }

    public function duration(): ?float
    {
        return $this->duration;
    }

    public function bitrate(): ?int
    {
        return $this->bitrate;
    }

    public function partsCount(): ?int
    {
        return $this->parts_count;
    }

    public function metadata(?string $key = null, mixed $default = null): mixed
    {
        $metadata = $this->metadata ?? [];

        return $key === null ? $metadata : Arr::get($metadata, $key, $default);
    }

    public function visibility(): Visibility
    {
        $media = $this->media ?? throw new LogicException(sprintf('The file %d has no media.', $this->id));

        return $media->visibility();
    }

    public function isRemote(): bool
    {
        return $this->provider !== null;
    }

    public function url(): ?string
    {
        return app(UrlGenerator::class)->url($this);
    }

    public function temporaryUrl(DateTimeInterface $expiration): ?string
    {
        return app(UrlGenerator::class)->temporaryUrl($this, $expiration);
    }
}
