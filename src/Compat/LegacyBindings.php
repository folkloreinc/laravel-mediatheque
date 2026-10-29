<?php

namespace Folklore\Mediatheque\Compat;

use Folklore\Mediatheque\Contracts\Models\File;
use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Models\File as PackageFile;
use Folklore\Mediatheque\Models\Media as PackageMedia;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;

/**
 * 1.x applications bound their own models to the contracts
 * (`app()->bind(MediaContract::class, Media::class)`); 2.0 reads them from
 * `config('mediatheque.models')`. A binding of the media or file contract
 * is honored, with a deprecation, while the config names the package's own
 * model: a model the config names wins.
 */
final class LegacyBindings
{
    /**
     * @var array<string, array{0: class-string, 1: class-string}>
     */
    private const array MODELS = [
        'media' => [Media::class, PackageMedia::class],
        'file' => [File::class, PackageFile::class],
    ];

    public static function apply(Container $container, Repository $config): void
    {
        foreach (self::MODELS as $key => [$contract, $default]) {
            if (! $container->bound($contract) || $config->get('mediatheque.models.'.$key) !== $default) {
                continue;
            }

            // A binding that is not a model of the contract is refused when
            // the config is read (MediathequeConfig::model())
            $model = $container->make($contract);
            if ($model::class === $default) {
                continue;
            }

            $config->set('mediatheque.models.'.$key, $model::class);
            Deprecation::trigger(sprintf('Binding %s in the container is deprecated: set your model in config(\'mediatheque.models.%s\').', $contract, $key));
        }
    }
}
