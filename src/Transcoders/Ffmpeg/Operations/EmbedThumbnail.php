<?php

namespace Folklore\Mediatheque\Transcoders\Ffmpeg\Operations;

use finfo;
use Folklore\Mediatheque\Embeds\EmbedProviders;
use Folklore\Mediatheque\Enums\TranscoderErrorCode;
use Folklore\Mediatheque\Metadata\MetadataKey;
use Folklore\Mediatheque\Pipelines\Rendition;
use Folklore\Mediatheque\Support\MediathequeConfig;
use Folklore\Mediatheque\Transcoders\Ffmpeg\LocalOutput;
use Folklore\Mediatheque\Transcoders\Ffmpeg\Operation;
use Folklore\Mediatheque\Transcoders\Ffmpeg\OperationContext;
use Folklore\Mediatheque\Transcoders\Ffmpeg\OperationFailed;
use Folklore\Mediatheque\Transcoders\Ffmpeg\ReadsNoFile;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Imagick;
use ImagickException;
use RuntimeException;

/**
 * `embed.thumbnail`: an embed's thumbnail, as its provider gives it
 * (`thumbnail_url`, from the probe), for the renditions that read from it:
 * an `image.convert` with `from` makes its poster (docs/decisions/0031).
 *
 * - It downloads only from the provider's thumbnail domains, over https,
 *   without following redirects, up to 20 MB, within `embeds.timeout`.
 * - The file is typed by its content: a JPEG is kept as it is; a PNG, WebP
 *   or GIF is converted to JPEG with Imagick; anything else fails.
 * - An embed without a thumbnail fails with `provider_unavailable`, as
 *   Eclair's operation does.
 */
class EmbedThumbnail implements Operation, ReadsNoFile
{
    /** The largest thumbnail, in bytes. */
    public const int MAX_SIZE = 20 * 1024 * 1024;

    private const array CONVERTED = ['image/png', 'image/webp', 'image/gif'];

    public function __construct(
        private readonly Http $http,
        private readonly MediathequeConfig $config,
    ) {}

    public function extension(Rendition $rendition): string
    {
        return 'jpg';
    }

    public function run(OperationContext $context): array
    {
        $provider = $context->metadata[MetadataKey::Provider->value] ?? null;
        $url = $context->metadata[MetadataKey::ThumbnailUrl->value] ?? null;
        if (! is_string($provider) || ! is_string($url) || $url === '') {
            throw OperationFailed::because(TranscoderErrorCode::ProviderUnavailable, 'The embed\'s provider gives no thumbnail.');
        }
        if (! EmbedProviders::isThumbnail($provider, $url)) {
            throw OperationFailed::because(TranscoderErrorCode::UnsupportedInput, sprintf('The thumbnail is not on a domain of %s\'s.', $provider));
        }

        $download = $context->outputDirectory.'/download';
        $this->download($url, $download);

        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($download);
        $output = $context->outputDirectory.'/'.$context->rendition->name.'.jpg';
        if ($mime === 'image/jpeg') {
            rename($download, $output);
        } elseif (in_array($mime, self::CONVERTED, true)) {
            $this->toJpeg($download, $output);
        } else {
            throw OperationFailed::because(TranscoderErrorCode::ProviderFailed, sprintf('The thumbnail is not an image (%s).', $mime));
        }
        ($context->progress)(1.0);

        return [new LocalOutput($output, 'image/jpeg')];
    }

    private function download(string $url, string $path): void
    {
        $tooLarge = false;
        try {
            $response = $this->http
                ->timeout($this->config->embedTimeout())
                ->withOptions([
                    'allow_redirects' => false,
                    'sink' => $path,
                    // Stops a download that announces or reaches more than the limit
                    'progress' => function (int|float $total, int|float $downloaded) use (&$tooLarge) {
                        if ($total > self::MAX_SIZE || $downloaded > self::MAX_SIZE) {
                            $tooLarge = true;

                            throw new RuntimeException('The thumbnail is too large.');
                        }
                    },
                ])
                ->get($url);
        } catch (ConnectionException|TransferException|RuntimeException $exception) {
            throw $tooLarge
                ? OperationFailed::because(TranscoderErrorCode::LimitExceeded, 'The thumbnail is larger than '.self::MAX_SIZE.' bytes.')
                : OperationFailed::because(TranscoderErrorCode::ProviderFailed, 'The thumbnail can\'t be downloaded: '.$exception->getMessage());
        }

        if (! $response->successful()) {
            throw OperationFailed::because(
                $response->status() === 404 || $response->status() === 410 ? TranscoderErrorCode::ProviderUnavailable : TranscoderErrorCode::ProviderFailed,
                sprintf('The thumbnail answered %d.', $response->status()),
            );
        }
        if (! is_file($path) || filesize($path) > self::MAX_SIZE) {
            throw OperationFailed::because(TranscoderErrorCode::LimitExceeded, 'The thumbnail is larger than '.self::MAX_SIZE.' bytes.');
        }
    }

    private function toJpeg(string $input, string $output): void
    {
        if (! class_exists(Imagick::class)) {
            // The server is missing an extension: the job is retried once it is fixed
            throw new RuntimeException('The embed.thumbnail operation needs the imagick extension for a thumbnail that isn\'t a JPEG.');
        }

        try {
            $image = new Imagick;
            $image->readImage($input);
            $image->setFirstIterator();
            $frame = $image->getImage();
            $image->clear();
            if ($frame->getImageAlphaChannel()) {
                $frame->setImageBackgroundColor('white');
                $frame = $frame->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
            }
            $frame->setImageFormat('jpeg');
            $frame->setImageCompressionQuality(90);
            $frame->writeImage($output);
            $frame->clear();
        } catch (ImagickException $exception) {
            throw OperationFailed::because(TranscoderErrorCode::ProviderFailed, 'ImageMagick can\'t read the thumbnail: '.$exception->getMessage());
        }
    }
}
