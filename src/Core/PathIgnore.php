<?php


namespace ricwein\directoryindex\Core;

use Phpfastcache\Exceptions\PhpfastcacheInvalidArgumentException;
use ricwein\DirectoryIndex\Config\Config;
use ricwein\FileSystem\Directory;
use ricwein\FileSystem\Exceptions\AccessDeniedException;
use ricwein\FileSystem\Exceptions\ConstraintsException;
use ricwein\FileSystem\Exceptions\FileNotFoundException;
use ricwein\FileSystem\Exceptions\RuntimeException;
use ricwein\FileSystem\Exceptions\UnexpectedValueException as FileSystemUnexpectedValueException;
use ricwein\FileSystem\File;
use ricwein\FileSystem\Storage;
use UnexpectedValueException;

class PathIgnore
{
    public const FILEIGNORE_FILENAME = '.indexignore';

    private const ATTRIBUTE_VALUES = [
        self::ATTRIBUTE_VISIBILITY_HIDE, self::ATTRIBUTE_VISIBILITY_SHOW,
        self::ATTRIBUTE_ACCESS_DENY, self::ATTRIBUTE_ACCESS_ALLOW
    ];

    public const ATTRIBUTE_VISIBILITY_HIDE = 'hide';
    public const ATTRIBUTE_VISIBILITY_SHOW = 'show';
    private const ATTRIBUTE_VISIBILITY = [
        'name' => 'visible',
        'values' => [self::ATTRIBUTE_VISIBILITY_HIDE => -1, self::ATTRIBUTE_VISIBILITY_SHOW => +1]
    ];

    public const ATTRIBUTE_ACCESS_DENY = 'deny';
    public const ATTRIBUTE_ACCESS_ALLOW = 'allow';
    private const ATTRIBUTE_ACCESS = [
        'name' => 'access',
        'values' => [self::ATTRIBUTE_ACCESS_DENY => -1, self::ATTRIBUTE_ACCESS_ALLOW => +1]
    ];

    private ?Cache $cache;
    private Directory $rootDir;
    private Config $config;

    /**
     * PathIgnore constructor.
     * @param Directory $rootDir
     * @param Config $config
     * @param Cache|null $cache
     */
    public function __construct(Directory $rootDir, Config $config, ?Cache $cache)
    {
        $this->rootDir = $rootDir;
        $this->config = $config;
        $this->cache = $cache;
    }

    /**
     * @param Storage\Disk $storage Directory
     * @return array<string, string>
     * @throws AccessDeniedException
     * @throws ConstraintsException
     * @throws FileNotFoundException
     * @throws PhpfastcacheInvalidArgumentException
     * @throws RuntimeException
     */
    private function fetchRules(Storage\Disk $storage): array
    {
        if ($this->cache === null) {
            return $this->parseIndexIgnoreFiles($storage);
        }

        $cacheKey = str_replace(
            ['{', '}', '(', ')', '/', '\\', '@', ':'],
            ['|', '|', '|', '|', '.', '.', '-', '_'],
            sprintf('indexignore_rules_%s|%d|%d',
                $storage->path()->real,
                $this->rootDir->getTime(),
                $this->config->development ? 1 : 0,
            )
        );

        $ruleCacheItem = $this->cache->getItem($cacheKey);

        if ($ruleCacheItem->isHit()) {
            return $ruleCacheItem->get();
        }

        $rules = $this->parseIndexIgnoreFiles($storage);

        $ruleCacheItem->set($rules);
        $ruleCacheItem->expiresAfter($this->config->cache['ttl']);
        $this->cache->save($ruleCacheItem);

        return $rules;
    }

