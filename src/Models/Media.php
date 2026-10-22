<?php

namespace Folklore\Mediatheque\Models;

use DateTimeInterface;
use Folklore\Mediatheque\Casts\Json;
use Folklore\Mediatheque\Contracts\Mediatheque;
use Folklore\Mediatheque\Contracts\Models\File as FileContract;
use Folklore\Mediatheque\Contracts\Models\Media as MediaContract;
use Folklore\Mediatheque\Contracts\Models\PipelineRun;
use Folklore\Mediatheque\Enums\MediaStatus;
use Folklore\Mediatheque\Enums\Visibility;
use Folklore\Mediatheque\Events\MediaDeleted;
use Folklore\Mediatheque\Events\MediaRestored;
use Folklore\Mediatheque\Events\MediaUpdated;
use Folklore\Mediatheque\Exceptions\FileRejected;
use Folklore\Mediatheque\Ingest\IngestOptions;
use Folklore\Mediatheque\Ingest\Source;
use Folklore\Mediatheque\Support\MediathequeConfig;
use Folklore\Mediatheque\Support\Models;
use Folklore\Mediatheque\Support\Uid;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A media, stored in the `medias` table.
 *
 * The contract's methods read its data (`uid()`, `files()`); its Eloquent
 * relations have other names (`mediaFiles()`, `pipelineRuns()`,
 * docs/decisions/0014). Applications extend it and set their class in
 * `mediatheque.models.media`.
 *
 * @property int $id
 * @property string $uid
 * @property string $type
 * @property string|null $name
 * @property MediaStatus $status
 * @property Visibility $visibility
 * @property array<string, mixed>|null $metadata
 * @property array<string, mixed>|null $data
 * @property int|null $derived_from_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read EloquentCollection<int, Model&FileContract> $mediaFiles
 */
class Media extends Model implements MediaContract
{
    use SoftDeletes;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
        'visibility' => 'public',
    ];

    /**
     * @var array<string, class-string>
     */
    protected $dispatchesEvents = [
        'updated' => MediaUpdated::class,
        'deleted' => MediaDeleted::class,
        'restored' => MediaRestored::class,
    ];

    /**
     * A uid is set on insert when none was set, even when model events are
     * faked.
     *
     * @var bool
     */
    public $usesUniqueIds = true;

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uid'];
    }

    public function newUniqueId(): string
    {
        return Uid::generate(app(MediathequeConfig::class)->mediaPrefix());
    }

    /**
     * Every column read by a contract method of the same name has a cast, so
     * Eloquent reads it as an attribute, never as a relation.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'uid' => 'string',
            'type' => 'string',
            'name' => 'string',
            'status' => MediaStatus::class,
            'visibility' => Visibility::class,
            'metadata' => Json::class,
            'data' => Json::class,
        ];
    }

    public function getTable(): string
    {
        return $this->table ?? Models::table('medias');
    }

    public function getRouteKeyName(): string
    {
        return 'uid';
    }

    /**
     * @return HasMany<Model&FileContract, $this>
     */
    public function mediaFiles(): HasMany
    {
        return $this->hasMany(Models::file(), 'media_id')->chaperone('media');
    }

    /**
     * @return MorphMany<Model&PipelineRun, $this>
     */
    public function pipelineRuns(): MorphMany
    {
        return $this->morphMany(Models::pipelineRun(), 'pipelinable');
    }

    /**
     * @return BelongsTo<Model&MediaContract, $this>
     */
    public function derivedFrom(): BelongsTo
    {
        return $this->belongsTo(Models::media(), 'derived_from_id');
    }

    public function uid(): string
    {
        return $this->uid;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function name(): ?string
    {
        return $this->name;
    }

    public function status(): MediaStatus
    {
        return $this->status;
    }

    public function visibility(): Visibility
    {
        return $this->visibility;
    }

    public function metadata(?string $key = null, mixed $default = null): mixed
    {
        $metadata = $this->metadata ?? [];

        return $key === null ? $metadata : Arr::get($metadata, $key, $default);
    }

    public function original(): ?FileContract
    {
        return $this->file(FileContract::ORIGINAL);
    }

    public function file(string $handle, ?int $index = null): ?FileContract
    {
        return $this->files($handle)->first(fn (FileContract $file) => $index === null || $file->index() === $index);
    }

    public function files(?string $handle = null): Collection
    {
        $files = array_values($this->mediaFiles
            ->filter(fn (FileContract $file) => $handle === null || $file->handle() === $handle)
            ->sortBy([
                fn (FileContract $a, FileContract $b) => $a->handle() <=> $b->handle(),
                fn (FileContract $a, FileContract $b) => ($a->index() ?? -1) <=> ($b->index() ?? -1),
            ])
            ->all());

        return self::collect($files);
    }

    public function thumbnail(): ?FileContract
    {
        $files = $this->files();

        return $files->first(fn (FileContract $file) => $file->role() === 'poster')
            ?? $files->first(fn (FileContract $file) => $file->role() === 'thumbnail');
    }

    public function url(?string $handle = null): ?string
    {
        return $this->playedFile($handle)?->url();
    }

    public function temporaryUrl(?string $handle, DateTimeInterface $expiration): ?string
    {
        return $this->playedFile($handle)?->temporaryUrl($expiration);
    }

    /**
     * Replace its original with another file, keeping its uid: see
     * Mediatheque::replaceOriginal(). This media is updated in place.
     *
     * @param  UploadedFile|Source|string|resource  $source
     *
     * @throws FileRejected
     */
    public function replaceOriginal(mixed $source, ?IngestOptions $options = null, bool $allowTypeChange = false): MediaContract
    {
        return app(Mediatheque::class)->replaceOriginal($this, $source, $options, $allowTypeChange);
    }

    /**
     * @param  list<FileContract>  $files
     * @return Collection<int, FileContract>
     */
    private static function collect(array $files): Collection
    {
        return new Collection($files);
    }

    /**
     * The file a handle names, or the main rendition, or the original until
     * the main rendition exists.
     */
    protected function playedFile(?string $handle): ?FileContract
    {
        if ($handle !== null) {
            return $this->file($handle);
        }

        $main = app(MediathequeConfig::class)->mainRenditionOfType($this->type);

        return ($main !== null ? $this->file($main) : null) ?? $this->original();
    }
}
