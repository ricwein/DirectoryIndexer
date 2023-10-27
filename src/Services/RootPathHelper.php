<?php

namespace App\Services;

use SplFileInfo;

readonly class RootPathHelper
{
    private string $appIndexPath;

    public function __construct(string $appIndexPath)
    {
        $this->appIndexPath = rtrim(realpath($appIndexPath), '/') . '/';
    }

    public function normalizeRelativePath(string $path): string
    {
        $relativePath = str_replace(['/..', '../'], '/', $path);
        $relativePath = preg_replace('/\/+/', '/', $relativePath);
        return ltrim($relativePath, '/');
    }

    public function loadPath(string $relativePath): SplFileInfo
    {
        return new SplFileInfo(
            sprintf(
                '%s/%s',
                rtrim($this->appIndexPath, '/'),
                ltrim($this->normalizeRelativePath($relativePath), '/')
            )
        );
    }

    public function escapeFilename(string $filename): string
    {
        $filename = strip_tags($filename);

        $filename = strtr($filename, [
            'Ü' => 'Ue', 'Ü' => 'Ue',
            'Ö' => 'Oe',
            'Ä' => 'Ae',
            'ü' => 'ue',
            'ö' => 'oe',
            'a' => 'ae',
            'ß' => 'ss',
            ' ' => '_'
        ]);

        $filename = preg_replace('/[\r\n\t ]+/', ' ', $filename);
        $filename = preg_replace('/[\"\*\/\:\<\>\?\'\|]+/', ' ', $filename);
        $filename = html_entity_decode($filename, ENT_QUOTES, "utf-8");
        $filename = htmlentities($filename, ENT_QUOTES, "utf-8");
        $filename = preg_replace("/(&)([a-z])([a-z]+;)/i", '$2', $filename);
        $filename = str_replace(' ', '-', $filename);
        $filename = rawurlencode($filename);
        $filename = str_replace('%', '-', $filename);
        return $filename;
    }

}
