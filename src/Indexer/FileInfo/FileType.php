<?php

namespace ricwein\Indexer\Indexer\FileInfo;

use ricwein\FileSystem\Exceptions\RuntimeException;
use ricwein\FileSystem\Exceptions\UnexpectedValueException;
use ricwein\FileSystem\Helper\Path;
use ricwein\FileSystem\Storage;

class FileType
{
    private ?string $mimeType;
    private string $type;
    private string $faIcon;

    private static ?string $rootDir = null;

    public function __construct(string $type, string $faIcon, ?string $mimeType = null)
    {
        $this->mimeType = $mimeType;
        $this->type = $type;
        $this->faIcon = $faIcon;
    }

    /**
     * @param Storage $storage
     * @return static
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    public static function fromStorage(Storage $storage): self
    {
        if ($storage->isSymlink()) {
            return new static('symlink', 'fas fa-external-link-alt');
        }

        if (!$storage instanceof Storage\Disk) {
            if ($storage->isDir()) {
                return new static('directory', 'fas fa-folder');
            }
            return new static('file', 'far fa-file', $storage->getFileType());
        }

        if (static::$rootDir === null) {
            static::$rootDir = (new Path([__DIR__, '/../../../']))->real;
        }

        if ($storage->path()->real === static::$rootDir) {
            return new static('directory', 'fas fa-crown');
        }

        $filename = strtolower($storage->isDir() ? $storage->path()->basename : $storage->path()->filename);
        $extension = strtolower(pathinfo($storage->path()->real, PATHINFO_EXTENSION));
        $isFile = !$storage->isDir();
        $mimeType = $isFile ? strtolower($storage->getFileType()) : null;

        switch (true) {
            // known files (full names)
            case in_array($filename, ['.gitignore', '.dockerignore', '.indexignore'], true):
                return new static($isFile ? 'ignore' : 'directory', 'far fa-eye-slash', $mimeType);

            case strpos($filename, '.gitlab') === 0:
                if ($isFile) {
                    return new static('GitLab file', 'fab fa-gitlab', $mimeType);
                }
                return new static('GitLab config', 'fab fa-gitlab');

            case strpos($filename, '.github') === 0:
                if ($isFile) {
                    return new static('GitHub file', 'fab fa-github', $mimeType);
                }
                return new static('GitHub config', 'fab fa-github');

            case strpos($filename, '.git') === 0:
                if ($isFile) {
                    return new static('git config', 'fab fa-git', $mimeType);
                }
                return new static('git repo', 'fab fa-git-square');

            case $isFile && $filename === '.editorconfig':
                return new static('editorconfig', 'fas fa-cog', $mimeType);

            case $isFile && in_array($filename, ['license', 'license.txt', 'license.md'], true):
                return new static('license', 'far fa-id-badge', $mimeType);

            case $isFile && $extension === 'pdf':
                return new static('document', 'far fa-file-pdf', $mimeType);

            case $isFile && in_array($filename, ['dockerfile', 'docker-compose.yml'], true):
            case $isFile && $extension === 'dockerfile':
            case $isFile && strpos($filename, 'docker-compose.') === 0:
                return new static('docker config', 'fab fa-docker', $mimeType);

            case $isFile && in_array($filename, ['composer.json', 'composer.lock', 'podfile', 'podfile.lock',], true):
            case $isFile && strpos($filename, 'package.') === 0:
            case $isFile && $extension === 'gradle':
                return new static('package manager config', 'fas fa-cube', $mimeType);

            case !$isFile && in_array($filename, ['node_modules', 'vendor', 'pods'], true):
                return new static('libraries', 'fas fa-cubes');

            // extensions and mime-types
            case $isFile && in_array($extension, ['ini', 'conf', 'plist'], true) :
                return new static('config', 'fas fa-cogs', $mimeType);

            case $isFile && in_array($extension, ['dmg', 'iso', 'ccd', 'cue', 'vmdk', 'hds', 'cdr', 'vcd', 'disk'], true) :
                return new static('disk-image', 'fas fa-compact-disc', $mimeType);

            case !$isFile && in_array($extension, ['xcworkspace', 'xcodeproj'], true):
                return new static('Xcode project', 'fab fa-app-store-ios');

            case strpos($mimeType, 'model/') === 0:
                return new static('3D model', 'fas fa-dice-d6', $mimeType);

            case strpos($mimeType, 'font/') === 0:
                return new static('font', 'fas fa-font', $mimeType);

            case strpos($mimeType, 'image/') === 0:
            case $isFile && in_array($extension, ['png', 'jpg', 'jpeg', 'tiff', 'bmp', 'gif', 'ico', 'svg'], true):
                return new static('image', 'far fa-file-image', $mimeType);

            case strpos($mimeType, 'video/') === 0:
            case $isFile && in_array($extension, ['mpeg', 'mp4', 'mov', 'mkv'], true):
                return new static('video', 'far fa-file-video', $mimeType);

            case strpos($mimeType, 'audio/') === 0:
            case $isFile && in_array($extension, ['mp3', 'acc', 'wav'], true):
                return new static('audio file', 'far fa-file-audio', $mimeType);

            case $isFile && in_array($extension, ['txt', 'rtf', 'md'], true):
                return new static('text', 'far fa-file-alt', $mimeType);

            case $isFile && in_array($extension, ['doc', 'docx'], true):
                return new static('Word document', 'far fa-file-word', $mimeType);

            case $isFile && in_array($extension, ['xls', 'xlsx'], true):
                return new static('Excel spreadsheet', 'far fa-file-excel', $mimeType);

            case $isFile && in_array($extension, ['pptm', 'potm', 'ppsm', 'pps', 'ppsx', 'odp', 'ppt', 'pptx', 'pot', 'potx'], true):
                return new static('Powerpoint slideshow', 'far fa-file-powerpoint', $mimeType);

            case $isFile && in_array($extension, ['sql', 'sqlite'], true):
                return new static('database', 'fas fa-database', $mimeType);

            case $isFile && in_array($extension, ['blade', 'twig'], true):
                return new static('template', 'fas fa-leaf', $mimeType);

            case $isFile && in_array($extension, ['h', 'hpp', 'h++'], true):
                return new static('header', 'far fa-file-code', $mimeType);

            case $isFile && in_array($extension, ['css', 'scss', 'sass'], true):
                return new static('stylesheet', 'far fa-file-code', $mimeType);

            case $isFile && in_array($extension, ['json', 'yml', 'yaml'], true):
                return new static('config', 'far fa-file-code', $mimeType);

            case $isFile && in_array($extension, ['php', 'sh', 'zsh', 'js', 'map', 'm', 'c', 'cpp', 'swift', 'java', 'rb', 'py', 'html', 'htm', 'xml'], true):
                return new static('sourcecode', 'far fa-file-code', $mimeType);

            case $isFile && in_array($extension, ['zip', 'rar', 'xip', '7z', 'tar', 'gz'], true):
                return new static('archive', 'far fa-file-archive', $mimeType);

        }

        // defaults
        if (!$isFile) {
            return new static('directory', 'fas fa-folder');
        }

        return new static('file', 'far fa-file', $mimeType);
    }

    public function is(string $type): bool
    {
        return strtolower($type) === strtolower(trim($type));
    }

    public function mime(): ?string
    {
        return $this->mimeType;
    }

    public function name(): string
    {
        return $this->type;
    }

    public function icon(): string
    {
        return $this->faIcon;
    }

    public function asArray(): array
    {
        return [
            'mime' => $this->mimeType,
            'faIcon' => $this->faIcon,
            'type' => $this->type,
        ];
    }

    public function __serialize(): array
    {
        return $this->asArray();
    }

    public function __unserialize(array $data): void
    {
        $this->mimeType = $data['mime'];
        $this->faIcon = $data['faIcon'];
        $this->type = $data['type'];
    }


}
