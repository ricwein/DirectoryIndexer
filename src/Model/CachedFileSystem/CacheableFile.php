<?php

namespace App\Model\CachedFileSystem;

use ricwein\FileSystem\Enum\Hash;
use ricwein\FileSystem\File;
use ricwein\FileSystem\Model\FileSize;

class CacheableFile extends File
{
    use CacheFileSystemTrait;

    private static function getCacheKeyHash(Hash $mode, string $algo, bool $raw): string
    {
        return sprintf("hash_%s-%s-%d", $mode->asString(), $algo, (int)$raw);
    }

    public function getHash(Hash $mode = Hash::CONTENT, string $algo = 'sha256', bool $raw = false): string
    {
        return $this->getCached(
            key: self::getCacheKeyHash($mode, $algo, $raw),
            fn: fn() => parent::getHash($mode, $algo, $raw)
        );
    }

    public function isHashCached(Hash $mode = Hash::CONTENT, string $algo = 'sha256', bool $raw = false): bool
    {
        return array_key_exists(self::getCacheKeyHash($mode, $algo, $raw), $this->cachedData);
    }

    public function getSize(): FileSize
    {
        return $this->getCached(
            key: 'size',
            fn: fn() => parent::getSize(),
        );
    }

    public function isSizeCached(): bool
    {
        return array_key_exists('size', $this->cachedData);
    }
}
