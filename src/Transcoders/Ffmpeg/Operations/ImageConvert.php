<?php

namespace Folklore\Mediatheque\Transcoders\Ffmpeg\Operations;

use Folklore\Mediatheque\Enums\TranscoderErrorCode;
use Folklore\Mediatheque\Pipelines\Rendition;
use Folklore\Mediatheque\Transcoders\Ffmpeg\Ffmpeg;
use Folklore\Mediatheque\Transcoders\Ffmpeg\LocalOutput;
use Folklore\Mediatheque\Transcoders\Ffmpeg\Operation;
use Folklore\Mediatheque\Transcoders\Ffmpeg\OperationContext;
use Folklore\Mediatheque\Transcoders\Ffmpeg\OperationFailed;
use Imagick;
use ImagickException;
use ImagickPixel;
use RuntimeException;

/**
 * `image.convert`: the image in a format every browser shows, with
 * Imagick: `format` `jpeg` (the default), `webp` or `png`, and `quality`
 * (85). Its first frame, upright and of the same size. Its color profile is
 * kept, and its other metadata is not, the location included. A JPEG of an
 * image with transparency is flattened over white.
 *
 * The `image` pipeline runs it for the HEIC, HEIF and AVIF images phones
 * take (docs/decisions/0009).
 */
class ImageConvert implements Operation
{
    private const array FORMATS = ['jpeg' => ['jpg', 'image/jpeg'], 'webp' => ['webp', 'image/webp'], 'png' => ['png', 'image/png']];

    public function extension(Rendition $rendition): string
    {
        return self::format($rendition->name, $rendition->options['format'] ?? null)[0];
    }

    public function run(OperationContext $context): array
    {
        if (! class_exists(Imagick::class)) {
            // The server is missing an extension: the job is retried once it is fixed
            throw new RuntimeException('The image.convert operation needs the imagick extension.');
        }

        [$extension, $mime] = self::format($context->rendition->name, $context->option('format'));
        $format = $extension === 'jpg' ? 'jpeg' : $extension;
        $quality = Ffmpeg::intOption($context, 'quality', 85);
        $output = $context->outputDirectory.'/'.$context->rendition->name.'.'.$extension;

        try {
            $image = new Imagick;
            $image->readImage($context->input);
            $image->setFirstIterator();
            $frame = $image->getImage();
            $image->clear();
        } catch (ImagickException $exception) {
            throw OperationFailed::because(TranscoderErrorCode::UnsupportedInput, 'ImageMagick can\'t read the image: '.$exception->getMessage());
        }

        $frame->autoOrient();
        $profile = $frame->getImageProfiles('icc', true)['icc'] ?? null;
        $frame->stripImage();
        if (is_string($profile)) {
            $frame->profileImage('icc', $profile);
        }

        if ($format === 'jpeg' && $frame->getImageAlphaChannel()) {
            $frame->setImageBackgroundColor(new ImagickPixel('white'));
            $frame = $frame->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
        }

        $frame->setImageFormat($format);
        $frame->setImageCompressionQuality((int) $quality);
        $frame->writeImage($output);
        $frame->clear();

        return [new LocalOutput($output, $mime)];
    }

    /**
     * The extension and mime of a format.
     *
     * @return array{string, string}
     */
    private static function format(string $rendition, mixed $format): array
    {
        return self::FORMATS[is_string($format) ? $format : 'jpeg']
            ?? throw OperationFailed::because(TranscoderErrorCode::OperationFailed, sprintf('The option "format" of the rendition "%s" must be %s.', $rendition, implode(', ', array_keys(self::FORMATS))));
    }
}
