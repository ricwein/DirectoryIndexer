<?php


namespace ricwein\directoryindex\Core;

use Phpfastcache\Exceptions\PhpfastcacheInvalidArgumentException;
use ricwein\DirectoryIndex\Config\Config;
use ricwein\FileSystem\Directory;
use ricwein\FileSystem\Exceptions\AccessDeniedException;
use ricwein\FileSystem\Exceptions\ConstraintsException;
use ricwein\FileSystem\Exceptions\FileNotFoundException;
use ricwein\FileSystem\Exceptions\RuntimeException;
use ricwein\FileSystem\File;
use ricwein\FileSystem\Storage;
use UnexpectedValueException;

class PathIgnore
{
    public const FILEIGNORE_FILENAME = '.indexignore';

    /** @var string File access is denied AND hidden */
    public const TYPE_FORBIDDEN = 'deny';

    /** @var string File access is allowed BUT hidden */
    public const TYPE_HIDDEN = 'hide';

    /** @var string File access is denied BUT shown */
    public const TYPE_SHOW = 'show';

    /** @var string File access is allowed AND shown */
    public const TYPE_ALLOW = 'allow';

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
     * @throws AccessDeniedException
     * @throws ConstraintsException
     * @throws FileNotFoundException
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

        $rules = [];

        /** @var File $file */
        foreach ($ignoreFiles as $file) {

            /** @var array<string, string> $rule */
            $rule = json_decode($file->read(), true, 512, JSON_THROW_ON_ERROR);

            // parse rules
            foreach ($rule as $path => $type) {

                $type = strtolower(trim($type));

                if (!in_array($type, [static::TYPE_ALLOW, static::TYPE_HIDDEN, static::TYPE_FORBIDDEN, static::TYPE_SHOW], true)) {
                    throw new UnexpectedValueException(sprintf(
                        "Invalid attribute '%s' for path '%s'. Unable to parse %s.",
                        $type,
                        $path,
                        $file->path()->real,
                    ), 500);
                }

                $path = trim($path);
                $pathRegex = str_replace('/', '\\/', $file->path()->directory);

                if ($path === '/') {
                    $rules["/{$pathRegex}(.*)/"] = $type;
                    continue;
                }


                $regex = str_replace([
                    '/', '.', '/.', '*', '(.*)(.*)'
                ], [
                    '\\/', '\\.', '/\\.', '(.*)', '(.*)'
                ], $path);

                if (strpos($path, '/') !== 0) {
                    $regex = "(.*)\/{$regex}";
                    $rules["/{$pathRegex}\/{$regex}\/(.*)/"] = $type;
                    $rules["/{$pathRegex}\/{$regex}/"] = $type;
                    continue;
                }

                $rules["/{$pathRegex}{$regex}/"] = $type;
            }
        }

        return $rules;
    }

    public function isHidden(Storage\Disk $storage): bool
    {
        $rules = $this->getMatchingAttributes($storage);
        if (count($rules) < 1) {
            return false;
        }

        $firstRule = array_shift($rules);
        return in_array($firstRule, [static::TYPE_HIDDEN, static::TYPE_FORBIDDEN], true);
    }

    public function isForbidden(Storage\Disk $storage): bool
    {
        $rules = $this->getMatchingAttributes($storage);
        if (count($rules) < 1) {
            return false;
        }

        $firstRule = array_shift($rules);
        return $firstRule === static::TYPE_FORBIDDEN;
    }

    private function getMatchingAttributes(Storage\Disk $storage): array
    {
        $rules = $this->fetchRules($storage);
        $path = $storage->path()->real;

        $matchingRules = [];
        foreach ($rules as $regex => $type) {
            if (preg_match($regex, $path, $matches) === 1) {
                $matchingRules[] = $type;
            }
        }

        return $matchingRules;
    }
}
