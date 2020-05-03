<?php

namespace ricwein\Indexer\Indexer\FileInfo;

use Intervention\Image\Constraint as IConstraint;
use Intervention\Image\Image as IImage;
use ricwein\FileSystem\Directory;
use ricwein\FileSystem\Exceptions\AccessDeniedException;
use ricwein\FileSystem\Exceptions\ConstraintsException;
use ricwein\FileSystem\Exceptions\Exception;
use ricwein\FileSystem\Exceptions\FileNotFoundException;
use ricwein\FileSystem\Exceptions\RuntimeException;
use ricwein\FileSystem\Exceptions\UnexpectedValueException;
use ricwein\FileSystem\Exceptions\UnsupportedException;
use ricwein\FileSystem\File;
use ricwein\FileSystem\Storage;
use ricwein\Indexer\Config\Config;
use ricwein\Indexer\Core\Cache;
use ricwein\Templater\Config as TemplateConfig;
use ricwein\Templater\Engine\CoreFunctions;

class FileInfo
{
    public const THUMBNAIL_WIDTH = 32;
    public const THUMBNAIL_HEIGHT = 32;
    public const CACHE_DURATION = 365 * 24 * 60 * 60; // 1y

    private Storage\Disk $storage;
    private ?Cache $cache;
    private MetaData $metaData;

    /**
     * FileInfo constructor.
     * @param Storage\Disk $storage
     * @param Cache|null $cache
     * @param Config $config
     * @param Directory $rootDir
     * @param int $constraints
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    public function __construct(Storage\Disk $storage, ?Cache $cache, Config $config, Directory $rootDir, int $constraints)
    {
        $this->storage = $storage;
        $this->cache = $cache;

        $this->metaData = new MetaData($storage->setConstraints($constraints), $cache, $rootDir, $config);
    }

    public function isCached(): bool
    {
        return $this->metaData !== null;
    }

    /**
     * @return MetaData
     */
    public function meta(): MetaData
    {
        return $this->metaData;
    }

    /**
     * @param string $prefix
     * @param Storage\Disk $storage
     * @return string
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    protected static function buildCacheKey(string $prefix, Storage\Disk $storage): string
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

    public function supportsThumbnail(): bool
    {
        return $this->meta()->supportsThumbnail();
    }

    /**
     * @return File\Image|null
     * @throws AccessDeniedException
     * @throws Exception
     * @throws UnsupportedException
     */
    public function getThumbnail(): ?File\Image
    {
        if (!$this->supportsThumbnail()) {
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
