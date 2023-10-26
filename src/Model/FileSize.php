<?php

namespace App\Model;

class FileSize
{
    private int $bytes;
    private float $factor;
    private ?string $unit;

    private const MEMORY_UNITS = [
        0 => ['B', 'b'],
        10 => ['kB', 'kb', 'k'],
        20 => ['MB', 'mb', 'm'],
        30 => ['GB', 'gb', 'g'],
        40 => ['TB', 'tb', 't'],
        50 => ['PB', 'pb', 'p'],
        60 => ['EB', 'eb', 'e'],
        70 => ['ZB', 'zb', 'z'],
        80 => ['YB', 'yb', 'y']
    ];

    public static function from(null|false|string|int $size): ?self
    {
        return ($size === null || $size === false) ? null : new self($size);
    }

    public function __construct(string|int $size)
    {
        $this->bytes = (int)$size;

        $this->factor = floor((strlen((string)($this->bytes ?? 0)) - 1) / 3);

        $units = self::MEMORY_UNITS[(int)($this->factor * 10)];
        $this->unit = reset($units);
    }

    public function getNumber(int $decimals = 2): string
    {
        return sprintf("%.{$decimals}f ", ($this->bytes ?? 0) / (1024 ** $this->factor));
    }

    public function getUnit(): string
    {
        return $this->unit;
    }

    public function __toString(): string
    {
        return "{$this->getNumber()} {$this->getUnit()}";
    }
}
