<?php

/** @noinspection ClassMethodNameMatchesFieldNameInspection */

namespace ricwein\Indexer\Indexer\FileInfo;

use ricwein\FileSystem\Directory;
use ricwein\FileSystem\Enum\Hash;
use ricwein\FileSystem\Enum\Time;
use ricwein\FileSystem\Exceptions\AccessDeniedException;
use ricwein\FileSystem\Exceptions\ConstraintsException;
use ricwein\FileSystem\Exceptions\Exception;
use ricwein\FileSystem\Exceptions\RuntimeException;
use ricwein\FileSystem\Exceptions\UnexpectedValueException;
use ricwein\FileSystem\Exceptions\UnsupportedException;
use ricwein\FileSystem\Helper\Path;
use ricwein\FileSystem\Storage;
use ricwein\Indexer\Config\Config;
use ricwein\Indexer\Core\Cache;
use ricwein\Indexer\Indexer\PathIgnore;

/**
 * Class MetaData
 * @package ricwein\Indexer\Indexer\FileInfo
 */
class MetaData
{
    private bool $wasUpdated = false;
    private ?string $cacheKey = null;
    private Directory $rootDir;
    private ?Cache $cache;
    private Config $config;
    private Storage\Disk $storage;

    private string $name;

    private bool $supportsThumbnail;
    private bool $isDir;

    private ?string $mimeType; // expensive
    private ?string $type; // expensive
    private ?string $faIcon; // expensive

    private ?int $size; // expensive (for dirs)
    private int $timeLastModified;
    private int $timeLastAccessed;
    private int $timeCreated;

    private ?string $hashMD5; // expensive
    private ?string $hashSHA1; // expensive
    private ?string $hashSHA256; // expensive

    private ?bool $isHidden; // expensive

    /**
     * @param Storage\Disk $storage
     * @param Cache|null $cache
     * @param Directory $rootDir
     * @param Config $config
     * @throws RuntimeException
     * @throws UnexpectedValueException
     * @noinspection PhpFieldAssignmentTypeMismatchInspection
     */
    public function __construct(Storage\Disk $storage, ?Cache $cache, Directory $rootDir, Config $config)
    {
        $cachedAttributes = [];
        if ($cache !== null) {

            $this->cacheKey = static::buildCacheKey($storage);
            $cacheItem = $cache->getItem($this->cacheKey);

            if ($cacheItem->isHit()) {
                $cachedAttributes = (array)$cacheItem->get();
            } else {
                $this->wasUpdated = true;
            }
        }

        $isDir = $cachedAttributes['isDir'] ?? $storage->isDir();
        $size = $cachedAttributes['size'] ?? (!$isDir ? $storage->getSize() : null);

        // init attributes
        $this->storage = $storage;
        $this->cache = $cache;
        $this->rootDir = $rootDir;
        $this->config = $config;

        $this->name = $cachedAttributes['name'] ?? ($isDir ? $storage->path()->basename : $storage->path()->filename);
        $this->supportsThumbnail = $cachedAttributes['supportsThumbnail'] ?? static::canHasThumbnail($storage);
        $this->isDir = $isDir;

        $this->mimeType = $cachedAttributes['mimeType'] ?? null;
        $this->type = $cachedAttributes['type'] ?? null;
        $this->faIcon = $cachedAttributes['faIcon'] ?? null;

        $this->size = $size;

        $this->timeLastModified = $cachedAttributes['timeLastModified'] ?? $storage->getTime(Time::LAST_MODIFIED);
        $this->timeLastAccessed = $cachedAttributes['timeLastAccessed'] ?? $storage->getTime(Time::LAST_ACCESSED);
        $this->timeCreated = $cachedAttributes['timeCreated'] ?? $storage->getTime(Time::CREATED);

        $this->hashMD5 = $cachedAttributes['hashMD5'] ?? null;
        $this->hashSHA1 = $cachedAttributes['hashSHA1'] ?? null;
        $this->hashSHA256 = $cachedAttributes['hashSHA256'] ?? null;

        $this->isHidden = $cachedAttributes['isHidden'] ?? null;
    }

    public function isCached(string $name): bool
    {
        switch ($name) {
            case 'hashMD5':
                return $this->isDir || $this->hashMD5 !== null;

            case 'hashSHA1':
                return $this->isDir || $this->hashSHA1 !== null;

            case 'hashSHA256':
                return $this->isDir || $this->hashSHA256 !== null;

            default:
                return property_exists($this, $name) && $this->$name !== null;
        }
    }

