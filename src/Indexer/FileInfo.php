<?php

namespace ricwein\Indexer\Indexer;

use Intervention\Image\Constraint as IConstraint;
use Intervention\Image\Image as IImage;
use ricwein\FileSystem\Directory;
use ricwein\FileSystem\Enum\Hash;
use ricwein\FileSystem\Exceptions\AccessDeniedException;
use ricwein\FileSystem\Exceptions\ConstraintsException;
use ricwein\FileSystem\Exceptions\Exception;
use ricwein\FileSystem\Exceptions\FileNotFoundException;
use ricwein\FileSystem\Exceptions\RuntimeException;
use ricwein\FileSystem\Exceptions\UnexpectedValueException;
use ricwein\FileSystem\Exceptions\UnsupportedException;
use ricwein\FileSystem\File;
use ricwein\FileSystem\Helper\Path;
use ricwein\FileSystem\Storage;
use ricwein\Indexer\Core\Cache;
use ricwein\Templater\Config;
use ricwein\Templater\Engine\CoreFunctions;
use ricwein\Templater\Exceptions\UnexpectedValueException as TemplateUnexpectedValueException;

class FileInfo
{
    private const THUMBNAIL_WIDTH = 32;
    private const THUMBNAIL_HEIGHT = 32;
    private const CACHE_DURATION = 365 * 24 * 60 * 60;

    private Storage $storage;
    private ?Cache $cache;
    private int $constraints;

    public function __construct(Storage $storage, ?Cache $cache, int $constraints)
    {
        $this->storage = $storage;
        $this->cache = $cache;
        $this->constraints = $constraints;
    }

    public function isCached(): bool
    {
        $cacheKey = static::buildCacheKey('infoOf', $this->storage);
        $cacheItem = $this->cache->getItem($cacheKey);
        return $cacheItem->isHit();
    }

    /**
     * @return array
     * @throws AccessDeniedException
     * @throws ConstraintsException
     * @throws Exception
     * @throws FileNotFoundException
     * @throws RuntimeException
     * @throws UnexpectedValueException
     * @throws UnsupportedException
     * @throws TemplateUnexpectedValueException
     */
    public function getInfo(): array
    {
        if ($this->cache === null) {
            return $this->fetchInfo();
        }

        $cacheKey = static::buildCacheKey('infoOf', $this->storage);
        $cacheItem = $this->cache->getItem($cacheKey);

        if ($cacheItem->isHit()) {
            return $cacheItem->get();
        }

        $info = $this->fetchInfo();
        $cacheItem->set($info);
        $cacheItem->expiresAfter(static::CACHE_DURATION);
        $this->cache->save($cacheItem);

        return $info;
    }

    /**
     * @param string $prefix
     * @param Storage $storage
     * @return string
     */
    protected static function buildCacheKey(string $prefix, Storage $storage): string
    {
        return str_replace(
            ['{', '}', '(', ')', '/', '\\', '@', ':'],
            ['|', '|', '|', '|', '.', '.', '-', '_'],
            sprintf('%s_%s|%d',
                rtrim($prefix, '_'),
                $storage->path()->real,
                $storage->getTime()
            )
        );
    }

    public function hasThumbnail(): bool
    {
        if (!$this->storage instanceof Storage\Disk) {
            return false;
        }

        $extension = strtolower($this->storage->path()->extension);

        return in_array($extension, [
            'png', 'gif', 'bmp', 'jpg', 'jpeg'
        ], true);
    }

    public function getType(): string
    {
        return static::guessFileType($this->storage);
    }


    /**
     * @return File\Image|null
     * @throws AccessDeniedException
     * @throws Exception
     * @throws UnsupportedException
     */
    public function getPreview(): ?File\Image
    {
        if (!$this->hasThumbnail()) {
            return null;
        }

        if ($this->cache === null) {
            return $this->buildThumbnail();
        }

        $cacheKey = sprintf('%s|%dx%d',
            static::buildCacheKey('thumbnailOf', $this->storage),
            static::THUMBNAIL_WIDTH,
            static::THUMBNAIL_HEIGHT
        );
        $cacheItem = $this->cache->getItem($cacheKey);

        if ($cacheItem->isHit()) {
            return new File\Image(new Storage\Memory($cacheItem->get()));
        }

        $thumbnail = $this->buildThumbnail();
        $cacheItem->set($thumbnail->read());
        $cacheItem->expiresAfter(static::CACHE_DURATION);
        $this->cache->save($cacheItem);

        return $thumbnail;
    }

    /**
     * @return array
     * @throws AccessDeniedException
     * @throws ConstraintsException
     * @throws Exception
     * @throws FileNotFoundException
     * @throws RuntimeException
     * @throws UnexpectedValueException
     * @throws UnsupportedException
     * @throws TemplateUnexpectedValueException
     */
    private function fetchInfo(): array
    {
        if ($this->storage->isDir()) {
            $dir = new Directory($this->storage, $this->constraints);
            $size = $dir->getSize();

            return [
                'type' => 'dir',
                'filename' => $dir->path()->basename,
                'size' => [
                    'bytes' => $size,
                    'hr' => (new CoreFunctions(new Config([])))->formatBytes($size, 1),
                ],
            ];
        }

        $file = new File($this->storage, $this->constraints);
        $size = $file->getSize();

        return [
            'type' => 'file',
            'filename' => $file->path()->filename,
            'size' => [
                'bytes' => $size,
                'hr' => (new CoreFunctions(new Config([])))->formatBytes($size, 1),
            ],
            'hash' => [
                'md5' => $file->getHash(Hash::CONTENT, 'md5'),
                'sha1' => $file->getHash(Hash::CONTENT, 'sha1'),
                'sha256' => $file->getHash(Hash::CONTENT, 'sha256'),
            ],
        ];
    }

