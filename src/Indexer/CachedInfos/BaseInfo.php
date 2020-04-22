<?php

namespace ricwein\Indexer\Indexer\CachedInfos;

use ricwein\FileSystem\Storage;
use ricwein\Indexer\Core\Cache;

abstract class BaseInfo
{
    protected Storage $storage;
    protected ?Cache $cache;

    public function __construct(Storage $storage, ?Cache $cache)
    {
        $this->storage = $storage;
        $this->cache = $cache;
    }

    /**
     * @param Storage $storage
     * @return string
     */
    protected static function buildCacheKey(Storage $storage): string
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


}