    public function __destruct()
    {
        if ($this->wasUpdated) {
            $this->updateCache();
        }
    }

    public static function canHasThumbnail(Storage\Disk $storage): bool
    {
        $extension = strtolower($storage->path()->extension);
        return in_array($extension, ['png', 'gif', 'bmp', 'jpg', 'jpeg'], true);
    }

    /**
     * @param Storage\Disk $storage
     * @return string
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    private static function buildCacheKey(Storage\Disk $storage): string
    {
        return str_replace(
            ['{', '}', '(', ')', '/', '\\', '@', ':'],
            ['|', '|', '|', '|', '.', '.', '-', '_'],
            sprintf('%s_%s|%d', 'file_metadata', $storage->path()->real, $storage->getTime() ?? 0)
        );
    }

    public function updateCache(): bool
    {
        if ($this->cache === null || $this->cacheKey === null || null === $cacheItem = $this->cache->getItem($this->cacheKey)) {
            return true;
        }

        $this->wasUpdated = false;

        $cacheItem->set($this->getCacheableAttributes());
        $cacheItem->expiresAfter(FileInfo::CACHE_DURATION);
        return $this->cache->save($cacheItem);
    }

    private function getCacheableAttributes(): array
    {
        return [
            'name' => $this->name,
            'supportsThumbnail' => $this->supportsThumbnail,
            'isDir' => $this->isDir,
            'mimeType' => $this->mimeType,
            'type' => $this->type,
            'faIcon' => $this->faIcon,
            'size' => $this->size,
            'timeLastModified' => $this->timeLastModified,
            'timeLastAccessed' => $this->timeLastAccessed,
            'timeCreated' => $this->timeCreated,
            'hashMD5' => $this->hashMD5,
            'hashSHA1' => $this->hashSHA1,
            'hashSHA256' => $this->hashSHA256,
            'isHidden' => $this->isHidden,
        ];
    }

    public function filename(): string
    {
        return $this->name;
    }

    public function supportsThumbnail(): bool
    {
        return $this->supportsThumbnail;
    }

    public function isDir(): bool
    {
        return $this->isDir;
    }

    /**
     * @return string|null
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    public function mimeType(): ?string
    {
        if (!isset($this->type)) {
            [$this->type, $this->faIcon, $this->mimeType] = static::typeFromStorage($this->storage, $this->rootDir);
            $this->wasUpdated = true;
        }

        return $this->mimeType;
    }

    /**
     * @return string
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    public function faIcon(): string
    {
        if (!isset($this->faIcon)) {
            [$this->type, $this->faIcon, $this->mimeType] = static::typeFromStorage($this->storage, $this->rootDir);
            $this->wasUpdated = true;
        }

        return $this->faIcon;
    }

    /**
     * @return string
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    public function type(): string
    {
        if (!isset($this->type)) {
            [$this->type, $this->faIcon, $this->mimeType] = static::typeFromStorage($this->storage, $this->rootDir);
            $this->wasUpdated = true;
        }

        return $this->type;
    }

    /**
     * @return int
     * @throws AccessDeniedException
     * @throws Exception
     * @throws RuntimeException
     * @throws UnexpectedValueException
     * @throws UnsupportedException
     */
    public function size(): int
    {
        if (!isset($this->size)) {
            $this->size = $this->isDir ? (new Directory($this->storage, $this->rootDir->storage()->getConstraints()))->getSize() : $this->storage->getSize();
            $this->wasUpdated = true;
        }

        return $this->size;
    }

    public function timeLastModified(): int
    {
        return $this->timeLastModified;
    }

    public function timeLastAccessed(): int
    {
        return $this->timeLastAccessed;
    }

    public function timeCreated(): int
    {
        return $this->timeCreated;
    }

