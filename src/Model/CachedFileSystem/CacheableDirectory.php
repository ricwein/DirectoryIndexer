<?php

namespace App\Model\CachedFileSystem;

use ricwein\FileSystem\Directory;
use ricwein\FileSystem\Enum\Hash;
use ricwein\FileSystem\Model\FileSize;

class CacheableDirectory extends Directory
{
    use CacheFileSystemTrait;

    private static function getCacheKeyHash(Hash $mode, string $algo, bool $raw, bool $recursive): string
    {
        return sprintf("hash_%s-%s-%d-%d", $mode->asString(), $algo, (int)$raw, (int)$recursive);
    }


    public function getHash(Hash $mode = Hash::CONTENT, string $algo = 'sha256', bool $raw = false, bool $recursive = false): string
    {
        return $this->getCached(
            key: self::getCacheKeyHash($mode, $algo, $raw, $recursive),
            fn: fn() => parent::getHash($mode, $algo, $raw, $recursive)
        );
    }

    public function isHashCached(Hash $mode = Hash::CONTENT, string $algo = 'sha256', bool $raw = false, bool $recursive = false): bool
    {
        return array_key_exists(self::getCacheKeyHash($mode, $algo, $raw, $recursive), $this->cachedData);
    }

    public function getSize(bool $recursive = true): FileSize
    {
        return $this->getCached(
            key: sprintf('size_%d', (int)$recursive),
            fn: fn() => parent::getSize($recursive),
        );
    }

    public function isSizeCached(): bool
    {
        return array_key_exists('size', $this->cachedData);
    }
}
