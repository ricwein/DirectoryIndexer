<?php

namespace ricwein\Indexer\Indexer\FileInfo;

use Intervention\Image\Constraint as IConstraint;
use Intervention\Image\Image as IImage;
use ricwein\FileSystem\Directory;
use ricwein\FileSystem\Enum\Hash;
use ricwein\FileSystem\Exceptions\AccessDeniedException;
use ricwein\FileSystem\Exceptions\ConstraintsException;
use ricwein\FileSystem\Exceptions\Exception;
use ricwein\FileSystem\Exceptions\FileNotFoundException;
use ricwein\FileSystem\Exceptions\RuntimeException;
use ricwein\FileSystem\Exceptions\UnexpectedValueException;
use ricwein\FileSystem\Exceptions\UnsupportedException;
use ricwein\FileSystem\File;
use ricwein\FileSystem\Storage;
use ricwein\Indexer\Core\Cache;
use ricwein\Templater\Config;
use ricwein\Templater\Engine\CoreFunctions;

class FileInfo
{
    private const THUMBNAIL_WIDTH = 32;
    private const THUMBNAIL_HEIGHT = 32;
    private const CACHE_DURATION = 365 * 24 * 60 * 60; // 1y

    private Storage $storage;
    private ?Cache $cache;
    private int $constraints;
    private ?FileType $type = null;

    public function __construct(Storage $storage, ?Cache $cache, int $constraints)
    {
        $this->storage = $storage;
        $this->cache = $cache;
        $this->constraints = $constraints;
    }

    public function isCached(): bool
    {
        $cacheKey = static::buildCacheKey('infoOf', $this->storage);
        $cacheItem = $this->cache->getItem($cacheKey);
        return $cacheItem->isHit();
    }

    /**
     * @return array
     * @throws AccessDeniedException
     * @throws ConstraintsException
     * @throws Exception
     * @throws FileNotFoundException
     * @throws RuntimeException
     * @throws UnexpectedValueException
     * @throws UnsupportedException
     */
    public function getInfo(): array
    {
        if ($this->cache === null) {
            return $this->fetchInfo();
        }

        $cacheKey = static::buildCacheKey('infoOf', $this->storage);
        $cacheItem = $this->cache->getItem($cacheKey);

        if ($cacheItem->isHit()) {
            return $cacheItem->get();
        }

        $info = $this->fetchInfo();
        $cacheItem->set($info);
        $cacheItem->expiresAfter(static::CACHE_DURATION);
        $this->cache->save($cacheItem);

        return $info;
    }

    /**
     * @param string $prefix
     * @param Storage $storage
     * @return string
     */
    protected static function buildCacheKey(string $prefix, Storage $storage): string
    {
        return str_replace(
            ['{', '}', '(', ')', '/', '\\', '@', ':'],
            ['|', '|', '|', '|', '.', '.', '-', '_'],
            sprintf('%s_%s|%d',
                rtrim($prefix, '_'),
                $storage->path()->real,
                $storage->getTime()
            )
        );
    }

    public function hasThumbnail(): bool
    {
        if (!$this->storage instanceof Storage\Disk) {
            return false;
        }

        $extension = strtolower($this->storage->path()->extension);

        return in_array($extension, [
            'png', 'gif', 'bmp', 'jpg', 'jpeg'
        ], true);
    }

    /**
     * @return FileType
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    public function type(): FileType
    {
        if ($this->type !== null) {
            return $this->type;
        }

        if ($this->cache === null) {
            $type = FileType::fromStorage($this->storage);
            $this->type = $type;
            return $type;
        }

        $cacheKey = static::buildCacheKey('typeOf', $this->storage);
        $cacheItem = $this->cache->getItem($cacheKey);

        if ($cacheItem->isHit()) {
            return $cacheItem->get();
        }

        $type = FileType::fromStorage($this->storage);

        $cacheItem->set($type);
        $cacheItem->expiresAfter(static::CACHE_DURATION);
        $this->cache->save($cacheItem);
        $this->type = $type;

        return $type;
    }


    /**
     * @return File\Image|null
     * @throws AccessDeniedException
     * @throws Exception
     * @throws UnsupportedException
     */
    public function getPreview(): ?File\Image
    {
        if (!$this->hasThumbnail()) {
            return null;
        }

        if ($this->cache === null) {
            return $this->buildThumbnail();
        }

        $cacheKey = sprintf('%s|%dx%d',
            static::buildCacheKey('thumbnailOf', $this->storage),
            static::THUMBNAIL_WIDTH,
            static::THUMBNAIL_HEIGHT
        );
        $cacheItem = $this->cache->getItem($cacheKey);

        if ($cacheItem->isHit()) {
            return new File\Image(new Storage\Memory($cacheItem->get()));
        }

        $thumbnail = $this->buildThumbnail();
        $cacheItem->set($thumbnail->read());
        $cacheItem->expiresAfter(static::CACHE_DURATION);
        $this->cache->save($cacheItem);

        return $thumbnail;
    }

    /**
     * @return array
     * @throws AccessDeniedException
     * @throws ConstraintsException
     * @throws Exception
     * @throws FileNotFoundException
     * @throws RuntimeException
     * @throws UnexpectedValueException
     * @throws UnsupportedException
     */
    private function fetchInfo(): array
    {
        if ($this->storage->isDir()) {
            $dir = new Directory($this->storage, $this->constraints);
            $size = $dir->getSize();

            return [
                'filename' => $dir->path()->basename,
                'type' => $this->type()->asArray(),
                'isDir' => true,
                'size' => [
                    'bytes' => $size,
                    'hr' => (new CoreFunctions(new Config))->formatBytes($size, 1),
                ],
            ];
        }

        $file = new File($this->storage, $this->constraints);
        $size = $file->getSize();

        return [
            'filename' => $file->path()->filename,
            'type' => $this->type()->asArray(),
            'isDir' => false,
            'size' => [
                'bytes' => $size,
                'hr' => (new CoreFunctions(new Config))->formatBytes($size, 1),
            ],
            'hash' => [
                'md5' => $file->getHash(Hash::CONTENT, 'md5'),
                'sha1' => $file->getHash(Hash::CONTENT, 'sha1'),
                'sha256' => $file->getHash(Hash::CONTENT, 'sha256'),
            ],
        ];
    }

    /**
     * @return File\Image
     * @throws AccessDeniedException
     * @throws ConstraintsException
     * @throws Exception
     * @throws FileNotFoundException
     * @throws RuntimeException
     * @throws UnexpectedValueException
     * @throws UnsupportedException
     */
    private function buildThumbnail(): File\Image
    {
        $file = new File\Image(new Storage\Memory($this->storage->readFile()));
        $file->edit(static function (IImage $image): IImage {
            return $image->fit(static::THUMBNAIL_WIDTH, static::THUMBNAIL_HEIGHT, static function (IConstraint $constraint) {
                $constraint->upsize();
            });
        });
        return $file;
    }
}
