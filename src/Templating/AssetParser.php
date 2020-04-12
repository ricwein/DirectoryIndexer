<?php

namespace ricwein\DirectoryIndex\Templating;

use Phpfastcache\Core\Pool\ExtendedCacheItemPoolInterface;
use Phpfastcache\Exceptions\PhpfastcacheInvalidArgumentException;
use ricwein\DirectoryIndex\Config\Config;
use ricwein\FileSystem\Directory;
use ricwein\FileSystem\Exceptions\AccessDeniedException;
use ricwein\FileSystem\Exceptions\ConstraintsException;
use ricwein\FileSystem\Exceptions\FileNotFoundException;
use ricwein\FileSystem\Exceptions\RuntimeException;
use ricwein\FileSystem\Exceptions\UnexpectedValueException;
use ricwein\FileSystem\File;
use ScssPhp\ScssPhp\Compiler;
use ScssPhp\ScssPhp\Formatter\Crunched as FormatterCrunched;
use ScssPhp\ScssPhp\Formatter\Expanded as FormatterExpanded;
use MatthiasMullie\Minify;

class AssetParser
{
    public const FLAG_INLINE = 'inline';

    public const FLAG_CSSMEDIA_ALL = 'media:all';
    public const FLAG_CSSMEDIA_SCREEN = 'media:screen';
    public const FLAG_CSSMEDIA_PRINT = 'media:print';

    public const FLAG_PRELOAD = 'preload';
    public const FLAG_PRELOAD_FALLBACK = 'preload:withFallback';
    public const FLAG_LAZYLOAD = 'lazyload';

    public const FLAGS = [
        self::FLAG_INLINE,
        self::FLAG_CSSMEDIA_ALL,
        self::FLAG_CSSMEDIA_SCREEN,
        self::FLAG_CSSMEDIA_PRINT,
        self::FLAG_PRELOAD,
        self::FLAG_PRELOAD_FALLBACK,
        self::FLAG_LAZYLOAD,
    ];

    private Directory $baseDir;
    private ?ExtendedCacheItemPoolInterface $cache = null;
    private Config $config;

    public function __construct(Directory $baseDir, Config $config, ?ExtendedCacheItemPoolInterface $cache = null)
    {
        $this->baseDir = $baseDir;
        $this->config = $config;
        $this->cache = $cache;
    }

    /**
     * @param File $assetFile
     * @param array $bindings
     * @return string
     * @throws AccessDeniedException
     * @throws ConstraintsException
     * @throws FileNotFoundException
     * @throws PhpfastcacheInvalidArgumentException
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    public function parse(File $assetFile, array $bindings = []): string
    {
        if ($this->cache === null) {
            return $this->parseNew($assetFile, $bindings);
        }

        if (!$assetFile->isFile() || !$assetFile->isReadable()) {
            throw new FileNotFoundException("File {$assetFile->path()->filepath} not found!", 404);
        }

        $cacheKey = str_replace(
            ['{', '}', '(', ')', '/', '\\', '@', ':'],
            ['|', '|', '|', '|', '.', '.', '-', '_'],
            sprintf(
                "asset_%s|%d|%d",
                str_replace(['/', '\\'], '.', ltrim($assetFile->path()->filepath, '/')),
                $assetFile->getTime(),
                $this->config->development ? 1 : 0,
            )
        );

        $cacheItem = $this->cache->getItem($cacheKey);

        if (null === $asset = $cacheItem->get()) {

            $asset = $this->parseNew($assetFile);

            $cacheItem->set($asset);
            $cacheItem->expiresAfter($this->config->cache['ttl']);
            $this->cache->save($cacheItem);
        }

        return $asset;
    }

    /**
     * @param File $assetFile
     * @param array $bindings
     * @return string|null
     * @throws AccessDeniedException
     * @throws ConstraintsException
     * @throws FileNotFoundException
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    private function parseNew(File $assetFile, array $bindings = []): string
    {
        // load template from file
        /** @var string|null $asset */
        $asset = null;
        switch ($assetFile->path()->extension) {

            case 'css':
                return $this->parseNewCss($assetFile);

            case 'scss':
            case 'sass':
                return $this->parseNewScss($assetFile, $bindings);

            case 'js':
                return $this->parseNewScript($assetFile);
        }

        throw new \RuntimeException("error while processing assetfile '{$assetFile->path()->filename}': invalid extension '{$assetFile->path()->extension}", 500);
    }

    /**
     * @param File $assetFile
     * @return string
     * @throws AccessDeniedException
     * @throws ConstraintsException
     * @throws FileNotFoundException
     */
    private function parseNewCss(File $assetFile): string
    {
        if ($this->config->development) {
            return $assetFile->read();
        }

        $content = $assetFile->read();
        $minifier = new Minify\CSS($content);
        return $minifier->minify();
    }

    /**
     * @param File $assetFile
     * @param array $bindings
     * @return string
     * @throws AccessDeniedException
     * @throws ConstraintsException
     * @throws FileNotFoundException
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    private function parseNewScss(File $assetFile, array $bindings = []): string
    {
        /**
         * @var Compiler
         */
        static $compiler;

        $bindings = array_replace_recursive($this->config->assets['variables'], (array)array_filter($bindings, function ($entry): bool {
            return is_scalar($entry) || (is_object($entry) && method_exists($entry, '__toString'));
        }));

        if ($compiler === null) {
            $compiler = new Compiler();
            $compiler->setImportPaths([$this->baseDir->path()->real]);

            if ($this->config->development) {
                $compiler->setFormatter(new FormatterExpanded());
            } else {
                $compiler->setFormatter(new FormatterCrunched());
            }
        }

        $compiler->setVariables($bindings);
        $compiler->addImportPath($assetFile->directory()->path()->real);
        $compiler->addImportPath($assetFile->path()->real);

        $filecontent = $assetFile->read();
        return $compiler->compile($filecontent);
    }

    /**
     * @param File $assetFile
     * @return string
     * @throws AccessDeniedException
     * @throws ConstraintsException
     * @throws FileNotFoundException
     */
    private function parseNewScript(File $assetFile): string
    {
        if ($this->config->development) {
            return $assetFile->read();
        }

        $content = $assetFile->read();
        $minifier = new Minify\JS($content);
        return $minifier->minify();
    }
}