    /**
     * @return string|null
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    public function hashMD5(): ?string
    {
        if ($this->isDir) {
            return null;
        }

        if (!isset($this->hashMD5)) {
            $this->hashMD5 = $this->storage->getFileHash(Hash::CONTENT, 'md5');
            $this->wasUpdated = true;
        }

        return $this->hashMD5;
    }

    /**
     * @return string|null
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    public function hashSHA1(): ?string
    {
        if ($this->isDir) {
            return null;
        }

        if (!isset($this->hashSHA1)) {
            $this->hashSHA1 = $this->storage->getFileHash(Hash::CONTENT, 'sha1');
            $this->wasUpdated = true;
        }

        return $this->hashSHA1;
    }

    /**
     * @return string|null
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    public function hashSHA256(): ?string
    {
        if ($this->isDir) {
            return null;
        }

        if (!isset($this->hashSHA256)) {
            $this->hashSHA256 = $this->storage->getFileHash(Hash::CONTENT, 'sha256');
            $this->wasUpdated = true;
        }

        return $this->hashSHA256;
    }

    /**
     * @return bool
     * @throws AccessDeniedException
     * @throws ConstraintsException
     * @throws Exception
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    public function isHidden(): bool
    {
        if (!isset($this->isHidden)) {
            $this->isHidden = (new PathIgnore($this->rootDir, $this->config))->isHiddenStorage($this->storage);
            $this->wasUpdated = true;
        }
        return $this->isHidden;
    }

    /**
     * @return bool
     * @throws AccessDeniedException
     * @throws ConstraintsException
     * @throws Exception
     * @throws RuntimeException
     * @throws UnexpectedValueException
     * @throws UnsupportedException
     * @noinspection PhpFieldAssignmentTypeMismatchInspection
     */
    public function updateAllAttributes(): bool
    {
        $isDir = $this->storage->isDir();
        $this->name = $isDir ? $this->storage->path()->basename : $this->storage->path()->filename;
        $this->supportsThumbnail = static::canHasThumbnail($this->storage);
        $this->isDir = $isDir;

        [$this->type, $this->faIcon, $this->mimeType] = static::typeFromStorage($this->storage, $this->rootDir);

        $this->size = $isDir ? (new Directory($this->storage, $this->rootDir->storage()->getConstraints()))->getSize() : $this->storage->getSize();

        $this->timeLastModified = $this->storage->getTime(Time::LAST_MODIFIED);
        $this->timeLastAccessed = $this->storage->getTime(Time::LAST_ACCESSED);
        $this->timeCreated = $this->storage->getTime(Time::CREATED);

        $this->hashMD5 = $isDir ? null : $this->storage->getFileHash(Hash::CONTENT, 'md5');
        $this->hashSHA1 = $isDir ? null : $this->storage->getFileHash(Hash::CONTENT, 'sha1');
        $this->hashSHA256 = $isDir ? null : $this->storage->getFileHash(Hash::CONTENT, 'sha256');

        $this->isHidden = (new PathIgnore($this->rootDir, $this->config))->isHiddenStorage($this->storage);
        $this->wasUpdated = true;

        return $this->updateCache();
    }

