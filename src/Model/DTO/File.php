<?php

namespace App\Model\DTO;

use JsonSerializable;
use ricwein\FileSystem\Directory;
use ricwein\FileSystem\Enum\Hash;
use ricwein\FileSystem\Enum\Time;
use ricwein\FileSystem\File as FileSystemFile;
use ricwein\FileSystem\FileSystem;
use ricwein\FileSystem\Helper\MimeType;
use UnexpectedValueException;

readonly class File implements JsonSerializable
{

    public function __construct(public FileSystem $file) {}

    public function jsonSerialize(): array
    {
        $data = [
            'id' => $this->file->getHash(Hash::FILEPATH, 'sha1'),
            'filename' => $this->file->getPath()->getFilename(),
            'mTime' => $this->file->getTime(Time::LAST_MODIFIED),
            'aTime' => $this->file->getTime(Time::LAST_ACCESSED),
            'cTime' => $this->file->getTime(Time::CREATED),
        ];

        if ($this->file instanceof Directory) {
            return [
                ...$data,
                'type' => 'dir',
                'mime' => null,
                'fileType' => null,
            ];
        }

        if ($this->file instanceof FileSystemFile) {
            $mimeType = $this->file->getType();
            return [
                ...$data,
                'type' => 'file',
                'mime' => $mimeType,
                'fileType' => $mimeType !== null ? match (true) {
                    MimeType::isImage($mimeType) => 'image',
                    default => MimeType::getExtensionFor($mimeType),
                } : null,
            ];
        }

        throw new UnexpectedValueException("Invalid FileSystem Object given.", 500);
    }
}
