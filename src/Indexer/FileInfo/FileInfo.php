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
    private int $constraints;
    private Config $config;
    private Directory $rootDir;
    private ?MetaData $metaData;

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
        $this->rootDir = $rootDir;
        $this->constraints = $constraints;
        $this->config = $config;

        $this->metaData = $cache !== null ? MetaData::fromCache($storage, $cache) : null;
    }

    public function isCached(): bool
    {
        return $this->metaData !== null;
    }

    /**
     * @return array
     * @throws AccessDeniedException
     * @throws ConstraintsException
     * @throws Exception
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    public function getInfo(): array
    {
        $metaData = $this->getMetaData();
        return $this->formatMetaData($metaData);
    }

    /**
     * @return MetaData
     * @throws AccessDeniedException
     * @throws ConstraintsException
     * @throws Exception
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    public function getMetaData(): MetaData
    {
        if ($this->metaData !== null) {
            return $this->metaData;
        }

        return $this->refreshMetaData();
    }

    /**
     * @return MetaData
     * @throws AccessDeniedException
     * @throws ConstraintsException
     * @throws Exception
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    public function refreshMetaData(): MetaData
    {
        $metaData = MetaData::fromStorage($this->storage->setConstraints($this->constraints), $this->rootDir, $this->config);
        $metaData->saveToCache($this->cache);
        $this->metaData = $metaData;
        return $metaData;
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

    public function canHasThumbnail(): bool
    {
        if ($this->metaData !== null) {
            return $this->metaData->supportsThumbnail;
        }

        return MetaData::canHasThumbnail($this->storage);
    }

    /**
     * @return File\Image|null
     * @throws AccessDeniedException
     * @throws Exception
     * @throws UnsupportedException
     */
    public function getThumbnail(): ?File\Image
    {
        if (!$this->canHasThumbnail()) {
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

    private function formatMetaData(MetaData $metaData): array
    {
        return [
            'filename' => $metaData->name,
            'hidden' => $metaData->isHidden,
            'isDir' => $metaData->isDir,
            'type' => [
                'name' => $metaData->type,
                'mime' => $metaData->mimeType,
                'icon' => $metaData->faIcon,
            ],
            'size' => [
                'bytes' => $metaData->size,
                'hr' => (new CoreFunctions(new TemplateConfig))->formatBytes($metaData->size, 1),
            ],
            'hash' => [
                'md5' => $metaData->hashMD5,
                'sha1' => $metaData->hashSHA1,
                'sha256' => $metaData->hashSHA256,
            ],
            'time' => [
                'modified' => $metaData->timeLastModified,
                'accessed' => $metaData->timeLastAccessed,
                'created' => $metaData->timeCreated,
            ]
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
