<?php

namespace App\Model\DTO;

use JsonSerializable;
use ricwein\FileSystem\FileSystem;

final readonly class Hashes implements JsonSerializable
{
    public function __construct(
        public string $md5,
        public string $sha1,
        public string $sha256,
        public string $sha512,
    ) {}

    public static function calculate(FileSystem $file): self
    {
        return new self(
            md5: $file->getHash(algo: 'md5'),
            sha1: $file->getHash(algo: 'sha1'),
            sha256: $file->getHash(algo: 'sha256'),
            sha512: $file->getHash(algo: 'sha512'),
        );
    }

    public function jsonSerialize(): array
    {
        return [
            'md5' => $this->md5,
            'sha1' => $this->sha1,
            'sha256' => $this->sha256,
            'sha512' => $this->sha512,
        ];
    }

    public function __serialize(): array
    {
        return $this->jsonSerialize();
    }

    public function __unserialize(array $data): void
    {
        $this->md5 = $data['md5'];
        $this->sha1 = $data['sha1'];
        $this->sha256 = $data['sha256'];
        $this->sha512 = $data['sha512'];
    }
}
