<?php
/**
 * @author Richard Weinhold
 */

namespace ricwein\DirectoryIndex\Core;

use Exception;
use Phpfastcache\CacheManager;
use Phpfastcache\Config\ConfigurationOption;
use Phpfastcache\Core\Item\ExtendedCacheItemInterface;
use Phpfastcache\Core\Pool\ExtendedCacheItemPoolInterface;
use Phpfastcache\Drivers;
use Phpfastcache\Exceptions\PhpfastcacheDriverCheckException;
use Phpfastcache\Exceptions\PhpfastcacheDriverException;
use Phpfastcache\Exceptions\PhpfastcacheDriverNotFoundException;
use Phpfastcache\Exceptions\PhpfastcacheInvalidArgumentException;
use Phpfastcache\Exceptions\PhpfastcacheInvalidConfigurationException;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;
use RuntimeException;

/**
 * PSR 6 Cache Wrapper with prefix support
 *
 * @method bool clear()
 * @method bool save(CacheItemInterface $item)
 * @method CacheItemInterface saveDeferred(CacheItemInterface $item)
 * @method bool commit()
 * @method ExtendedCacheItemInterface[] getItemsByTag(string $tagName)
 * @method ExtendedCacheItemInterface[] getItemsByTags(array $tagNames)
 * @method ExtendedCacheItemInterface[] getItemsByTagsAll(array $tagNames)
 * @method bool deleteItemsByTag(string $tagName)
 * @method bool deleteItemsByTags(array $tagNames)
 * @method bool deleteItemsByTagsAll(array $tagNames)
 */
class Cache
{

    /**
     * @var ExtendedCacheItemPoolInterface
     */
    protected ExtendedCacheItemPoolInterface $cache;

    /**
     * @var string
     */
    private string $prefix = '';

    /**
     * @param string $engine cache-type
     * @param array $config
     * @throws PhpfastcacheDriverCheckException
     * @throws PhpfastcacheDriverException
     * @throws PhpfastcacheDriverNotFoundException
     * @throws PhpfastcacheInvalidArgumentException
     * @throws PhpfastcacheInvalidConfigurationException
     * @throws ReflectionException
     */
    public function __construct(string $engine, array $config)
    {
        if (isset($config['prefix']) && $config['prefix'] !== null) {
            $this->setPrefix($config['prefix']);
        }

        // load caching-adapter
        try {
            if (!$config['enabled']) {

                $this->cache = CacheManager::getInstance('devnull');

            } elseif (strtolower($engine) === 'auto') {

                $this->cache = static::_loadDynamicCache($config);

            } else {

                $this->cache = static::_loadCache($engine, $config);

            }
        } catch (Exception $e) {
            $this->cache = static::_loadCache($config['fallback'], $config);
        }
    }

    /**
     * @param string $prefix
     * @return self
     */
    public function setPrefix(string $prefix = ''): self
    {
        $this->prefix = trim(rtrim((string)$prefix, '._-')) . '.';
        return $this;
    }

    /**
     * clears object variables
     */
    public function __destruct()
    {
        unset($this->cache);
    }

    /**
     * use dynamic cache-adapter
     * apc(u) > (p)redis > memcache(d) > file
     * @param array $config
     * @return ExtendedCacheItemPoolInterface
     * @throws PhpfastcacheDriverCheckException
     * @throws PhpfastcacheDriverException
     * @throws PhpfastcacheDriverNotFoundException
     * @throws PhpfastcacheInvalidArgumentException
     * @throws PhpfastcacheInvalidConfigurationException
     * @throws ReflectionException
     */
    protected static function _loadDynamicCache(array $config): ExtendedCacheItemPoolInterface
    {
        switch (true) {
            case extension_loaded('apcu'):
                return static::_loadCache('apcu', $config);

            case ini_get('apc.enabled'):
                return static::_loadCache('apc', $config);

            case extension_loaded('redis'):
                return static::_loadCache('redis', $config);

            case class_exists('Predis\Client'):
                return static::_loadCache('predis', $config);

            case extension_loaded('memcached'):
                return static::_loadCache('memcached', $config);

            case extension_loaded('memcache'):
                return static::_loadCache('memcache', $config);

            default:
                return static::_loadCache('files', $config);
        }
    }

