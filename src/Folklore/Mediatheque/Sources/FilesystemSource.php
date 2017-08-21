<?php

namespace Folklore\Mediatheque\Sources;

use Folklore\Mediatheque\Contracts\Source;
use League\Flysystem\Adapter\Local;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\File;
use finfo;

class FilesystemSource implements Source
{
    protected $filesystem;

    public function __construct(array $config, Filesystem $filesystem)
    {
        $this->config = $config;
        $this->filesystem = $filesystem;
    }

    public function exists($path)
    {
        $fullPath = $this->getFullPath($path);
        return $this->existsOnDisk($fullPath);
    }

    public function putFromContents($path, $contents)
    {
        $disk = $this->getDisk();
        $realPath = $this->getFullPath($path);
        return $disk->put($realPath, $contents);
    }

    public function putFromLocalPath($path, $localPath)
    {
        if ($this->exists($path)) {
            $this->delete($path);
        }

        $disk = $this->getDisk();
        $realPath = $this->getFullPath($path);
        $directory = dirname($realPath);
        $filename = basename($realPath);
        $localFile = new File($localPath);
        return $disk->putFileAs($directory, $localFile, $filename);
    }

    public function delete($path)
    {
        if (!$this->exists($path)) {
            return;
        }

        $realPath = $this->getFullPath($path);
        return $disk->delete($realPath);
    }

    public function move($source, $destination)
    {
        if ($this->exists($destination)) {
            $this->delete($destination);
        }

        $sourceRealPath = $this->getFullPath($source);
        $destinationRealPath = $this->getFullPath($destination);

        $disk = $this->getDisk();
        return $disk->move($sourceRealPath, $destinationRealPath);
    }

    public function copy($source, $destination)
    {
        if ($this->exists($destination)) {
            $this->delete($destination);
        }

        $sourceRealPath = $this->getFullPath($source);
        $destinationRealPath = $this->getFullPath($destination);

        $disk = $this->getDisk();
        return $disk->copy($sourceRealPath, $destinationRealPath);
    }

    public function copyToLocalPath($path, $localPath)
    {
        $disk = $this->getDisk();
        $realPath = $this->getFullPath($path);
        $contents = $disk->get($realPath);
        return $this->filesystem->put($localPath, $contents);
    }

    public function getUrl($path)
    {
        $disk = $this->getDisk();
        $realPath = $this->getFullPath($path);
        return $disk->url($realPath);
    }

    public function getDisk()
    {
        $disk = $this->config['disk'];
        return $disk === 'cloud' ? app('filesystem')->cloud():app('filesystem')->disk($disk);
    }

    protected function getFullPath($path)
    {
        $prefixPath = array_get($this->config, 'path', '/');
        return rtrim($prefixPath, '/').'/'.ltrim($path, '/');
    }

    protected function getCacheFullPath($path)
    {
        $prefix = array_get($this->config, 'cache_path', null);
        $cachePath = $this->getCachePath($path);
        $extension = pathinfo($path, \PATHINFO_EXTENSION);
        return rtrim($prefix, '/').'/'.$cachePath.(empty($extension) ? '':('.'.$extension));
    }

    protected function getCachePath($path)
    {
        $key = md5($path).'_'.sha1($path);

        return 'image/'.preg_replace('/^([0-9a-z]{2})([0-9a-z]{2})/i', '$1/$2/', $key);
    }

    protected function getCacheKey($path)
    {
        $cachePath = $this->getCachePath($path);
        return preg_replace('/[^a-zA-Z0-9]+/i', '_', $cachePath);
    }

    protected function existsOnCache($path)
    {
        $cachePath = array_get($this->config, 'cache_path', null);
        if ($cachePath) {
            return file_exists($this->getCacheFullPath($path));
        }

        $cacheKey = $this->getCacheKey($path);
        return app('cache')->has($cacheKey);
    }

    protected function existsOnDisk($path)
    {
        $disk = $this->getDisk();
        return $disk->exists($path);
    }
}
