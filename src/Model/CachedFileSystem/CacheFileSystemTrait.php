<?php

namespace App\Model\CachedFileSystem;

use Closure;

trait CacheFileSystemTrait
{
    private array $cachedData = [];

    private function getCached(string $key, ?Closure $fn = null): mixed
    {
        if (array_key_exists(key: $key, array: $this->cachedData)) {
            return $this->cachedData[$key];
        }

        if ($fn !== null) {
            return $this->cachedData[$key] = $fn();
        }

        return null;
    }
}
