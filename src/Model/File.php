<?php

namespace App\Model;

use finfo;
use Symfony\Component\Finder\SplFileInfo;

class File
{
    public function __construct(
        private readonly string      $basePath,
        private readonly SplFileInfo $file,
    )
    {
    }

    public function getFile(): SplFileInfo
    {
        return $this->file;
    }

    public function getRelativePath(): string
    {
        return ltrim(str_replace(rtrim($this->basePath, '/'), '', $this->file->getRealPath()), '/');
    }

    public function getFilenameHash(): string
    {
        return hash('sha1', $this->getRelativePath());
    }

    public function getMimeType(bool $withEncoding = false): string
    {
        $fileInfo = new finfo($withEncoding ? FILEINFO_MIME : FILEINFO_MIME_TYPE);
        $type = $fileInfo->file($this->file->getPathname());

        if (!in_array($type, [false, 'text/plain', 'application/octet-stream', 'inode/x-empty'], true)) {
            return $type;
        }

        // detect mimetype by file-extension
        return $this->file->getExtension();
    }

    public function getFileType(): FileTypeEnum
    {
        if (in_array(strtolower($this->file->getFilename()), [
            'dockerfile',
            '.dockerignore',
            'docker-compose.yaml',
            'docker-compose.yml',
            'docker-compose.override.yaml',
            'docker-compose.override.yml',
            'docker-compose.dev.yaml',
            'docker-compose.dev.yml',
            'docker-compose.prod.yaml',
            'docker-compose.prod.yml',
        ], true)) {
            return FileTypeEnum::DOCKER;
        }

        if (in_array(strtolower($this->file->getFilename()), [
            'yarn.lock',
            'package.json',
            'package.lock',
            'composer.json',
            'composer.lock',
            'symfony.lock',
            'webpack.config.js',
            'tailwind.config.js',
            'vendor',
            'node_modules',
        ], true)) {
            return FileTypeEnum::PACKAGE_MANAGER;
        }

        if (str_ends_with(strtolower($this->file->getFilename()), 'test.php')) {
            return FileTypeEnum::TESTS;
        }

        if (
            strtolower($this->file->getFilename()) === 'license'
            || str_starts_with(strtolower($this->file->getFilename()), 'license.')
        ) {
            return FileTypeEnum::LICENSE;
        }

        if (in_array(strtolower($this->file->getFilename()), [
            '.git',
            '.gitignore',
            '.gitattributes',
        ], true)) {
            return FileTypeEnum::GIT;
        }

        if (
            str_starts_with($this->file->getFilename(), 'Caddyfile')
            || in_array($this->file->getFilename(), [
                'nginx.conf',
                'web.config',
                '.htaccess',
                'htaccess.txt',
                'lighttpd.conf',
                'apache.conf'
            ])
        ) {
            return FileTypeEnum::WEBSERVER;
        }

        if (
            !str_contains($this->file->getFilename(), '.')
            && str_contains($this->file->getRealPath(), '/bin/')
        ) {
            return FileTypeEnum::BINARY;
        }

        if (in_array(strtolower($this->file->getExtension()), [
            'config',
            'conf',
            'ini'
        ], true)) {
            return FileTypeEnum::CONFIG;
        }

        $mimeType = $this->getMimeType(false);
        return match (strtolower($mimeType)) {
            'directory' => FileTypeEnum::DIRECTORY,
            'md' => FileTypeEnum::MARKDOWN,
            'jpg', 'jpeg', 'png', 'webp' => FileTypeEnum::IMAGE,
            'text/x-php', 'phar' => FileTypeEnum::PHP,
            'application/json' => FileTypeEnum::JSON,
            'html', 'htm', 'text/html' => FileTypeEnum::HTML,
            'yml', 'yaml' => FileTypeEnum::YAML,
            'txt' => FileTypeEnum::TEXT,
            'js' => FileTypeEnum::JAVASCRIPT,
            'exe', 'msi', 'data' => FileTypeEnum::BINARY,
            'application/pdf', 'pdf', 'rtf' => FileTypeEnum::DOCUMENT,
            'text/xml', 'xml' => FileTypeEnum::XML,
            'sql', 'sqlite', 'db' => FileTypeEnum::SQL,
            'text/x-shellscript', 'sh', 'bash' => FileTypeEnum::SHELLSCRIPT,
//            default => FileTypeEnum::UNKNOWN,
            default => throw new \RuntimeException("Unknown type '$mimeType' for file '{$this->file->getPathname()}'.")
        };
    }

}