    /**
     * @return File\Image
     * @throws AccessDeniedException
     * @throws ConstraintsException
     * @throws Exception
     * @throws FileNotFoundException
     * @throws RuntimeException
     * @throws UnexpectedValueException
     * @throws UnsupportedException
     */
    private function buildThumbnail(): File\Image
    {
        $file = new File\Image(new Storage\Memory($this->storage->readFile()));
        $file->edit(static function (IImage $image): IImage {
            return $image->fit(static::THUMBNAIL_WIDTH, static::THUMBNAIL_HEIGHT, static function (IConstraint $constraint) {
                $constraint->upsize();
            })->encode('jpg', 90);
        });
        return $file;
    }

    /**
     * @return string
     * @throws UnexpectedValueException
     * @throws RuntimeException
     */
    public function getIconName(): string
    {
        if ($this->storage->isSymlink()) {
            return 'fas fa-external-link-alt';
        }

        if (!$this->storage instanceof Storage\Disk) {
            return $this->storage->isDir() ? 'fas fa-folder' : 'far fa-file';
        }

        if ($this->storage->path()->real === (new Path([__DIR__, '/../../']))->real) {
            return 'fas fa-crown';
        }

        $filename = strtolower($this->storage->isDir() ? $this->storage->path()->basename : $this->storage->path()->filename);
        $extension = strtolower(pathinfo($this->storage->path()->real, PATHINFO_EXTENSION));

        switch (true) {
            // known files (full names)
            case in_array($filename, ['.gitignore', '.dockerignore', '.indexignore'], true):
                return 'far fa-eye-slash';

            case strpos($filename, '.gitlab') === 0:
                return 'fab fa-gitlab';

            case strpos($filename, '.github') === 0:
                return 'fab fa-github';

            case strpos($filename, '.git') === 0:
                return $this->storage->isDir() ? 'fab fa-git-square' : 'fab fa-git';

            case $filename === '.editorconfig':
                return 'fas fa-cog';

            case in_array($filename, ['license', 'license.txt', 'license.md'], true):
                return 'far fa-id-badge';

            case $extension === 'pdf':
                return 'far fa-file-pdf';

            case in_array($filename, ['dockerfile', 'docker-compose.yml'], true):
            case $extension === 'dockerfile':
            case strpos($filename, 'docker-compose.') === 0:
                return 'fab fa-docker';

            case in_array($filename, ['composer.json', 'composer.lock', 'podfile', 'podfile.lock',], true):
            case strpos($filename, 'package.') === 0:
            case $extension === 'gradle':
                return 'fas fa-cube';

            case in_array($filename, ['node_modules', 'vendor'], true):
                return 'fas fa-cubes';

            // extensions
            case in_array($extension, ['ini', 'conf', 'plist'], true) :
                return 'fas fa-cogs';

            case in_array($extension, ['xcworkspace', 'xcodeproj'], true):
                return 'fab fa-app-store-ios';

            case in_array($extension, ['png', 'jpg', 'jpeg', 'tiff', 'bmp', 'gif', 'ico', 'svg'], true):
                return 'far fa-file-image';

            case in_array($extension, ['mpeg', 'mp4', 'mov', 'mkv'], true):
                return 'far fa-file-video';

            case in_array($extension, ['mp3', 'acc', 'wav'], true):
                return 'far fa-file-audio';

            case in_array($extension, ['txt', 'rtf', 'md'], true):
                return 'far fa-file-alt';

            case in_array($extension, ['doc', 'docx'], true):
                return 'far fa-file-word';

            case in_array($extension, ['xls', 'xlsx'], true):
                return 'far fa-file-excel';

            case in_array($extension, ['pptm', 'potm', 'ppsm', 'pps', 'ppsx', 'odp', 'ppt', 'pptx', 'pot', 'potx'], true):
                return 'far fa-file-powerpoint';

            case in_array($extension, ['sql', 'sqlite'], true):
                return 'fas fa-database';

            case in_array($extension, ['blade', 'twig'], true):
                return 'fas fa-leaf';

            case in_array($extension, ['php', 'sh', 'zsh', 'js', 'map', 'm', 'c', 'cpp', 'h', 'hpp', 'swift', 'java', 'rb', 'py', 'html', 'htm', 'xml', 'css', 'scss', 'sass', 'json', 'yml', 'yaml'], true):
                return 'far fa-file-code';

            case in_array($extension, ['zip', 'rar', 'xip', '7z', 'tar', 'gz'], true):
                return 'far fa-file-archive';
        }

        return $this->storage->isDir() ? 'fas fa-folder' : 'far fa-file';
    }
}
