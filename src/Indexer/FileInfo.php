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
        $fileType = static::guessFileType($this->storage);
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
            });
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

        if ($this->storage instanceof Storage\Disk) {
            if ($this->storage->path()->real === (new Path([__DIR__, '/../../']))->real) {
                return 'fas fa-crown';
            }

            $filename = strtolower($this->storage->path()->filename);
            switch ($filename) {
                case '.git':
                    return 'fas fa-code-branch';

                case '.gitlab-ci.yml':
                    return 'fab fa-gitlab';

                case 'dockerfile':
                case 'docker-compose.yml':
                case 'docker-compose.yaml':
                    return 'fab fa-docker';
            }

            if ($this->storage->isDir()) {
                $extension = strtolower(pathinfo($this->storage->path()->real, PATHINFO_EXTENSION));
                switch ($extension) {
                    case 'xcworkspace':
                    case 'xcodeproj':
                        return 'fab fa-apple';
                }
                return 'fas fa-folder';
            }

        }

        $fileType = static::guessFileType($this->storage);
        switch ($fileType) {
            case 'image':
                return 'far fa-file-image';

            case 'video':
                return 'far fa-file-video';

            case 'audio':
                return 'far fa-file-audio';

            case 'word':
                return 'far fa-file-word';

            case 'text':
                return 'far fa-file-alt';

            case 'powerpoint':
                return 'far fa-file-powerpoint';

            case 'excel':
                return 'far fa-file-excel';

            case 'pdf':
                return 'far fa-file-pdf';

            case 'code':
                return 'far fa-file-code';

            case 'archive':
                return 'far fa-file-archive';

            case 'unknown':
                return 'far fa-file';
        }

        return $fileType;
    }


    private static function guessFileType(Storage $storage): string
    {
        if (!$storage instanceof Storage\Disk) {
            return 'unknown';
        }

        $extension = strtolower($storage->path()->extension);

        switch ($extension) {
            case 'png':
            case 'jpg':
            case 'jpeg':
            case 'tiff':
            case 'bmp':
            case 'gif':
            case 'ico':
            case 'svg':
                return 'image';

            case 'mpeg':
            case 'mp4':
            case 'mov':
                return 'video';

            case 'mp3':
                return 'audio';

            case 'txt':
            case 'rtf':
            case 'md':
                return 'text';

            case 'doc':
            case 'docx':
            case 'md':
                return 'word';

            case 'xls':
            case 'xlsx':
                return 'excel';

            case 'pptm':
            case 'potm':
            case 'ppsm':
            case 'pps':
            case 'ppsx':
            case 'odp':
            case 'ppt':
            case 'pptx':
            case 'pot':
            case 'potx':
                return 'powerpoint';

            case 'pdf':
                return 'pdf';

            case 'php':
            case 'js':
            case 'map':
            case 'c':
            case 'cpp':
            case 'h':
            case 'hpp':
            case 'java':
            case 'rb':
            case 'py':
            case 'html':
            case 'htm':
            case 'css':
            case 'scss':
            case 'sass':
                return 'code';

            case 'zip':
            case 'rar':
            case 'xip':
            case '7z':
            case 'tar':
            case 'gz':
                return 'archive';
        }

        return 'unknown';
    }
}
