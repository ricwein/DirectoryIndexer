<?php

namespace ricwein\Indexer\Indexer\CachedInfos;

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

class FilePreview extends BaseInfo
{
    private const THUMBNAIL_WIDTH = 32;
    private const THUMBNAIL_HEIGHT = 32;

    /**
     * @return File\Image|null
     * @throws AccessDeniedException
     * @throws Exception
     * @throws UnsupportedException
     */
    public function getPreview(): ?File\Image
    {
        $fileType = static::guessFileType($this->storage);
        if ($fileType !== 'image') {
            return null;
        }

        if ($this->cache === null) {
            return $this->buildThumbnail();
        }

        $cacheKey = static::buildCacheKey($this->storage);
        $cacheItem = $this->cache->getItem($cacheKey);

        if ($cacheItem->isHit()) {
            return new File\Image(new Storage\Memory($cacheItem->get()));
        }

        $thumbnail = $this->buildThumbnail();
        $cacheItem->set($thumbnail->read());
        $cacheItem->expiresAfter(365 * 24 * 60 * 60);
        $this->cache->save($cacheItem);

        return $thumbnail;
    }

    protected static function buildCacheKey(Storage $storage): string
    {
        return sprintf('%s|%dx%d', parent::buildCacheKey($storage), static::THUMBNAIL_WIDTH, static::THUMBNAIL_HEIGHT);
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
        $image = new File\Image(new Storage\Memory($this->storage->readFile()));
        $image->resizeToFit(static::THUMBNAIL_WIDTH, static::THUMBNAIL_HEIGHT, false);
        return $image;
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
            if ($this->storage->path()->real === (new Path([__DIR__, '/../../../']))->real) {
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
                return 'cods';

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
