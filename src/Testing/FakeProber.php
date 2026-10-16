<?php

namespace Folklore\Mediatheque\Testing;

use Closure;
use Folklore\Mediatheque\Contracts\Models\File;
use Folklore\Mediatheque\Contracts\Prober;
use Folklore\Mediatheque\Enums\ProbeKind;
use Folklore\Mediatheque\Probe\ProbeOutcome;
use Folklore\Mediatheque\Transcoders\TranscoderError;
use Illuminate\Support\Str;

/**
 * Returns probe fixtures: plausible metadata for the file's kind, or what
 * the test set with Mediatheque::fake()->probeWith().
 */
class FakeProber implements Prober
{
    /** @var (Closure(File): array<string, mixed>)|array<string, mixed>|null */
    public Closure|array|null $metadata = null;

    public ?TranscoderError $failure = null;

    public ?string $pendingId = null;

    public function probe(File $file): ProbeOutcome
    {
        if ($this->failure !== null) {
            return ProbeOutcome::failed($this->failure);
        }

        if ($this->pendingId !== null) {
            return ProbeOutcome::pending($this->pendingId);
        }

        $metadata = $this->metadata instanceof Closure ? ($this->metadata)($file) : ($this->metadata ?? []);

        return ProbeOutcome::done([...self::fixture($file), ...$metadata]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function fixture(File $file): array
    {
        $mime = $file->mime() ?? 'application/octet-stream';
        $kind = self::kind($mime);

        return [
            'kind' => $kind->value,
            'mime' => $mime,
            ...match ($kind) {
                ProbeKind::Video => ['width' => 1920, 'height' => 1080, 'rotation' => 0, 'duration' => 10.0, 'frame_rate' => 25.0, 'bitrate' => 4_000_000, 'video_codec' => 'h264', 'audio_codec' => 'aac', 'audio_channels' => 2],
                ProbeKind::Audio => ['duration' => 10.0, 'bitrate' => 128_000, 'audio_codec' => 'mp3', 'audio_channels' => 2],
                ProbeKind::Image => ['width' => 1200, 'height' => 800, 'rotation' => 0],
                ProbeKind::Document => ['pages_count' => 1],
                ProbeKind::Font => ['font_names' => ['Fake Sans']],
                ProbeKind::Other => [],
            },
        ];
    }

    public static function kind(string $mime): ProbeKind
    {
        return match (true) {
            Str::startsWith($mime, 'video/') => ProbeKind::Video,
            Str::startsWith($mime, 'audio/') => ProbeKind::Audio,
            Str::startsWith($mime, 'image/') => ProbeKind::Image,
            $mime === 'application/pdf' => ProbeKind::Document,
            Str::startsWith($mime, ['font/', 'application/font-', 'application/x-font-']) || $mime === 'application/vnd.ms-opentype' => ProbeKind::Font,
            default => ProbeKind::Other,
        };
    }
}
