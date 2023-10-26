<?php

namespace App\Twig;

use App\Model\FileSize;
use Twig\Extension\RuntimeExtensionInterface;

class FileRuntime implements RuntimeExtensionInterface
{
    public function formatFileSize(null|int|string|false $bytes): ?FileSize
    {
        return FileSize::from($bytes);
    }
}
