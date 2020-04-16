<?php

namespace ricwein\Indexer\Indexer;

use ricwein\FileSystem\Directory;
use ricwein\FileSystem\Exceptions\AccessDeniedException;
use ricwein\FileSystem\Exceptions\Exception;
use ricwein\FileSystem\Exceptions\RuntimeException;
use ricwein\FileSystem\Exceptions\UnexpectedValueException;
use ricwein\FileSystem\Exceptions\UnsupportedException;
use ricwein\FileSystem\Storage;
use ricwein\Indexer\Config\Config;
use ricwein\Indexer\Core\Cache;

class Index
{
    private Directory $rootDir;
    private ?Cache $cache;
    private PathIgnore $pathIgnore;

    /** @var string[]|null */
    private ?array $fileList = null;

    public function __construct(Directory $rootDir, Config $config, ?Cache $cache)
    {
        $this->rootDir = $rootDir;
        $this->cache = $cache;

        $this->pathIgnore = new PathIgnore($this->rootDir, $config);
    }

    /**
     * @param callable|null $progressCallback
     * @return string[]
     * @throws AccessDeniedException
     * @throws Exception
     * @throws RuntimeException
     * @throws UnexpectedValueException
     * @throws UnsupportedException
     */
    public function list(?callable $progressCallback = null): array
    {
        if ($progressCallback !== null) {
            $progressCallback(null);
        }

        if ($this->fileList !== null) {
            return $this->fileList;
        }

        if ($this->cache === null) {
            $filesList = $this->indexDirectory($progressCallback);
            $this->fileList = $filesList;
            return $filesList;
        }

        $cacheKey = str_replace(
            ['{', '}', '(', ')', '/', '\\', '@', ':'],
            ['|', '|', '|', '|', '.', '.', '-', '_'],
            sprintf('indexOf_%s|%d',
                $this->rootDir->path()->real,
                $this->rootDir->getTime()
            )
        );

        $cacheItem = $this->cache->getItem($cacheKey);

        if ($progressCallback !== null) {
            $progressCallback(null);
        }

        // cache-hit
        if ($cacheItem->isHit()) {
            $filesList = $cacheItem->get();
            $this->fileList = $filesList;
            return $filesList;
        }

        // cache-miss, index directory
        $filesList = $this->indexDirectory($progressCallback);

        $cacheItem->set($filesList);
        $cacheItem->expiresAfter(365 * 24 * 60 * 60);
        $this->cache->save($cacheItem);
        $this->fileList = $filesList;

        if ($progressCallback !== null) {
            $progressCallback(null);
        }

        return $filesList;
    }

    /**
     * WARNING: Slow on large directories!
     * @param callable|null $progressCallback
     * @return array
     * @throws AccessDeniedException
     * @throws Exception
     * @throws UnexpectedValueException
     * @throws UnsupportedException
     */
    private function indexDirectory(?callable $progressCallback): array
    {
        $iterator = $this->rootDir
            ->list(true)
            ->filterStorage(function (Storage\Disk $storage) use ($progressCallback): bool {
                if ($progressCallback !== null) {
                    $progressCallback(null);
                }

                if (!$storage->isReadable()) {
                    return false;
                }

                if ($this->pathIgnore->isForbidden($storage)) {
                    return false;
                }

                if ($storage->path()->filename === PathIgnore::FILEIGNORE_FILENAME) {
                    return false;
                }

                if ($progressCallback !== null) {
                    $progressCallback($storage);
                }

                return true;
            });

        $fileList = [];
        foreach ($iterator->all($this->rootDir->storage()->getConstraints()) as $file) {
            $fileList[] = $file->path()->filepath;
        }

        return $fileList;
    }
}
