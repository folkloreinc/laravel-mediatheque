<?php namespace Folklore\Mediatheque\Models;

use Folklore\Mediatheque\Contracts\Models\File as FileContract;
use Folklore\Mediatheque\Models\Collections\FilesCollection;
use Folklore\Mediatheque\Contracts\MimeGetter;
use Folklore\Mediatheque\Contracts\ExtensionGetter;
use Folklore\Mediatheque\Contracts\TypeGetter;
use Folklore\Mediatheque\Contracts\MetadataGetter;
use Folklore\Mediatheque\Support\Interfaces\HasUrl as HasUrlInterface;
use Folklore\Mediatheque\Support\Traits\HasUrl;
use Folklore\Mediatheque\Models\Observers\FileObserver;

use Illuminate\Http\File as HttpFile;
use Symfony\Component\HttpFoundation\File\UploadedFile as SymfonyUploadedFile;

class File extends Model implements FileContract, HasUrlInterface
{
    use HasUrl;

    protected $table = 'files';

    protected $fillable = [
        'handle',
        'name',
        'path',
        'source',
        'type',
        'mime',
        'size'
    ];

    protected $appends = [
        'url',
        'size_human',
        'type'
    ];

    protected $casts = [
        'handle' => 'string',
        'name' => 'string',
        'path' => 'string',
        'source' => 'string',
        'type' => 'string',
        'mime' => 'string',
        'size' => 'int',
        'metadata' => 'object'
    ];

    public static function boot()
    {
        parent::boot();

        static::observe(FileObserver::class);
    }

    public function setFile($file, $data = [])
    {
        if (is_string($file)) {
            $file = new HttpFile($file);
        }

        $path = $file->getRealPath();

        if (!isset($data['source'])) {
            $data['source'] = config('mediatheque.source');
        }

        if (!isset($data['mime'])) {
            $data['mime'] = app(MimeGetter::class)->getMime($path);
        }

        if (!isset($data['name'])) {
            $data['name'] = $file instanceof SymfonyUploadedFile ?
                $file->getClientOriginalName() : $file->getFilename();
        }

        if (!isset($data['extension'])) {
            $defaultExtension = $file instanceof SymfonyUploadedFile ?
                $file->guessClientExtension() : $file->guessExtension();
            $extension = app(ExtensionGetter::class)->getExtension($path, array_get($data, 'name'));
            $data['extension'] = !empty($extension) ? $extension : $defaultExtension;
        }

        if (!isset($data['size'])) {
            $data['size'] = $file->getSize();
        }

        if (!isset($data['type'])) {
            $data['type'] = app(TypeGetter::class)->getType($path);
        }

        if (!isset($data['metadata'])) {
            $data['metadata'] = app(MetadataGetter::class)->getMetadata($path, $data['type']);
        }

        if (!isset($data['path'])) {
            if ($this->getKey() === null) {
                $this->save();
            }
            $replaces = array_merge([
                'id' => $this->id
            ], $this->toArray(), $data);
            $format = config('mediatheque.file_path_format');
            $data['path'] = $this->formatPath($format, $replaces);
        }

        $source = $this->getSource($data['source']);
        $source->putFromLocalPath($data['path'], $path);

        $this->fill(array_only($data, $this->fillable));
        $this->save();
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
        return app('mediatheque.source')->driver($source);
    }

    protected function formatPath($format, $replaces)
    {
        $destination = ltrim($format, '/');
        foreach ($replaces as $key => $value) {
            if (preg_match_all('/\{\s*'.strtolower($key).'\s*\}/', $destination, $matches)) {
                if (sizeof($matches)) {
                    for ($i = 0; $i < sizeof($matches[0]); $i++) {
                        $destination = str_replace($matches[0][$i], $value, $destination);
                    }
                }
            }
        }
        if (preg_match_all('/\{\s*date\(([^\)]+)\)\s*\}/', $destination, $matches)) {
            if (sizeof($matches)) {
                for ($i = 0; $i < sizeof($matches[0]); $i++) {
                    $destination = str_replace($matches[0][$i], date($matches[1][$i]), $destination);
                }
            }
        }

        return $destination;
    }

    protected function getHandleAttribute()
    {
        $handle = array_get($this->attributes, 'handle');
        if ($handle === null) {
            $handle = $this->pivot && $this->pivot->handle ? $this->pivot->handle : null;
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
        return round($size / pow(1024, $i), [0,0,2,2,3][$i]).['B','kB','MB','GB','TB'][$i];
    }

    /**
     * Collections
     */
    public function newCollection(array $models = array())
    {
        return new FilesCollection($models);
    }

    /**
     * Query scopes
     */
    public function scopeSearch($query, $text)
    {
        $query->where(function ($query) use ($text) {
            $query->where('handle', 'LIKE', '%'.$text.'%');
            $query->where('name', 'LIKE', '%'.$text.'%');
            $query->where('path', 'LIKE', '%'.$text.'%');
        });

        return $query;
    }

    protected function getTypeAttribute()
    {
        return 'file';
    }
}
