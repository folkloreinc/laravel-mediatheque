<?php

namespace Folklore\Mediatheque\Sources;

use Folklore\Mediatheque\Contracts\Source\Source;
use Illuminate\Filesystem\Filesystem;

class LocalSource implements Source
{
    protected $filesystem;

    public function __construct(array $config, Filesystem $filesystem)
    {
        $this->config = $config;
        $this->filesystem = $filesystem;
    }

    public function getFullPath($path)
    {
        $dir = isset($this->config['path']) ? $this->config['path'] : '';
        return rtrim($dir, '/').'/'.ltrim($path, '/');
    }

    public function exists($path)
    {
        $realPath = $this->getFullPath($path);
        return $this->filesystem->exists($realPath);
    }

    protected function ensureDirectory($path, $mode = 0775)
    {
        $dirname = dirname($path);
        if ($this->filesystem->isDirectory($dirname)) {
            return true;
        }
        return $this->filesystem->makeDirectory($dirname, $mode, true);
    }

    public function putFromContents($path, $contents)
    {
        if ($this->exists($path)) {
            $this->delete($path);
        }

        $realPath = $this->getFullPath($path);
        $this->ensureDirectory($realPath);
        return $this->filesystem->put($realPath, $contents);
    }

    public function putFromLocalPath($path, $localPath)
    {
        if ($this->exists($path)) {
            $this->delete($path);
        }

        $realPath = $this->getFullPath($path);
        $this->ensureDirectory($realPath);
        return $this->filesystem->copy($localPath, $realPath);
    }

    public function delete($path)
    {
        if (!$this->exists($path)) {
            return;
        }

        $realPath = $this->getFullPath($path);
        return $this->filesystem->delete($realPath);
    }

    public function move($source, $destination)
    {
        if ($this->exists($destination)) {
            $this->delete($destination);
        }

        $sourceRealPath = $this->getFullPath($source);
        $destinationRealPath = $this->getFullPath($destination);

        return $this->filesystem->move($sourceRealPath, $destinationRealPath);
    }

    public function copy($source, $destination)
    {
        if ($this->exists($destination)) {
            $this->delete($destination);
        }

        $sourceRealPath = $this->getFullPath($source);
        $destinationRealPath = $this->getFullPath($destination);

        return $this->filesystem->copy($sourceRealPath, $destinationRealPath);
    }

    public function copyToLocalPath($path, $localPath)
    {
        $realPath = $this->getFullPath($path);
        return $this->filesystem->copy($realPath, $localPath);
    }

    public function getUrl($path)
    {
        $publicPath = data_get($this->config, 'url', '/');
        return rtrim($publicPath, '/').'/'.ltrim($path, '/');
    }
}
