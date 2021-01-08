<?php

namespace Folklore\Mediatheque\Models;

use Illuminate\Support\Arr;
use Folklore\Mediatheque\Contracts\Models\File as FileContract;
use Folklore\Mediatheque\Contracts\Type\Factory as TypeFactory;
use Folklore\Mediatheque\Contracts\Source\Factory as SourceFactory;
use Folklore\Mediatheque\Contracts\Source\Source as SourceContract;
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

class File extends Model implements FileContract, HasUrlInterface, HasMetadatasInterface
{
    use HasUrl, HasMetadatas;

    protected $table = 'files';

    protected $fillable = ['handle', 'name', 'path', 'source', 'mime', 'size'];

    protected $appends = ['url', 'size_human'];

    protected $casts = [
        'handle' => 'string',
        'name' => 'string',
        'path' => 'string',
        'source' => 'string',
        'mime' => 'string',
        'size' => 'int',
    ];

    public static function boot()
    {
        parent::boot();

        $observer = config('mediatheque.observers.file', null);
        if (!is_null($observer)) {
            static::observe($observer);
        }
    }

    public function getHandle(): string
    {
        $handle = data_get($this->attributes, 'handle');
        if ($handle === null) {
            $handle = $this->pivot && $this->pivot->handle ? $this->pivot->handle : null;
        }
        return $handle;
    }

    public function setFile($file, array $data = []): void
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
            $extension = app(ExtensionService::class)->getExtension($path, $data['name']);
            $data['extension'] = !empty($extension) ? $extension : $defaultExtension;
        }

        if (!isset($data['size'])) {
            $data['size'] = $file->getSize();
        }

        if (!isset($data['metadata'])) {
            $data['metadata'] = app(MetadataService::class)->getMetadata($path);
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

        $source = $this->getSource(data_get($data, 'source'));
        $source->putFromLocalPath($data['path'], $path);

        $this->fill(Arr::only($data, $this->fillable))
            ->setMetadatas(data_get($data, 'metadata', []))
            ->save();
    }

    public function deleteFile(): void
    {
        $source = $this->getSource();
        $source->delete($this->path);
    }

    public function moveFile(string $newPath): void
    {
        $source = $this->getSource();
        $source->move($this->path, $newPath);
    }

    public function copyFile(string $destinationPath): void
    {
        $source = $this->getSource();
        $source->copy($this->path, $destinationPath);
    }

    public function downloadFile(string $localPath): void
    {
        $source = $this->getSource();
        $source->copyToLocalPath($this->path, $localPath);
    }

    public function getSource(): SourceContract
    {
        return app(SourceFactory::class)->source($this->source);
    }

    /**
     * Accessors
     */
    protected function getSizeHumanAttribute()
    {
        $size = data_get($this->attributes, 'size');
        if (empty($size)) {
            return null;
        }
        $i = floor(log($size, 1024));
        return round($size / pow(1024, $i), [0, 0, 2, 2, 3][$i]) .
            ['B', 'kB', 'MB', 'GB', 'TB'][$i];
    }

    protected function getUrlAttribute()
    {
        return $this->getUrl();
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
}
