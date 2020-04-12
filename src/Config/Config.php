<?php
/**
 *  SLURM Configurator - central Runtime-Configuration
 */

namespace ricwein\DirectoryIndex\Config;

use Monolog\Logger;
use ricwein\DirectoryIndex\Core\Helper;
use ricwein\FileSystem\Exceptions\FileNotFoundException;
use ricwein\Templater\Exceptions\UnexpectedValueException;

/**
 * Class Config
 * @property-read string URLLocationPath
 * @property-read bool development
 * @property-read bool hideDotfiles
 * @property-read bool docker
 * @property-read array imports
 * @property-read array paths
 * @property-read array log
 * @property-read array cache
 * @property-read array views
 * @property-read array assets
 */
class Config
{
    /**
     * @var self|null
     */
    protected static ?Config $instance = null;

    /**
     * default configuration
     * @var array
     */
    private array $config = [
        'development' => false,

        'URLLocationPath' => '/',
        'docker' => false,

        'hideDotfiles' => false,

        'imports' => [
            ['resource' => 'config.json'],
        ],

        'paths' => [
            'log' => '/logs',
            'cache' => '/cache',
            'view' => '/views',
            'asset' => '/assets',
            'config' => '/config',
        ],

        'log' => [
            'file' => '@log/error.log',
            'severity' => Logger::NOTICE, // 250
        ],

        'cache' => [
            'enabled' => true,
            'engine' => 'auto', // phpFastCache driver
            'fallback' => 'files',
            'ttl' => 3600, // default duration: 1h
            'prefix' => null,
            'path' => '@cache', // path for filecache
            'memcache' => ['path' => null], // memcache configuration, see phpFastCache
            'redis' => ['path' => null], // redis configuration, see phpFastCache
        ],

        'views' => [
            'path' => '@view',
            'extension' => '.html.twig',
            'variables' => [],
            'removeComments' => true,
        ],

        'assets' => [
            'path' => '@asset',
            'variables' => [],
            'useCachebuster' => true,
        ],
    ];

    /**
     * @var array|null
     */
    private ?array $paths = null;

    /**
     * provide singleton access to configurations
     * @param array|null $override
     * @return self
     * @throws FileNotFoundException
     * @throws UnexpectedValueException
     */
    public static function getInstance(?array $override = null): self
    {
        if (static::$instance === null) {
            static::$instance = new static();
        }
        if ($override !== null) {
            static::$instance->set($override);
        }
        return static::$instance;
    }

    /**
     * init new config object
     * @throws FileNotFoundException
     * @throws UnexpectedValueException
     */
    private function __construct()
    {
        $this->loadConfigEnv();
        $this->loadConfigFiles($this->config['imports']);
        $this->resolvePaths();
    }

    private static function toBool($value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            if (in_array(strtolower($value), ['true', 'on', 'yes', '1'], true)) {
                return true;
            }

            if (in_array(strtolower($value), ['false', 'off', 'no', '0'], true)) {
                return false;
            }

            return null;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        return null;
    }

    /**
     * @throws UnexpectedValueException
     */
    private function loadConfigEnv(): void
    {
        foreach ($_ENV as $name => $value) {
            switch ($name) {

                case 'URL_LOCATION_PATH':
                    $this->config['URLLocationPath'] = (string)$value;
                    break;

                case 'DEVELOPMENT':
                case 'DEBUG':
                    if (null === $boolVal = static::toBool($value)) {
                        throw new UnexpectedValueException(sprintf('Invalid value for environment variable "%s", expected type bool but got %s', $name, gettype($value)), 500);
                    }
                    $this->config['development'] = $boolVal;
                    break;

                case 'HIDE_DOTFILES':
                    if (null === $boolVal = static::toBool($value)) {
                        throw new UnexpectedValueException(sprintf('Invalid value for environment variable "%s", expected type bool but got %s', $name, gettype($value)), 500);
                    }
                    $this->config['hideDotfiles'] = $boolVal;
                    break;

                case 'DOCKER':
                    if (null === $boolVal = static::toBool($value)) {
                        throw new UnexpectedValueException(sprintf('Invalid value for environment variable "%s", expected type bool but got %s', $name, gettype($value)), 500);
                    }
                    $this->config['docker'] = $boolVal;
                    break;

                case 'IMPORTS':
                    $this->config['imports'] = [['resource' => (string)$value]];
                    break;

                case 'CACHE_ENABLED':
                    if (null === $boolVal = static::toBool($value)) {
                        throw new UnexpectedValueException(sprintf('Invalid value for environment variable "%s", expected type bool but got %s', $name, gettype($value)), 500);
                    }
                    $this->config['cache']['enabled'] = (bool)$boolVal;
                    break;

                case 'CACHE_ENGINE':
                    $this->config['cache']['engine'] = (string)$value;
                    break;

                case 'CACHE_TTL':
                    $this->config['cache']['ttl'] = (int)$value;
                    break;

                case 'STRIP_COMMENTS':
                case 'REMOVE_COMMENTS':
                    if (null === $boolVal = static::toBool($value)) {
                        throw new UnexpectedValueException(sprintf('Invalid value for environment variable "%s", expected type bool but got %s', $name, gettype($value)), 500);
                    }
                    $this->config['views']['removeComments'] = $boolVal;
                    break;
            }
        }
    }

