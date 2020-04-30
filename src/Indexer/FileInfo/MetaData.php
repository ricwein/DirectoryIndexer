<?php

/** @noinspection UnusedConstructorDependenciesInspection */

namespace ricwein\Indexer\Indexer\FileInfo;

use ricwein\FileSystem\Directory;
use ricwein\FileSystem\Enum\Hash;
use ricwein\FileSystem\Enum\Time;
use ricwein\FileSystem\Exceptions\AccessDeniedException;
use ricwein\FileSystem\Exceptions\ConstraintsException;
use ricwein\FileSystem\Exceptions\Exception;
use ricwein\FileSystem\Exceptions\RuntimeException;
use ricwein\FileSystem\Exceptions\UnexpectedValueException;
use ricwein\FileSystem\Storage;
use ricwein\Indexer\Config\Config;
use ricwein\Indexer\Core\Cache;
use ricwein\Indexer\Indexer\PathIgnore;

/**
 * Class MetaData
 * @package ricwein\Indexer\Indexer\FileInfo
 * @property-read $cacheKey string
 * @property-read $name string
 * @property-read $supportsThumbnail bool
 * @property-read $isDir bool
 * @property-read $mimeType string|null
 * @property-read $type string
 * @property-read $faIcon string
 * @property-read $size int
 * @property-read $timeLastModified int
 * @property-read $timeLastAccessed int
 * @property-read $timeCreated int
 * @property-read $hashMD5 string|null
 * @property-read $hashSHA1 string|null
 * @property-read $hashSHA256 string|null
 * @property-read $isHidden bool
 */
class MetaData
{
    private string $cacheKey;

    private string $name;

    private bool $supportsThumbnail;
    private bool $isDir;

    private ?string $mimeType;
    private string $type;
    private string $faIcon;

    private int $size;
    private int $timeLastModified;
    private int $timeLastAccessed;
    private int $timeCreated;

    private ?string $hashMD5;
    private ?string $hashSHA1;
    private ?string $hashSHA256;

    private bool $isHidden;

    private function __construct(string $cacheKey, string $name, bool $supportsThumbnail, bool $isDir, ?string $mimeType, string $type, string $faIcon, int $size, int $timeLastModified, int $timeLastAccessed, int $timeCreated, ?string $hashMD5, ?string $hashSHA1, ?string $hashSHA256, bool $isHidden)
    {
        $this->cacheKey = $cacheKey;

        $this->name = $name;

        $this->supportsThumbnail = $supportsThumbnail;
        $this->isDir = $isDir;

        $this->mimeType = $mimeType;
        $this->type = $type;
        $this->faIcon = $faIcon;

        $this->size = $size;
        $this->timeLastModified = $timeLastModified;
        $this->timeLastAccessed = $timeLastAccessed;
        $this->timeCreated = $timeCreated;

        $this->hashMD5 = $hashMD5;
        $this->hashSHA1 = $hashSHA1;
        $this->hashSHA256 = $hashSHA256;

        $this->isHidden = $isHidden;
    }

    /**
     * @param Storage\Disk $storage
     * @param Cache $cache
     * @return MetaData|null
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    public static function fromCache(Storage\Disk $storage, Cache $cache): ?MetaData
    {
        $cacheKey = static::buildCacheKey($storage);
        $cacheItem = $cache->getItem($cacheKey);
        if (!$cacheItem->isHit()) {
            return null;
        }

        return $cacheItem->get();
    }

    /**
     * @param Storage\Disk $storage
     * @param Directory $rootDir
     * @param Config $config
     * @return MetaData
     * @throws AccessDeniedException
     * @throws ConstraintsException
     * @throws Exception
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    public static function fromStorage(Storage\Disk $storage, Directory $rootDir, Config $config): MetaData
    {
        $isDir = $storage->isDir();
        $pathIgnore = new PathIgnore($rootDir, $config);

        [$type, $icon, $mime] = static::typeFromStorage($storage, $rootDir);

        return new self(
            static::buildCacheKey($storage),
            $isDir ? $storage->path()->basename : $storage->path()->filename,
            static::canHasThumbnail($storage),
            $isDir,
            $mime,
            $type,
            $icon,
            $isDir ? (new Directory($storage, $rootDir->storage()->getConstraints()))->getSize() : $storage->getSize(),
            $storage->getTime(Time::LAST_MODIFIED),
            $storage->getTime(Time::LAST_ACCESSED),
            $storage->getTime(Time::CREATED),
            $isDir ? null : $storage->getFileHash(Hash::CONTENT, 'md5'),
            $isDir ? null : $storage->getFileHash(Hash::CONTENT, 'sha1'),
            $isDir ? null : $storage->getFileHash(Hash::CONTENT, 'sha256'),
            $pathIgnore->isHiddenStorage($storage),
        );
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

    /**
     * @param Cache $cache
     * @return bool
     */
    public function saveToCache(Cache $cache): bool
    {
        $cacheItem = $cache->getItem($this->cacheKey);
        $cacheItem->set($this);
        $cacheItem->expiresAfter(FileInfo::CACHE_DURATION);
        return $cache->save($cacheItem);
    }

    public function asArray(): array
    {
        return get_object_vars($this);
    }

    public function __get(string $name)
    {
        return $this->$name ?? null;
    }

    /**
     * @param $name
     * @param $value
     * @throws \RuntimeException
     */
    public function __set($name, $value)
    {
        throw new \RuntimeException("Setting files metadata attributes ('{$name}') as class-properties is only support in the constructor.", 500);
    }

    public function __isset(string $name)
    {
        return isset($this->$name);
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

        if ($storage->path()->real === $rootDir->path()->real) {
            return ['directory', 'fas fa-crown', null];
        }

        $filename = strtolower($storage->isDir() ? $storage->path()->basename : $storage->path()->filename);
        $extension = strtolower(pathinfo($storage->path()->real, PATHINFO_EXTENSION));
        $isFile = !$storage->isDir();
        $mimeType = $isFile ? strtolower($storage->getFileType()) : null;

        switch (true) {
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
}
