<?php

/*
 * Rector rules for the upgrade from 1.x (docs/upgrade.md). Run them on your
 * code, with rector/rector installed:
 *
 *     vendor/bin/rector process app --config vendor/folklore/laravel-mediatheque/rector.php
 *
 * What they don't change keeps working through the compatibility layer,
 * with a deprecation: handles and metadata names known only at runtime
 * (they may be 1.x ones, which the 2.0 methods don't read), `getFiles()`
 * (keyed by 1.x handle, which files() is not), `setOriginalFile()` (an
 * ingest or a replacement, depending on the media),
 * `getMetadata('font_family_name')` (`font_names` is a list).
 */

use Folklore\Mediatheque\Rector\GetFileRector;
use Folklore\Mediatheque\Rector\MetadataValueRector;
use Folklore\Mediatheque\Rector\RenameV1MethodsRector;
use Folklore\Mediatheque\Rector\RunPipelineRector;
use Rector\Config\RectorConfig;
use Rector\Renaming\Rector\Name\RenameClassRector;

return RectorConfig::configure()
    ->withRules([
        GetFileRector::class,
        MetadataValueRector::class,
        RunPipelineRector::class,
        RenameV1MethodsRector::class,
    ])
    ->withConfiguredRule(RenameClassRector::class, [
        'Folklore\\Mediatheque\\Support\\Traits\\HasMedias' => 'Folklore\\Mediatheque\\Concerns\\HasMedias',
    ]);