    /**
     * load configuration from files
     * @param array $importList
     * @param array $loaded
     * @return self
     * @throws FileNotFoundException
     */
    private function loadConfigFiles(array $importList, array $loaded = []): self
    {
        foreach ($importList as $import) {
            if (!is_array($import) || !isset($import['resource'])) {
                continue;
            }

            $path = $this->resolvePath($import['resource'], __DIR__ . '/../../config/');
            if ($path === null) {
                throw new FileNotFoundException(sprintf('Config resource file not found for path: %s', $import['resource']), 404);
            }

            if (in_array($path, $loaded, true)) {
                continue;
            }


            if (!file_exists($path) || !is_readable($path)) {
                throw new FileNotFoundException(sprintf('Config resource file not readable for path: %s (real: %s)', $import['resource'], $path ?? '-'), 404);
            }

            if (null !== $fileConfig = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)) {
                $this->config = array_replace_recursive($this->config, $fileConfig);

                if (isset($fileConfig['paths'])) {
                    $this->paths = null;
                }

                if (isset($fileConfig['imports'])) {
                    $this->loadConfigFiles($fileConfig['imports'], $loaded);
                }
            }

            $loaded[] = $path;
        }

        return $this;
    }

    /**
     * @return void
     */
    protected function resolvePaths(): void
    {
        $this->config = Helper::array_map_recursive(function ($item) {
            return is_string($item) ? strtr($item, $this->getPaths()) : $item;
        }, $this->config);
    }

    /**
     * @param string $filepath
     * @param string|null $relativePath
     * @return string|null
     */
    protected function resolvePath(string $filepath, string $relativePath = null): ?string
    {
        $filepath = strtr($filepath, $this->getPaths());

        if (strpos($filepath, '/') === 0 && false !== $resolved = realpath($filepath)) {
            return $resolved;
        }

        if (strpos($filepath, '/') === 0) {
            if (false !== $resolved = realpath(__DIR__ . '/../../' . ltrim($filepath, '/'))) {
                return $resolved;
            }

            return null;
        }

        if ($relativePath !== null) {
            if (false !== $resolved = realpath($relativePath . ltrim($filepath, '/'))) {
                return $resolved;
            }

            return null;
        }

        return $filepath;
    }

    /**
     * @return array
     */
    protected function getPaths(): array
    {
        if ($this->paths === null) {
            $this->paths = [];

            foreach ($this->get('paths') as $key => $path) {
                $this->paths["@{$key}"] = rtrim(realpath(sprintf('%s/../../%s', __DIR__, ltrim($path, '/'))), '/');
            }
        }

        return $this->paths;
    }

    /**
     * @param string|null $name
     * @return mixed|null
     */
    public function get(string $name = null)
    {
        if ($name === null) {
            return $this->config;
        }

        if (array_key_exists($name, $this->config)) {
            return $this->config[$name];
        }

        return null;
    }

    /**
     * @param array $config
     * @return self
     */
    public function set(array $config): self
    {
        $this->config = array_replace_recursive($this->config, $config);
        return $this;
    }

    /**
     * @param string $name
     * @return mixed|null
     */
    public function __get(string $name)
    {
        return $this->get($name);
    }

    /**
     * @param string $name
     * @param mixed $value
     * @return void
     */
    public function __set(string $name, $value)
    {
        $this->config[$name] = $value;
    }

    /**
     * @param string $name
     * @return bool
     */
    public function __isset(string $name): bool
    {
        return array_key_exists($name, $this->config);
    }
}
