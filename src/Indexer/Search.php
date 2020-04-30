<?php

namespace ricwein\Indexer\Indexer;

use ricwein\FileSystem\Directory;
use ricwein\FileSystem\Exceptions\AccessDeniedException;
use ricwein\FileSystem\Exceptions\ConstraintsException;
use ricwein\FileSystem\Exceptions\Exception;
use ricwein\FileSystem\Exceptions\RuntimeException;
use ricwein\FileSystem\Exceptions\UnexpectedValueException;
use ricwein\FileSystem\Exceptions\UnsupportedException;
use ricwein\FileSystem\File;
use ricwein\FileSystem\FileSystem;
use ricwein\FileSystem\Storage;
use ricwein\Indexer\Config\Config;
use ricwein\Indexer\Core\Cache;
use ricwein\Indexer\Indexer\FileInfo\FileInfo;
use ricwein\Indexer\Indexer\FileInfo\MetaData;

class Search
{
    private Directory $rootDir;
    private Index $index;
    private Config $config;
    private ?Cache $cache;
    private PathIgnore $pathIgnore;

    /**
     * Search constructor.
     * @param Directory $rootDir
     * @param Config $config
     * @param Cache|null $cache
     */
    public function __construct(Directory $rootDir, Config $config, ?Cache $cache)
    {
        $this->rootDir = $rootDir;
        $this->cache = $cache;
        $this->config = $config;
        $this->index = new Index($rootDir, $config, $cache);
        $this->pathIgnore = new PathIgnore($rootDir, $config);
    }

    /**
     * @param string $searchTerm
     * @return array
     * @throws RuntimeException
     * @throws UnexpectedValueException
     * @throws AccessDeniedException
     * @throws Exception
     * @throws UnsupportedException
     */
    public function search(string $searchTerm): array
    {
        // builds pattern variants and extend pattern with root-dir
        $searchTerm = trim($searchTerm);
        if (empty($searchTerm)) {
            return [];
        }

        /** @var Storage\Disk[] $storages */
        if (preg_match('/^(.+):(.+)$/i', $searchTerm, $matches) === 1 && count($matches) === 3) {
            $filter = strtolower(trim($matches[1]));
            $filterValue = trim($matches[2]);

            switch ($filter) {
                case 'type':
                    $storages = $this->searchType($filterValue, false);
                    break;

                case 'mime':
                    $storages = $this->searchType($filterValue, true);
                    break;

                case 'hash':
                    $storages = $this->searchHash($filterValue, null);
                    break;

                case 'md5':
                    $storages = $this->searchHash($filterValue, 'md5');
                    break;

                case 'sha1':
                    $storages = $this->searchHash($filterValue, 'sha1');
                    break;

                case 'sha256':
                    $storages = $this->searchHash($filterValue, 'sha256');
                    break;

                default:
                    throw new \UnexpectedValueException("Unknown filter-type: '{$filter}. Supported filters are: 'type:[term]', 'mime:[term]', 'hash:[term]'.", 400);

            }

        } else {
            $storages = $this->searchFilename($searchTerm);
        }

        // remove hidden storages (the index-list doesn't contain denied storages, but hidden ones are listed)
        $storages = array_filter($storages, function (Storage\Disk $storage): bool {
            if (null !== $metadata = MetaData::fromCache($storage, $this->cache)) {
                return !$metadata->isHidden;
            }
            return !$this->pathIgnore->isHiddenStorage($storage);
        });

        $constraints = $this->rootDir->storage()->getConstraints();

        // warp storages into related FileSystem objects (File/Directory)
        $files = array_map(static function (Storage\Disk $storage) use ($constraints): FileSystem {
            if ($storage->isDir()) {
                return new Directory($storage, $constraints);
            }
            return new File($storage, $constraints);
        }, $storages);

        // sort by type, than by filepath (alphabetical)
        usort($files, static function (FileSystem $file_a, FileSystem $file_b): int {
            if ($file_a instanceof Directory && $file_b instanceof File) {
                return -1;
            }

            if ($file_a instanceof File && $file_b instanceof Directory) {
                return 1;
            }

            return strcmp($file_a->path()->filepath, $file_b->path()->filepath);
        });

        return $files;
    }

