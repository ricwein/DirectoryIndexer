<?php

namespace App\Model;

use ricwein\FileSystem\Enum\Hash;
use ricwein\FileSystem\FileSystem;

readonly class FileInfo
{
    public string $id;

    public function __construct(
        public FileSystem $file,
    ) {
        $this->id = $this->file->getHash(Hash::FILEPATH, 'sha1');
    }
}