    /**
     * @param Storage\Disk $storage Directory
     * @return array
     * @throws RuntimeException
     */
    private function parseIndexIgnoreFiles(Storage\Disk $storage): array
    {
        // fetch all directories between root and current
        $intermediateDirs = explode('/', str_replace(
            $this->rootDir->path()->real,
            '',
            $storage->path()->real
        ));

        $ignoreFiles = array_map(function (string $dir): File {
            return (new Directory(new Storage\Disk($this->rootDir->path()->real, $dir)))->file(static::FILEIGNORE_FILENAME);
        }, $intermediateDirs);

        $ignoreFiles = array_filter($ignoreFiles, static function (File $file): bool {
            return $file->isFile() && $file->isReadable();
        });

        $rulesets = [];
        if (null !== $defaultIgnore = $this->config->defaultIndexIgnore) {
            $rulesets[] = ['file' => null, 'rules' => $defaultIgnore];
        }

        /** @var array<string, string>[] $rulesets */
        $rulesets = array_merge($rulesets, array_map(static function (File $file): array {
            return [
                'file' => $file,
                'rules' => json_decode($file->read(), true, 512, JSON_THROW_ON_ERROR)
            ];
        }, $ignoreFiles));

        $rules = [];

        /** @var array<string, string> $rule */
        foreach ($rulesets as $ruleset) {

            // parse rules
            foreach ($ruleset['rules'] as $pattern => $type) {

                $type = strtolower(trim($type));

                if (!in_array($type, static::ATTRIBUTE_VALUES, true)) {
                    throw new UnexpectedValueException(sprintf(
                        "Invalid attribute '%s' for path '%s'. Unable to parse %s.",
                        $type,
                        $pattern,
                        $ruleset['file'] !== null ? $ruleset['file']->path()->real : '[default indexignore]',
                    ), 500);
                }

                $pattern = trim($pattern);
                $path = $ruleset['file'] !== null ? $ruleset['file']->path()->directory : null;

                if (strpos($pattern, '/') === 0) {
                    $rules["{$path}{$pattern}"] = $type;
                } else {
                    $rules["{$path}*/{$pattern}"] = $type;
                }

            }
        }

        return $rules;
    }

    /**
     * @param Storage\Disk $storage
     * @return bool
     * @throws AccessDeniedException
     * @throws ConstraintsException
     * @throws FileNotFoundException
     * @throws PhpfastcacheInvalidArgumentException
     * @throws RuntimeException
     * @throws FileSystemUnexpectedValueException
     */
    public function isHidden(Storage\Disk $storage): bool
    {
        $rules = $this->getMatchingAttributes($storage);
        return $rules[static::ATTRIBUTE_VISIBILITY['name']] < 0 || $rules[static::ATTRIBUTE_ACCESS['name']] < 0;
    }

    /**
     * @param Storage\Disk $storage
     * @return bool
     * @throws AccessDeniedException
     * @throws ConstraintsException
     * @throws FileNotFoundException
     * @throws FileSystemUnexpectedValueException
     * @throws PhpfastcacheInvalidArgumentException
     * @throws RuntimeException
     */
    public function isForbidden(Storage\Disk $storage): bool
    {
        $rules = $this->getMatchingAttributes($storage);
        return $rules[static::ATTRIBUTE_ACCESS['name']] < 0;
    }

    /**
     * @param Storage\Disk $storage
     * @return array
     * @throws AccessDeniedException
     * @throws ConstraintsException
     * @throws FileNotFoundException
     * @throws PhpfastcacheInvalidArgumentException
     * @throws RuntimeException
     * @throws FileSystemUnexpectedValueException
     */
    private function getMatchingAttributes(Storage\Disk $storage): array
    {
        $rules = $this->fetchRules($storage);
        $path = $storage->path()->real;
        if ($storage->isDir()) {
            $path = "{$path}/";
        }

        $matchingRules = [];
        foreach ([static::ATTRIBUTE_ACCESS['name'], static::ATTRIBUTE_VISIBILITY['name']] as $type) {
            $matchingRules[$type] = 0;
        }

        $counter = 0;
        foreach ($rules as $pattern => $type) {

            ++$counter;

            if (!fnmatch($pattern, $path)) {
                continue;
            }

            $priority = $counter;

            switch (true) {
                case array_key_exists($type, static::ATTRIBUTE_ACCESS['values']):
                    $matchingRules[static::ATTRIBUTE_ACCESS['name']] += ($priority * static::ATTRIBUTE_ACCESS['values'][$type]);
                    break;

                case array_key_exists($type, static::ATTRIBUTE_VISIBILITY['values']):
                    $matchingRules[static::ATTRIBUTE_VISIBILITY['name']] += ($priority * static::ATTRIBUTE_VISIBILITY['values'][$type]);
                    break;
            }
        }


        return $matchingRules;
    }
}
