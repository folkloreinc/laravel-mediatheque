<?php

namespace Folklore\Mediatheque\Sources;

use Folklore\Mediatheque\Contracts\Source\Source;
use League\Flysystem\Adapter\Local;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Contracts\Filesystem\Filesystem as FilesystemContract;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Symfony\Component\HttpFoundation\File\File;
use finfo;

class FilesystemSource implements Source
{
    protected $config;

    protected $filesystem;

    protected $factory;

    protected $cache;

    public function __construct(
        array $config,
        Filesystem $filesystem,
        FilesystemFactory $factory,
        CacheRepository $cache
    ) {
        $this->config = $config;
        $this->filesystem = $filesystem;
        $this->factory = $factory;
        $this->cache = $cache;
    }

    public function exists(string $path): bool
    {
        $fullPath = $this->getFullPath($path);
        return $this->existsOnDisk($fullPath);
    }

    public function putFromContents(string $path, $contents)
    {
        $disk = $this->getDisk();
        $realPath = $this->getFullPath($path);
        $options = Arr::only($this->config, ['visibility']);
        return $disk->put($realPath, $contents, $options);
    }

    public function putFromLocalPath(string $path, string $localPath)
    {
        if ($this->exists($path)) {
            $this->delete($path);
        }

        $disk = $this->getDisk();
        $realPath = $this->getFullPath($path);
        $directory = dirname($realPath);
        $filename = basename($realPath);
        $localFile = new File($localPath);
        $options = Arr::only($this->config, ['visibility']);
        return $disk->putFileAs($directory, $localFile, $filename, $options);
    }

    public function delete(string $path)
    {
        if (!$this->exists($path)) {
            return;
        }

        $disk = $this->getDisk();
        $realPath = $this->getFullPath($path);
        return $disk->delete($realPath);
    }

    public function deleteDirectory(string $path)
    {
        if (!$this->exists($path)) {
            return;
        }

        $disk = $this->getDisk();
        $realPath = $this->getFullPath($path);
        return $disk->deleteDirectory($realPath);
    }

    public function move(string $source, string $destination)
    {
        if ($this->exists($destination)) {
            $this->delete($destination);
        }

        $sourceRealPath = $this->getFullPath($source);
        $destinationRealPath = $this->getFullPath($destination);

        $disk = $this->getDisk();
        return $disk->move($sourceRealPath, $destinationRealPath);
    }

    public function copy(string $source, string $destination)
    {
        if ($this->exists($destination)) {
            $this->delete($destination);
        }

        $sourceRealPath = $this->getFullPath($source);
        $destinationRealPath = $this->getFullPath($destination);

        $disk = $this->getDisk();
        return $disk->copy($sourceRealPath, $destinationRealPath);
    }

    public function copyToLocalPath(string $path, string $localPath)
    {
        $disk = $this->getDisk();
        $realPath = $this->getFullPath($path);
        $stream = $disk->readStream($realPath);
        return $this->filesystem->put($localPath, $stream);
    }

    public function getUrl(string $path): string
    {
        $disk = $this->getDisk();
        $realPath = $this->getFullPath($path);
        return $disk->url($realPath);
    }

    public function getDisk(): FilesystemContract
    {
        $disk = $this->config['disk'];
        return $disk === 'cloud' ? $this->factory->cloud() : $this->factory->disk($disk);
    }

    protected function getFullPath(string $path): string
    {
        $prefixPath = data_get($this->config, 'path', '/');
        return rtrim($prefixPath, '/') . '/' . ltrim($path, '/');
    }

    protected function getCacheFullPath(string $path): string
    {
        $prefix = data_get($this->config, 'cache_path', null);
        $cachePath = $this->getCachePath($path);
        $extension = pathinfo($path, \PATHINFO_EXTENSION);
        return rtrim($prefix, '/') . '/' . $cachePath . (empty($extension) ? '' : '.' . $extension);
    }

    protected function getCachePath(string $path): string
    {
        $key = md5($path) . '_' . sha1($path);

        return 'image/' . preg_replace('/^([0-9a-z]{2})([0-9a-z]{2})/i', '$1/$2/', $key);
    }

    protected function getCacheKey(string $path): string
    {
        $cachePath = $this->getCachePath($path);
        return preg_replace('/[^a-zA-Z0-9]+/i', '_', $cachePath);
    }

    protected function existsOnCache(string $path): bool
    {
        $cachePath = data_get($this->config, 'cache_path', null);
        if ($cachePath) {
            return file_exists($this->getCacheFullPath($path));
        }

        $cacheKey = $this->getCacheKey($path);
        return $this->cache->has($cacheKey);
    }

    protected function existsOnDisk(string $path): bool
    {
        return $this->getDisk()->exists($path);
    }
}
