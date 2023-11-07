<?php

namespace App\Service;

use App\Model\DTO\File as FileDTO;
use App\Model\DTO\Hashes;
use Generator;
use Intervention\Image\Constraint as IConstraint;
use Intervention\Image\Image as IImage;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use ricwein\FileSystem\Directory;
use ricwein\FileSystem\Enum\Hash;
use ricwein\FileSystem\File;
use ricwein\FileSystem\FileSystem as BaseFileSystem;
use ricwein\FileSystem\Helper\Constraint;
use ricwein\FileSystem\Helper\Creator;
use ricwein\FileSystem\Model\FileSize;
use ricwein\FileSystem\Storage;
use ricwein\FileSystem\Storage\StorageInterface;

readonly class FileSystem
{
    private const DEFAULT_FILE_CONSTRAINT = Constraint::IN_SAFEPATH | Constraint::IN_OPEN_BASEDIR;

    public function __construct(private CacheItemPoolInterface $cache) {}

    /**
     * @return File|Directory
     */
    public function get(StorageInterface|FileSystem $storage): ?BaseFileSystem
    {
        return Creator::from(
            fileInfo: $storage,
            constraint: self::DEFAULT_FILE_CONSTRAINT
        );
    }

    /** @return null|array{file: FileDTO, size: null|FileSize, hashes: null|Hashes}
     * @throws InvalidArgumentException
     */
    public function getDTOs(StorageInterface|FileSystem $storage, bool $forceCalculate): ?array
    {
        if (null === $file = $this->get($storage)) {
            return null;
        }
        $dto = new FileDTO($file);

        return [
            'file' => $dto,
            'size' => $this->getSize($file, $forceCalculate),
            'hashes' => $this->getHashes($file, $forceCalculate),
        ];
    }

    /**
     * @throws InvalidArgumentException
     */
    public function getSize(BaseFileSystem $file, bool $calculateIfNotCached): ?FileSize
    {
        $cacheKey = self::getCacheKey('size', $file);
        $sizeCacheItem = $this->cache->getItem($cacheKey);

        if ($sizeCacheItem->isHit()) {
            return $sizeCacheItem->get();
        }

        if (!$calculateIfNotCached) {
            return null;
        }

        $size = $file->getSize();
        $sizeCacheItem->set($size);
        $this->cache->save($sizeCacheItem);
        return $size;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function getHashes(BaseFileSystem $file, bool $calculateIfNotCached): ?Hashes
    {
        $cacheKey = self::getCacheKey('hashes', $file);
        $hashCacheItem = $this->cache->getItem($cacheKey);

        if ($hashCacheItem->isHit()) {
            return $hashCacheItem->get();
        }

        if (!$calculateIfNotCached) {
            return null;
        }

        $hashes = Hashes::calculate($file);
        $hashCacheItem->set($hashes);
        $this->cache->save($hashCacheItem);
        return $hashes;
    }


    /**
     * @throws InvalidArgumentException
     */
    public function getPreview(Storage\BaseStorage&Storage\FileStorageInterface $imageStorage, int $size): File\Image
    {
        $image = new File\Image(
            storage: $imageStorage,
            constraints: self::DEFAULT_FILE_CONSTRAINT
        );

        return $this->cache->get(
            key: sprintf('%1$s_%2$dx%2$d', self::getCacheKey('preview', $image), $size),
            callback: fn(): File\Image => $image
                ->copyTo(new Storage\Memory())
                ->edit(fn(IImage $image): IImage => $image
                    ->fit($size, $size, function (IConstraint $constraint) {
                        $constraint->aspectRatio();
                    })
                ),
            beta: 0
        );
    }

    /**
     * @return Generator<array{file: FileDTO, size: null|FileSize, hashes: null|Hashes}>
     * @throws InvalidArgumentException
     */
    public function iterate(Directory $directory): Generator
    {
        foreach ($directory->list()->storages() as $storage) {
            if (null !== $data = $this->getDTOs($storage, false)) {
                yield $data;
            }
        }
    }

    public static function getCacheKey(string $prefix, BaseFileSystem $file): string
    {
        return sprintf(
            '%s_%s-%d',
            rtrim($prefix, ' _-'),
            $file->getHash(Hash::FILEPATH, 'sha1'),
            $file->getTime(),
        );
    }
}