    /**
     * @param string $type
     * @param bool $mimeTypeOnly
     * @return Storage\Disk[]
     * @throws AccessDeniedException
     * @throws Exception
     * @throws RuntimeException
     * @throws UnexpectedValueException
     * @throws UnsupportedException
     */
    private function searchType(string $type, bool $mimeTypeOnly): array
    {
        /** @var Storage\Disk[] $matchingFiles */
        $matchingFiles = [];

        $constraints = $this->rootDir->storage()->getConstraints();
        foreach ($this->index->list() as $filepath) {

            $storage = new Storage\Disk($this->rootDir->path()->real, $filepath);
            $fileInfo = new FileInfo($storage, $this->cache, $this->config, $this->rootDir, $constraints);

            if (!$fileInfo->isCached()) {
                continue;
            }

            $mimeType = $fileInfo->getMetaData()->mimeType;

            if ($mimeType !== null && !$storage->isDir() && stripos($mimeType, $type) !== false) {
                $matchingFiles[] = $storage;
            } elseif (!$mimeTypeOnly && stripos($fileInfo->getMetaData()->type, $type) !== false) {
                $matchingFiles[] = $storage;
            }
        }

        return $matchingFiles;
    }

    /**
     * @param string $hash
     * @param string|null $algo
     * @return array|Storage\Disk[]
     * @throws AccessDeniedException
     * @throws Exception
     * @throws RuntimeException
     * @throws UnexpectedValueException
     * @throws UnsupportedException
     * @throws ConstraintsException
     */
    private function searchHash(string $hash, ?string $algo): array
    {
        /** @var Storage\Disk[] $matchingFiles */
        $matchingFiles = [];

        $constraints = $this->rootDir->storage()->getConstraints();
        foreach ($this->index->list() as $filepath) {

            $storage = new Storage\Disk($this->rootDir->path()->real, $filepath);
            if ($storage->isDir()) {
                continue;
            }

            $fileInfo = new FileInfo($storage, $this->cache, $this->config, $this->rootDir, $constraints);

            if (!$fileInfo->isCached()) {
                continue;
            }

            $metaData = $fileInfo->getMetaData();

            switch (true) {
                case ($algo === null || $algo === 'md5') && stripos($metaData->hashMD5, $hash) !== false:
                case ($algo === null || $algo === 'sha1') && stripos($metaData->hashSHA1, $hash) !== false:
                case ($algo === null || $algo === 'sha256') && stripos($metaData->hashSHA256, $hash) !== false:
                    $matchingFiles[] = $storage;
                    break;
            }
        }

        return $matchingFiles;
    }


    /**
     * @param string $searchTerm
     * @return Storage\Disk[]
     * @throws RuntimeException
     * @throws UnexpectedValueException
     * @throws AccessDeniedException
     * @throws Exception
     * @throws UnsupportedException
     */
    private function searchFilename(string $searchTerm): array
    {
        $pattern = str_replace([
            '/', '.*', '*', '__INTERNAL_DOT_STAR___'
        ], [
            '\\/', '__INTERNAL_DOT_STAR___', '.*', '.*'
        ], $searchTerm);

        /** @var Storage\Disk[] $matchingFiles */
        $matchingFiles = [];

        // use glob-matching to find all matching files from cached index
        foreach ($this->index->list() as $filepath) {

            if (@preg_match("/.*{$pattern}.*/i", $filepath) !== 1) {
                continue;
            }

            foreach ($matchingFiles as $alreadyMatchedStorage) {
                if (strpos($filepath, $alreadyMatchedStorage->path()->filepath) === 0) {
                    continue 2;
                }
            }

            $storage = new Storage\Disk($this->rootDir->path()->real, $filepath);
            $matchingFiles[] = $storage;
        }

        return $matchingFiles;
    }


}
