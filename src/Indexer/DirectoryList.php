<?php

namespace ricwein\Indexer\Indexer;

use Generator;
use ricwein\FileSystem\Exceptions\ConstraintsException;
use ricwein\Indexer\Config\Config;
use ricwein\FileSystem\Directory;
use ricwein\FileSystem\Exceptions\AccessDeniedException;
use ricwein\FileSystem\Exceptions\Exception;
use ricwein\FileSystem\Exceptions\UnexpectedValueException;
use ricwein\FileSystem\Exceptions\UnsupportedException;
use ricwein\FileSystem\Storage;
use ricwein\FileSystem\Exceptions\RuntimeException as FileSystemRuntimeException;
use ricwein\Templater\Exceptions\RuntimeException;

class DirectoryList
{
    private Directory $directory;
    private Directory $rootDir;
    private PathIgnore $pathIgnore;

    /**
     * Indexer constructor.
     * @param Directory $rootDir
     * @param Directory $dir
     * @param Config $config
     */
    public function __construct(Directory $rootDir, Directory $dir, Config $config)
    {
        $this->rootDir = $rootDir;
        $this->directory = $dir;

        $this->pathIgnore = new PathIgnore($this->rootDir, $config);
    }

    public function dir(): Directory
    {
        return $this->directory;
    }

    /**
     * @return bool
     * @throws FileSystemRuntimeException
     */
    public function isRoot(): bool
    {
        return $this->rootDir->path()->real === $this->directory->path()->real;
    }

    /**
     * @return string
     * @throws FileSystemRuntimeException
     * @throws RuntimeException
     */
    public function relativePath(): string
    {
        $rootPath = $this->rootDir->path()->real;
        $currentPath = $this->directory->path()->real;

        if ($rootPath === $currentPath) {
            return '/';
        }

        if (false !== $pos = strpos($currentPath, $rootPath)) {
            return substr_replace($currentPath, '', $pos, strlen($rootPath));
        }

        throw new RuntimeException('Mismatching current- and root-path. This should never happen!', 500);
    }

    /**
     * @return array<string, string> <dirpath, dirname>
     * @throws FileSystemRuntimeException
     * @throws RuntimeException
     */
    public function relativePathDirs(): array
    {
        $relativePath = $this->relativePath();
        $dirpath = '';
        $components = explode('/', $relativePath);
        $result = [];

        foreach ($components as $dir) {
            $dirpath = "{$dirpath}/{$dir}";
            $result[$dirpath] = $dir;
        }

        return $result;
    }

    /**
     * @return string
     * @throws FileSystemRuntimeException
     * @throws RuntimeException
     */
    public function relativePathUp(): string
    {
        $path = $this->relativePath();

        if ($path === '/') {
            return $path;
        }

        if (false === $pos = strrpos($path, '/')) {
            throw new RuntimeException('Invalid Path: missing Delimiter. This should never happen!', 500);
        }

        return substr($path, 0, $pos);
    }

    /**
     * @return Generator
     * @throws AccessDeniedException
     * @throws Exception
     * @throws UnexpectedValueException
     * @throws UnsupportedException
     */
    public function list(): Generator
    {
        $iterator = $this->directory
            ->list(false)
            ->filterStorage([$this, 'filterFileStorage']);

        yield from $iterator->dirs();
        yield from $iterator->files();
    }

    /**
     * @param Storage\Disk $storage
     * @return bool
     * @throws AccessDeniedException
     * @throws Exception
     * @throws FileSystemRuntimeException
     * @throws UnexpectedValueException
     * @throws ConstraintsException
     * @internal
     */
    public function filterFileStorage(Storage\Disk $storage): bool
    {
        if (!$storage->isReadable()) {
            return false;
        }

        if ($this->pathIgnore->isHiddenStorage($storage)) {
            return false;
        }

        if ($storage->path()->filename === PathIgnore::FILEIGNORE_FILENAME) {
            return false;
        }

        return true;
    }


}