    /**
     * @param string $name
     * @param array $config
     * @return ExtendedCacheItemPoolInterface
     * @throws PhpfastcacheDriverCheckException
     * @throws PhpfastcacheDriverException
     * @throws PhpfastcacheDriverNotFoundException
     * @throws PhpfastcacheInvalidArgumentException
     * @throws PhpfastcacheInvalidConfigurationException
     * @throws ReflectionException
     */
    protected static function _loadCache(string $name, array $config): ExtendedCacheItemPoolInterface
    {
        $driverConfig = [
            'path' => $config['path'],
            'defaultTtl' => $config['ttl'],
        ];

        switch (strtolower($name)) {
            case 'apcu':
                return CacheManager::getInstance('apcu', new Drivers\Apcu\Config($driverConfig));

            case 'apc':
                return CacheManager::getInstance('apc', new Drivers\Apc\Config($driverConfig));

            case 'redis':
                $redisConfig = array_replace_recursive($driverConfig, $config['redis']);
                foreach ($redisConfig as $key => $value) {
                    if ($value === null) {
                        unset($redisConfig[$key]);
                    }
                }
                return CacheManager::getInstance('redis', new Drivers\Redis\Config($redisConfig));

            case 'predis':
                $redisConfig = array_replace_recursive($driverConfig, $config['redis']);
                foreach ($redisConfig as $key => $value) {
                    if ($value === null) {
                        unset($redisConfig[$key]);
                    }
                }
                return CacheManager::getInstance('predis', new Drivers\Predis\Config($redisConfig));

            case 'memcached':
                $memcacheConfig = array_replace_recursive($driverConfig, $config['memcache']);
                foreach ($memcacheConfig as $key => $value) {
                    if ($value === null) {
                        unset($memcacheConfig[$key]);
                    }
                }
                return CacheManager::getInstance('memcached', new Drivers\Memcached\Config($memcacheConfig));

            case 'memcache':
                $memcacheConfig = array_replace_recursive($driverConfig, $config['memcache']);
                foreach ($memcacheConfig as $key => $value) {
                    if ($value === null) {
                        unset($memcacheConfig[$key]);
                    }
                }
                return CacheManager::getInstance('memcache', new Drivers\Memcache\Config($memcacheConfig));

            default:
                return CacheManager::getInstance(strtolower($name), new ConfigurationOption($driverConfig));

        }
    }

    /**
     * @param string $key
     * @return string
     */
    protected function prefixString(string $key): string
    {
        return $this->prefix . str_replace(
                ['{', '}', '(', ')', '/', '\\', '@', ':'],
                ['|', '|', '|', '|', '.', '.', '-', '_'],
                $key
            );
    }

    /**
     * @param array $keys
     * @param bool $recursive
     * @return array
     */
    protected function prefixArray(array $keys, bool $recursive = false): array
    {
        foreach ($keys as &$key) {
            if (is_string($key)) {
                $key = $this->prefixString($key);
            } elseif ($recursive && is_array($key)) {
                $key = $this->prefixArray($key, $recursive);
            }
        }
        return $keys;
    }

    /**
     * @return ExtendedCacheItemPoolInterface
     */
    public function getDriver(): ExtendedCacheItemPoolInterface
    {
        return $this->cache;
    }

    /**
     * @param string $key
     * @return ExtendedCacheItemInterface
     * @throws PhpfastcacheInvalidArgumentException
     */
    public function getItem(string $key): ExtendedCacheItemInterface
    {
        return $this->cache->getItem($this->prefixString($key));
    }

    /**
     * @param string $key
     * @return bool
     * @throws InvalidArgumentException
     */
    public function hasItem(string $key): bool
    {
        return $this->cache->hasItem($this->prefixString($key));
    }

    /**
     * @param string[] $keys
     * @return CacheItemInterface[]
     */
    public function getItems(array $keys = []): array
    {
        return $this->cache->getItems($this->prefixArray($keys));
    }

    /**
     * @param string $key
     * @return bool
     * @throws InvalidArgumentException
     */
    public function deleteItem(string $key): bool
    {
        return $this->cache->deleteItem($this->prefixString($key));
    }

    /**
     * @param string[] $keys
     * @return bool
     * @throws InvalidArgumentException
     */
    public function deleteItems(array $keys): bool
    {
        return $this->cache->deleteItems($this->prefixArray($keys));
    }

    /**
     * @param CacheItemInterface $item
     * @return self
     */
    public function setItem(CacheItemInterface $item)
    {
        $this->cache->setItem($item);
        return $this;
    }

    /**
     * @param string $name
     * @param array $arguments
     * @return mixed
     */
    public function __call(string $name, array $arguments)
    {

        // execute method-call at cache-driver (itemPool)
        if (method_exists($this->cache, $name)) {
            return @call_user_func_array([$this->cache, $name], $arguments);
        }

        // method not found
        throw new RuntimeException(sprintf(
            'Call to undefined Cache method %s::%s()',
            $this->getDriver()->getDriverName(),
            $name
        ), 500);
    }
}
