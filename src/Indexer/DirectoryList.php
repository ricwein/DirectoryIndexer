<?php

namespace ricwein\Indexer\Indexer;

use Generator;
use ricwein\FileSystem\Enum\Time;
use ricwein\FileSystem\Exceptions\ConstraintsException;
use ricwein\FileSystem\FileSystem;
use ricwein\Indexer\Config\Config;
use ricwein\FileSystem\Directory;
use ricwein\FileSystem\Exceptions\AccessDeniedException;
use ricwein\FileSystem\Exceptions\Exception;
use ricwein\FileSystem\Exceptions\UnexpectedValueException;
use ricwein\FileSystem\Exceptions\UnsupportedException;
use ricwein\FileSystem\Storage;
use ricwein\FileSystem\Exceptions\RuntimeException as FileSystemRuntimeException;
use ricwein\Templater\Exceptions\RuntimeException;
use SplFileInfo;

class DirectoryList
{
    private Directory $directory;
    private Directory $rootDir;
    private PathIgnore $pathIgnore;
    private Config $config;

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
        $this->config = $config;

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
     * @return FileSystem[]
     * @throws AccessDeniedException
     * @throws Exception
     * @throws UnexpectedValueException
     * @throws UnsupportedException
     */
    public function list(): array
    {
        $iterator = $this->directory
            ->list(false)
            ->filterPath([$this, 'filterFileIndex']);

        switch (strtolower($this->config->sortBy)) {

            case 'dynamic':
                return array_merge(
                    iterator_to_array($iterator->dirs()),
                    iterator_to_array($iterator->files())
                );

            case 'lastmodified':
            case 'last_modified':
                $files = iterator_to_array($iterator->all());
                usort($files, static function (FileSystem $fileA, FileSystem $fileB): int {
                    return $fileB->getTime(Time::LAST_MODIFIED) - $fileA->getTime(Time::LAST_MODIFIED);
                });
                return $files;

            case 'created':
                $files = iterator_to_array($iterator->all());
                usort($files, static function (FileSystem $fileA, FileSystem $fileB): int {
                    return $fileB->getTime(Time::CREATED) - $fileA->getTime(Time::CREATED);
                });
                return $files;

            case 'name':
                $files = iterator_to_array($iterator->all());
                usort($files, static function (FileSystem $fileA, FileSystem $fileB): int {
                    return strcmp(
                        $fileA->isDir() ? $fileA->path()->basename : $fileA->path()->filename,
                        $fileB->isDir() ? $fileB->path()->basename : $fileB->path()->filename
                    );
                });
                return $files;

            case 'rand':
            case 'random':
            case 'rng':
                $files = iterator_to_array($iterator->all());
                $fileCount = count($files);
                usort($files, static fn(): int => random_int($fileCount * -1, $fileCount));
                return $files;

        }

        throw new \UnexpectedValueException("Unsupported sortBy: '{$this->config->sortBy}'. Unable to order files.", 500);
    }

    /**
     * @param SplFileInfo $file
     * @return bool
     * @throws AccessDeniedException
     * @throws ConstraintsException
     * @throws Exception
     * @throws FileSystemRuntimeException
     * @throws UnexpectedValueException
     * @internal
     */
    public function filterFileIndex(SplFileInfo $file): bool
    {
        if (!$file->isReadable()) {
            return false;
        }

        if ($this->pathIgnore->isHiddenFileInfo($file)) {
            return false;
        }

        if ($file->getFilename() === PathIgnore::FILEIGNORE_FILENAME) {
            return false;
        }

        return true;
    }


}
