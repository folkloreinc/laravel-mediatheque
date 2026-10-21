<?php

namespace Folklore\Mediatheque\Models;

use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Enums\RejectionReason;
use Folklore\Mediatheque\Enums\UploadStatus;
use Folklore\Mediatheque\Support\Models;
use Folklore\Mediatheque\Support\Uid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;

/**
 * A presigned multipart upload, stored in the `uploads` table
 * (docs/decisions/0015). It belongs to the user who created it.
 *
 * An upload that ended says when, and why it was aborted: `aborted` by its
 * creator, `expired`, or the RejectionReason of the file ingest refused
 * (docs/decisions/0017).
 *
 * @property int $id
 * @property string $uid
 * @property UploadStatus $status
 * @property string|null $reason
 * @property string $disk
 * @property string $path
 * @property string|null $provider_upload_id
 * @property string|null $filename
 * @property int|null $size
 * @property string|null $creator_type
 * @property int|string|null $creator_id
 * @property int|null $media_id
 * @property Carbon $expires_at
 * @property Carbon|null $ended_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Upload extends Model
{
    public const string UID_PREFIX = 'upl';

    /** Aborted by its creator. */
    public const string ABORTED = 'aborted';

    /** Never completed before it expired. */
    public const string EXPIRED = 'expired';

    /**
     * @var bool
     */
    public $usesUniqueIds = true;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uid'];
    }

    public function newUniqueId(): string
    {
        return Uid::generate(self::UID_PREFIX);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => UploadStatus::class,
            'size' => 'integer',
            'expires_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function getTable(): string
    {
        return $this->table ?? Models::table('uploads');
    }

    public function getRouteKeyName(): string
    {
        return 'uid';
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function creator(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<Model&Media, $this>
     */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Models::media(), 'media_id');
    }

    /**
     * Completed and ingested, as this media.
     */
    public function markCompleted(int|string|null $mediaId): void
    {
        $this->forceFill(['status' => UploadStatus::Completed, 'media_id' => $mediaId, 'ended_at' => Date::now()])->save();
    }

    /**
     * Aborted: by its creator (`aborted`), because it expired (`expired`),
     * or because ingest rejected its file (the RejectionReason).
     */
    public function markAborted(RejectionReason|string $reason): void
    {
        $this->forceFill([
            'status' => UploadStatus::Aborted,
            'reason' => $reason instanceof RejectionReason ? $reason->value : $reason,
            'ended_at' => Date::now(),
        ])->save();
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isBefore(Date::now());
    }
}
