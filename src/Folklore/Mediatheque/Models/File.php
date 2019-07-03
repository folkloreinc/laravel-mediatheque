<?php

namespace Folklore\Mediatheque\Models;

use Folklore\Mediatheque\Contracts\Models\File as FileContract;
use Folklore\Mediatheque\Contracts\Type\Factory as TypeFactory;
use Folklore\Mediatheque\Contracts\Source\Factory as SourceFactory;
use Folklore\Mediatheque\Contracts\Services\Mime as MimeService;
use Folklore\Mediatheque\Contracts\Services\Extension as ExtensionService;
use Folklore\Mediatheque\Contracts\Services\Metadata as MetadataService;
use Folklore\Mediatheque\Contracts\Services\PathFormatter as PathFormatterService;
use Folklore\Mediatheque\Contracts\Support\HasMetadatas as HasMetadatasInterface;
use Folklore\Mediatheque\Contracts\Support\HasUrl as HasUrlInterface;
use Folklore\Mediatheque\Support\Traits\HasUrl;
use Folklore\Mediatheque\Support\Traits\HasMetadatas;
use Folklore\Mediatheque\Models\Collections\FilesCollection;

use Symfony\Component\HttpFoundation\File\File as HttpFile;
use Symfony\Component\HttpFoundation\File\UploadedFile as SymfonyUploadedFile;

class File extends Model implements
    FileContract,
    HasUrlInterface,
    HasMetadatasInterface
{
    use HasUrl, HasMetadatas;

    protected $table = 'files';

    protected $fillable = ['handle', 'name', 'path', 'source', 'mime', 'size'];

    protected $appends = ['url', 'size_human', 'type'];

    protected $casts = [
        'handle' => 'string',
        'name' => 'string',
        'path' => 'string',
        'source' => 'string',
        'mime' => 'string',
        'size' => 'int'
    ];

    public static function boot()
    {
        parent::boot();

        $observer = config('mediatheque.observers.file', null);
        if (!is_null($observer)) {
            static::observe($observer);
        }
    }

    public function setFile($file, $data = [])
    {
        if (is_string($file)) {
            $file = new HttpFile($file);
        }

        $path = $file->getRealPath();
        $name =
            $file instanceof SymfonyUploadedFile
                ? $file->getClientOriginalName()
                : $file->getFilename();
        $extension =
            $file instanceof SymfonyUploadedFile
                ? $file->guessClientExtension()
                : $file->guessExtension();

        if (!isset($data['name'])) {
            $data['name'] = $name;
        }

        if (!isset($data['type'])) {
            $data['type'] = app(TypeFactory::class)->typeFromPath($path);
        }

        if (!isset($data['mime'])) {
            $data['mime'] = app(MimeService::class)->getMime($path);
        }

        if (!isset($data['extension'])) {
            $defaultExtension = $extension;
            $extension = app(ExtensionService::class)->getExtension(
                $path,
                $data['name']
            );
            $data['extension'] = !empty($extension)
                ? $extension
                : $defaultExtension;
        }

        if (!isset($data['size'])) {
            $data['size'] = $file->getSize();
        }

        if (!isset($data['metadata'])) {
            $data['metadata'] = app(MetadataService::class)->getMetadata(
                $path
            );
        }

        if (!isset($data['path'])) {
            if (!$this->exists) {
                $this->save();
            }
            $data['path'] = app(PathFormatterService::class)->formatPath(
                config('mediatheque.file_path_format'),
                $this,
                $data
            );
        }

        $source = $this->getSource(array_get($data, 'source'));
        $source->putFromLocalPath($data['path'], $path);

        $this->fill(array_only($data, $this->fillable))
            ->setMetadata(array_get($data, 'metadata', []))
            ->save();

        return $this;
    }

    public function deleteFile()
    {
        $source = $this->getSource();
        return $source->delete($this->path);
    }

    public function moveFile($newPath)
    {
        $source = $this->getSource();
        return $source->move($this->path, $newPath);
    }

    public function copyFile($destinationPath)
    {
        $source = $this->getSource();
        return $source->copy($this->path, $destinationPath);
    }

    public function downloadFile($localPath)
    {
        $source = $this->getSource();
        return $source->copyToLocalPath($this->path, $localPath);
    }

    public function getSource($source = null)
    {
        if (!$source) {
            $source = $this->source;
        }
        return app(SourceFactory::class)->source($source);
    }

    protected function getHandleAttribute()
    {
        $handle = array_get($this->attributes, 'handle');
        if ($handle === null) {
            $handle =
                $this->pivot && $this->pivot->handle
                    ? $this->pivot->handle
                    : null;
        }
        return $handle;
    }

    protected function getSizeHumanAttribute()
    {
        $size = array_get($this->attributes, 'size');
        if (empty($size)) {
            return null;
        }
        $i = floor(log($size, 1024));
        return round($size / pow(1024, $i), [0, 0, 2, 2, 3][$i]) .
            ['B', 'kB', 'MB', 'GB', 'TB'][$i];
    }

    /**
     * Collections
     */
    public function newCollection(array $models = [])
    {
        return new FilesCollection($models);
    }

    /**
     * Query scopes
     */
    public function scopeSearch($query, $text)
    {
        $query->where(function ($query) use ($text) {
            $query->where('handle', 'LIKE', '%' . $text . '%');
            $query->where('name', 'LIKE', '%' . $text . '%');
            $query->where('path', 'LIKE', '%' . $text . '%');
        });

        return $query;
    }

    protected function getTypeAttribute()
    {
        return 'file';
    }
}
