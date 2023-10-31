<?php

namespace App\Service;

use App\Model\CachedFileSystem\CacheableDirectory;
use App\Model\CachedFileSystem\CacheableFile;
use Generator;
use Psr\Cache\InvalidArgumentException;
use ricwein\FileSystem\Directory;
use ricwein\FileSystem\Enum\Hash;
use ricwein\FileSystem\File;
use ricwein\FileSystem\FileSystem;
use ricwein\FileSystem\Helper\Constraint;
use ricwein\FileSystem\Helper\Creator;
use ricwein\FileSystem\Storage\StorageInterface;
use Symfony\Contracts\Cache\CacheInterface;
use UnexpectedValueException;

readonly class CacheableFileSystem
{
    public function __construct(private CacheInterface $cache) {}

    /**
     * @return CacheableFile|CacheableDirectory
     * @throws InvalidArgumentException
     */
    public function get(StorageInterface|FileSystem $storage): ?FileSystem
    {
        $file = $storage instanceof StorageInterface ? Creator::from(
            fileInfo: $storage,
            constraint: Constraint::IN_SAFEPATH | Constraint::IN_OPEN_BASEDIR
        ) : $storage;

        if ($file === null) {
            return null;
        }

        $cacheKey = self::getCacheKey('file', $file);
        return $this->cache->get($cacheKey, fn() => $file->as(
            match ($file::class) {
                File::class => CacheableFile::class,
                Directory::class => CacheableDirectory::class,
                default => throw new UnexpectedValueException(
                    sprintf("Unable to map to cacheable filesystem object for '%s'.", $file::class)
                ),
            }
        ));
    }

    public function listFiles(Directory $inDirectory): Generator {}

    public static function getCacheKey(string $prefix, FileSystem $file): string
    {
        return sprintf(
            '%s_%s-%d',
            rtrim(trim($prefix), '_-'),
            $file->getHash(Hash::FILEPATH, 'sha1'),
            $file->getTime(),
        );
    }
}
