<?php

namespace App\Services;

use SplFileInfo;

class PathHelper
{
    public function __construct(private readonly string $appIndexPath)
    {
    }

    public function loadPath(string $relativePath): SplFileInfo
    {
        $relativePath = str_replace('/../', '/', $relativePath);

        return new SplFileInfo(sprintf(
            '%s/%s',
            rtrim($this->appIndexPath, '/'),
            ltrim($relativePath, '/')
        ));
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
