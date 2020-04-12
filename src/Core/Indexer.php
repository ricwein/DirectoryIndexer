<?php


namespace ricwein\DirectoryIndex\Core;


use Generator;
use ricwein\DirectoryIndex\Config\Config;
use ricwein\FileSystem\Directory;
use ricwein\FileSystem\Exceptions\AccessDeniedException;
use ricwein\FileSystem\Exceptions\Exception;
use ricwein\FileSystem\Exceptions\UnexpectedValueException;
use ricwein\FileSystem\Exceptions\UnsupportedException;
use ricwein\FileSystem\Storage;
use ricwein\FileSystem\Exceptions\RuntimeException as FileSystemRuntimeException;
use ricwein\Templater\Exceptions\RuntimeException;

class Indexer
{
    private Config $config;
    private Directory $directory;
    private Directory $rootDir;
    private ?Cache $cache = null;

    public const FILEIGNORE_FILENAME = '.indexignore';
    public const FILEFORBIDDEN_FILENAME = '.indexforbidden';

    /**
     * Indexer constructor.
     * @param Directory $rootDir
     * @param Directory $dir
     * @param Config $config
     * @param Cache|null $cache
     */
    public function __construct(Directory $rootDir, Directory $dir, Config $config, ?Cache $cache)
    {
        $this->rootDir = $rootDir;
        $this->directory = $dir;
        $this->config = $config;
        $this->cache = $cache;
    }

    public function dir(): Directory
    {
        return $this->directory;
    }

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
     * @throws UnexpectedValueException
     * @throws Exception
     * @throws UnsupportedException
     */
    public function list(): Generator
    {
        yield from $this->directory
            ->list(false)
            ->filterStorage([$this, 'filterFileStorage'])
            ->dirs();

        yield from $this->directory
            ->list(false)
            ->filterStorage([$this, 'filterFileStorage'])
            ->files();
    }

    /**
     * @param Storage\Disk $storage
     * @return bool
     * @throws FileSystemRuntimeException
     * @throws UnexpectedValueException
     */
    public function filterFileStorage(Storage\Disk $storage): bool
    {
        if ($this->config->hideDotfiles && $storage->isDotfile()) {
            return false;
        }

        if (!$storage->isReadable()) {
            return false;
        }

        if ($storage->path()->real === $sourcePath) {
            return false;
        }

        if ($storage->isDir()) {
            return true;
        }

        if (in_array($storage->path()->filename, [static::FILEIGNORE_FILENAME, static::FILEFORBIDDEN_FILENAME], true)) {
            return false;
        }

        return true;
    }


}
