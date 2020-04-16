<?php

namespace ricwein\Indexer\Indexer;

use Phpfastcache\Exceptions\PhpfastcacheInvalidArgumentException;
use ricwein\FileSystem\Directory;
use ricwein\FileSystem\Exceptions\AccessDeniedException;
use ricwein\FileSystem\Exceptions\Exception;
use ricwein\FileSystem\Exceptions\RuntimeException;
use ricwein\FileSystem\Exceptions\UnexpectedValueException;
use ricwein\FileSystem\Exceptions\UnsupportedException;
use ricwein\FileSystem\File;
use ricwein\FileSystem\FileSystem;
use ricwein\FileSystem\Storage;
use ricwein\Indexer\Config\Config;
use ricwein\Indexer\Core\Cache;

class Search
{
    private Directory $rootDir;
    private Index $index;

    /**
     * Search constructor.
     * @param Directory $rootDir
     * @param Config $config
     * @param Cache|null $cache
     */
    public function __construct(Directory $rootDir, Config $config, ?Cache $cache)
    {
        $this->rootDir = $rootDir;
        $this->index = new Index($rootDir, $config, $cache);
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

        $pattern = str_replace([
            '/', '.*', '*', 'DOT_STAR'
        ], [
            '\\/', 'DOT_STAR', '.*', '.*'
        ], $searchTerm);

        /** @var Storage\Disk[] $matchingFiles */
        $matchingFiles = [];

        // use glob-matching to find all matching files from cached index
        foreach ($this->index->list() as $filepath) {

            if (@preg_match("/.*{$pattern}.*/", $filepath) !== 1) {
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

        $constraints = $this->rootDir->storage()->getConstraints();

        // warp storages into related FileSystem objects (File/Directory)
        $files = array_map(static function (Storage\Disk $storage) use ($constraints): FileSystem {
            if ($storage->isDir()) {
                return new Directory($storage, $constraints);
            }
            return new File($storage, $constraints);
        }, $matchingFiles);

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
}
