<?php

namespace ricwein\Indexer\Indexer;

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
    private Storage $storage;
    private int $constraints;
    private ?Cache $cache;

    public function __construct(Storage $storage, int $constraints, ?Cache $cache)
    {
        $this->storage = $storage;
        $this->cache = $cache;
        $this->constraints = $constraints;
    }

    /**
     * @param Storage $storage
     * @return string
     */
    private static function buildCacheKey(Storage $storage): string
    {
        return str_replace(
            ['{', '}', '(', ')', '/', '\\', '@', ':'],
            ['|', '|', '|', '|', '.', '.', '-', '_'],
            sprintf('fileInfoOf_%s|%d',
                $storage->path()->real,
                $storage->getTime()
            )
        );
    }

    public function isCached(): bool
    {
        $cacheKey = static::buildCacheKey($this->storage);
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

        $cacheKey = static::buildCacheKey($this->storage);
        $cacheItem = $this->cache->getItem($cacheKey);

        if ($cacheItem->isHit()) {
            return $cacheItem->get();
        }

        $info = $this->fetchInfo();
        $cacheItem->set($info);
        $cacheItem->expiresAfter(365 * 24 * 60 * 60);
        $this->cache->save($cacheItem);

        return $info;
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
     * @throws \ricwein\Templater\Exceptions\UnexpectedValueException
     */
    private function fetchInfo(): array
    {
        if ($this->storage->isDir()) {
            $dir = new Directory($this->storage, $this->constraints);
            $size = $dir->getSize();

            return [
                'type' => 'dir',
                'filename' => $dir->path()->basename,
                'size' => [
                    'bytes' => $size,
                    'hr' => (new CoreFunctions(new Config([])))->formatBytes($size, 1),
                ],
            ];
        }

        $file = new File($this->storage, $this->constraints);
        $size = $file->getSize();

        return [
            'type' => 'file',
            'filename' => $file->path()->filename,
            'size' => [
                'bytes' => $size,
                'hr' => (new CoreFunctions(new Config([])))->formatBytes($size, 1),
            ],
            'hash' => [
                'md5' => $file->getHash(Hash::CONTENT, 'md5'),
                'sha1' => $file->getHash(Hash::CONTENT, 'sha1'),
                'sha256' => $file->getHash(Hash::CONTENT, 'sha256'),
            ],
        ];
    }
}