    /**
     * @param Storage\Disk $storage
     * @param Directory $rootDir
     * @return array
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    private static function typeFromStorage(Storage\Disk $storage, Directory $rootDir): array
    {
        if ($storage->isSymlink()) {
            return ['symlink', 'fas fa-external-link-alt', null];
        }

        if (!$storage instanceof Storage\Disk) {
            if ($storage->isDir()) {
                return ['directory', 'fas fa-folder', null];
            }
            return ['file', 'far fa-file', $storage->getFileType()];
        }

        $filename = strtolower($storage->isDir() ? $storage->path()->basename : $storage->path()->filename);
        $extension = strtolower(pathinfo($storage->path()->real, PATHINFO_EXTENSION));
        $isFile = !$storage->isDir();
        $mimeType = $isFile ? strtolower($storage->getFileType()) : null;

        switch (true) {
            case !$isFile && $storage->path()->real === $rootDir->path()->real:
                return ['directory', 'far fa-folder', null];

            case !$isFile && $storage->path()->real === (new Path([__DIR__, '/../../../']))->real:
                return ['directory', 'fas fa-crown', null];

            // known files (full names)
            case in_array($filename, ['.gitignore', '.dockerignore', '.indexignore'], true):
                return [$isFile ? 'ignore' : 'directory', 'far fa-eye-slash', $mimeType];

            case strpos($filename, '.gitlab') === 0:
                if ($isFile) {
                    return ['GitLab file', 'fab fa-gitlab', $mimeType];
                }
                return ['GitLab config', 'fab fa-gitlab', null];

            case strpos($filename, '.github') === 0:
                if ($isFile) {
                    return ['GitHub file', 'fab fa-github', $mimeType];
                }
                return ['GitHub config', 'fab fa-github', null];

            case strpos($filename, '.git') === 0:
                if ($isFile) {
                    return ['git config', 'fab fa-git', $mimeType];
                }
                return ['git repo', 'fab fa-git-square', null];

            case $isFile && $filename === '.editorconfig':
                return ['editorconfig', 'fas fa-cog', $mimeType];

            case $isFile && in_array($filename, ['license', 'license.txt', 'license.md'], true):
                return ['license', 'far fa-id-badge', $mimeType];

            case $isFile && $extension === 'pdf':
                return ['document', 'far fa-file-pdf', $mimeType];

            case $isFile && in_array($filename, ['dockerfile', 'docker-compose.yml'], true):
            case $isFile && $extension === 'dockerfile':
            case $isFile && strpos($filename, 'docker-compose.') === 0:
                return ['docker config', 'fab fa-docker', $mimeType];

            case $isFile && in_array($filename, ['composer.json', 'composer.lock', 'podfile', 'podfile.lock',], true):
            case $isFile && strpos($filename, 'package.') === 0:
            case $isFile && $extension === 'gradle':
                return ['package manager config', 'fas fa-cube', $mimeType];

            case !$isFile && in_array($filename, ['node_modules', 'vendor', 'pods'], true):
                return ['libraries', 'fas fa-cubes', null];

            // extensions and mime-types
            case $isFile && in_array($extension, ['ini', 'conf', 'plist'], true) :
                return ['config', 'fas fa-cogs', $mimeType];

            case $isFile && in_array($extension, ['dmg', 'iso', 'ccd', 'cue', 'vmdk', 'hds', 'cdr', 'vcd', 'disk'], true) :
                return ['disk-image', 'fas fa-compact-disc', $mimeType];

            case !$isFile && in_array($extension, ['xcworkspace', 'xcodeproj'], true):
                return ['Xcode project', 'fab fa-app-store-ios', null];

            case strpos($mimeType, 'model/') === 0:
                return ['3D model', 'fas fa-dice-d6', $mimeType];

            case strpos($mimeType, 'font/') === 0:
                return ['font', 'fas fa-font', $mimeType];

            case strpos($mimeType, 'image/') === 0:
            case $isFile && in_array($extension, ['png', 'jpg', 'jpeg', 'tiff', 'bmp', 'gif', 'ico', 'svg'], true):
                return ['image', 'far fa-file-image', $mimeType];

            case strpos($mimeType, 'video/') === 0:
            case $isFile && in_array($extension, ['mpeg', 'mp4', 'mov', 'mkv'], true):
                return ['video', 'far fa-file-video', $mimeType];

            case strpos($mimeType, 'audio/') === 0:
            case $isFile && in_array($extension, ['mp3', 'acc', 'wav'], true):
                return ['audio file', 'far fa-file-audio', $mimeType];

            case $isFile && in_array($extension, ['txt', 'rtf', 'md'], true):
                return ['text', 'far fa-file-alt', $mimeType];

            case $isFile && in_array($extension, ['doc', 'docx'], true):
                return ['Word document', 'far fa-file-word', $mimeType];

            case $isFile && in_array($extension, ['xls', 'xlsx'], true):
                return ['Excel spreadsheet', 'far fa-file-excel', $mimeType];

            case $isFile && in_array($extension, ['pptm', 'potm', 'ppsm', 'pps', 'ppsx', 'odp', 'ppt', 'pptx', 'pot', 'potx'], true):
                return ['Powerpoint slideshow', 'far fa-file-powerpoint', $mimeType];

            case $isFile && in_array($extension, ['sql', 'sqlite'], true):
                return ['database', 'fas fa-database', $mimeType];

            case $isFile && in_array($extension, ['blade', 'twig'], true):
                return ['template', 'fas fa-leaf', $mimeType];

            case $isFile && in_array($extension, ['h', 'hpp', 'h++'], true):
                return ['header', 'far fa-file-code', $mimeType];

            case $isFile && in_array($extension, ['css', 'scss', 'sass'], true):
                return ['stylesheet', 'far fa-file-code', $mimeType];

            case $isFile && in_array($extension, ['json', 'yml', 'yaml'], true):
                return ['config', 'far fa-file-code', $mimeType];

            case $isFile && in_array($extension, ['php', 'sh', 'zsh', 'js', 'map', 'm', 'c', 'cpp', 'swift', 'java', 'rb', 'py', 'html', 'htm', 'xml'], true):
                return ['sourcecode', 'far fa-file-code', $mimeType];

            case $isFile && in_array($extension, ['zip', 'rar', 'xip', '7z', 'tar', 'gz'], true):
                return ['archive', 'far fa-file-archive', $mimeType];

        }

        // defaults
        if (!$isFile) {
            return ['directory', 'fas fa-folder', null];
        }

        return ['file', 'far fa-file', $mimeType];
    }

    public function getStorage(): Storage\Disk
    {
        return $this->storage;
    }
}
