<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;
use Twig\TwigTest;

class AppExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('flip', 'array_flip'),
            new TwigFilter('unique', 'array_unique'),
            new TwigFilter('debug_type', 'get_debug_type'),

            new TwigFilter('formatFileSize', [FileRuntime::class, 'formatFileSize']),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('flip', 'array_flip'),
            new TwigFunction('debug_type', 'get_debug_type'),

            new TwigFunction('formatFileSize', [FileRuntime::class, 'formatFileSize']),
        ];
    }

    public function getTests(): array
    {
        return [
            new TwigTest('numeric', fn(mixed $value) => is_numeric($value)),
            new TwigTest('string', fn(mixed $value) => is_string($value)),
            new TwigTest('object', fn(mixed $value) => is_object($value)),
            new TwigTest('array', fn(mixed $value) => is_array($value)),
            new TwigTest('bool', fn(mixed $value) => is_bool($value)),
            new TwigTest('scalar', fn(mixed $value) => is_scalar($value)),
        ];
    }
}
