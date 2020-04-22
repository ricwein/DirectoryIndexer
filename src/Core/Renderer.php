<?php

namespace ricwein\Indexer\Core;

use JsonException;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Phpfastcache\Exceptions\PhpfastcacheInvalidArgumentException;
use ReflectionClass;
use ricwein\Indexer\Config\Config;
use ricwein\Indexer\Indexer\DirectoryList;
use ricwein\Indexer\Indexer\FileInfo;
use ricwein\Indexer\Indexer\PathIgnore;
use ricwein\Indexer\Indexer\Search;
use ricwein\Indexer\Network\Http;
use ricwein\FileSystem\Directory;
use ricwein\FileSystem\Exceptions\Exception as FileSystemException;
use ricwein\FileSystem\File;
use ricwein\FileSystem\Helper\Constraint;
use ricwein\FileSystem\Storage;
use ricwein\FileSystem\Exceptions\AccessDeniedException;
use ricwein\FileSystem\Exceptions\ConstraintsException;
use ricwein\FileSystem\Exceptions\FileNotFoundException;
use ricwein\FileSystem\Exceptions\RuntimeException as FileSystemRuntimeException;
use ricwein\FileSystem\Exceptions\UnexpectedValueException;
use ricwein\Templater\Config as TemplaterConfig;
use ricwein\Indexer\Templating\AssetParser;
use ricwein\Templater\Engine\BaseFunction;
use ricwein\Templater\Exceptions\RenderingException;
use ricwein\Templater\Exceptions\RuntimeException as TemplaterRuntimeException;
use ricwein\Templater\Exceptions\UnexpectedValueException as TemplateUnexpectedValueException;
use ricwein\Templater\Templater;
use RuntimeException;
use Throwable;
use Exception;

class Renderer
{
    private Config $config;
    private Http $http;
    private ?Cache $cache = null;
    private Logger $logger;

    private array $definedBindings = [];

    /**
     * Renderer constructor.
     * @param Config $config
     * @param Http $http
     * @param Cache|null $cache
     */
    public function __construct(Config $config, Http $http, ?Cache $cache)
    {
        $this->config = $config;
        $this->http = $http;
        $this->cache = $cache;

        $this->logger = new Logger('DirectoryIndex');

        $this->logger->pushHandler(new StreamHandler(
            $config->docker ? 'php://stderr' : $this->config->log['file'],
            $this->config->log['severity']
        ));
    }

    public function setBindings(array $bindings): void
    {
        $this->definedBindings = array_replace_recursive($this->definedBindings, $bindings);
    }

    private function getTemplateBindings(): array
    {
        return array_replace_recursive([
            'config' => $this->config->get(),
            'assets' => $this->config->assets['variables'],
            'url' => [
                'base' => rtrim($this->http->getBaseURL(null, $this->config->URLLocationPath), '/'),
                'current' => rtrim($this->http->getPathURL(null, $this->config->URLLocationPath), '/'),
                'referrer' => $this->http->get('HTTP_REFERER', Http::SERVER),
            ],
            'http' => $this->http,
            'date' => [
                'year' => date('Y'),
            ],
        ], $this->definedBindings);
    }

    /**
     * @param string $templateFile
     * @param int $statusCode
     * @param array $bindings
     * @param callable|null $filter
     */
    public function display(string $templateFile, int $statusCode = 200, array $bindings = [], ?callable $filter = null): void
    {
        $templateName = str_replace(['_', '.'], ' ', pathinfo(str_replace($this->config->views['extension'], '', $templateFile), PATHINFO_FILENAME));

        /** @var array $bindings */
        $bindings = array_replace_recursive(
            [
                'template' => [
                    'name' => ucfirst(strtolower($templateName)),
                    'identifier' => strtolower($templateName),
                ],
            ],
            $this->getTemplateBindings(),
            $bindings
        );

        if (!$this->config->development) {
            $filter = static function (string $content, Templater $templater) use ($filter): string {
                if ($filter !== null) {
                    $content = $filter($content, $templater);
                }

                $regexReplaces = [
                    '/\>[^\S ]+/s' => '>', // strip whitespaces after tags, except space
                    '/[^\S ]+\</s' => '<', // strip whitespaces before tags, except space
                    '/(\s)+/s' => '\\1', // shorten multiple whitespace sequences
                    '/<!--(.|\s)*?-->/' => '', // Remove HTML comments
                ];

                return trim(preg_replace(array_keys($regexReplaces), array_values($regexReplaces), $content));
            };
        }

        try {
            Http::sendStatusHeader($statusCode);
            Http::sendHeaders(['Content-Type' => 'text/html; charset=utf-8']);

            $templater = new Templater($this->getTemplaterConfig(), $this->cache->getDriver());
            $templater->addFunction(new BaseFunction('asset', [$this, 'convertAssetURL']));
            $templater->addFunction(new BaseFunction('render_markdown', [$this, 'convertMarkdown']));
            $templater->addFunction(new BaseFunction('get_file_info', function (Storage $storage): FileInfo {
                return new FileInfo($storage, $storage->getConstraints(), $this->cache);
            }));
            $response = $templater->render($templateFile, $bindings, $filter);

            echo $response;

            exit($statusCode < 400 ? 0 : $statusCode);

        } catch (Throwable $throwable) {
            $this->displayError($throwable);
        }
    }

    /**
     * @return TemplaterConfig
     * @throws TemplateUnexpectedValueException
     */
    private function getTemplaterConfig(): TemplaterConfig
    {
        return new TemplaterConfig([
            'debug' => $this->config->development,
            'cacheDuration' => $this->config->cache['ttl'],
            'cacheBusterEnabled' => $this->config->assets['useCachebuster'],
            'fileExtension' => $this->config->views['extension'],
            'stripComments' => $this->config->views['removeComments'],
            'templateDir' => $this->config->views['path'],
        ]);
    }

    /**
     * @param string $assetFilename
     * @throws AccessDeniedException
     * @throws ConstraintsException
     * @throws FileNotFoundException
     * @throws FileSystemRuntimeException
     * @throws UnexpectedValueException
     * @throws FileSystemException
     */
    public function displayAssetFile(string $assetFilename): void
    {
        $assetDir = new Directory(new Storage\Disk($this->config->assets['path']));
        $assetFile = $assetDir->file($assetFilename);

        $headers = [
            'Content-Type' => $assetFile->getType(true),
        ];
        if ($this->config->development) {
            $headers += [
                'Expires' => 0,
                'Cache-Control' => ['no-store, no-cache, must-revalidate, max-age=0', 'post-check=0, pre-check=0'],
                'Pragma' => 'no-cache',
            ];
        } else {
            $headers += [
                'Cache-Control' => "max-age={$this->config->cache['ttl']}",
            ];
        }

        Http::sendHeaders($headers);
        Http::sendStatusHeader(200);

        $assetFile->stream();
        exit(0);
    }

    /**
     * @param string $assetFilename
     * @throws AccessDeniedException
     * @throws ConstraintsException
     * @throws FileNotFoundException
     * @throws FileSystemException
     * @throws PhpfastcacheInvalidArgumentException
     * @throws FileSystemRuntimeException
     * @throws UnexpectedValueException
     */
    public function displayAndRenderAssetFile(string $assetFilename): void
    {
        $assetDir = new Directory(new Storage\Disk($this->config->assets['path']));
        $assetFile = $assetDir->file($assetFilename);
        $parser = new AssetParser($assetDir, $this->config, $this->cache->getDriver());

        $content = $parser->parse($assetFile, $this->getTemplateBindings());
        $headers = [];

        switch ($assetFile->path()->extension) {
            case 'scss':
            case 'sass':
            case 'css':
                $headers += [
                    'Content-Type' => ['text/css', 'charset=utf-8'],
                ];
                break;

            case 'js':
                $headers += [
                    'Content-Type' => ['application/javascript', 'charset=utf-8'],
                ];
                break;
        }

        if ($this->config->development) {
            $headers += [
                'Expires' => 0,
                'Cache-Control' => ['no-store, no-cache, must-revalidate, max-age=0', 'post-check=0, pre-check=0'],
                'Pragma' => 'no-cache',
            ];
        } else {
            $headers += [
                'Cache-Control' => "max-age={$this->config->cache['ttl']}",
            ];
        }

        Http::sendHeaders($headers);
        Http::sendStatusHeader(200);

        echo $content;
        exit(0);
    }

    /**
     * @param Throwable $throwable
     */
    public function displayError(Throwable $throwable): void
    {
        $previousOutputs = ob_get_clean();
        Http::sendStatusHeader($throwable->getCode());

        try {

            /** @var Throwable[] $chain */
            $chain = [];
            $prev = $throwable;
            do {
                $chain[] = $prev;
            } while ($prev = $prev->getPrevious());


            $traced = [];
            $exceptionsArray = [];
            foreach ($chain as $entry) {

                $traces = array_map(static function (string $line): string {
                    return ltrim(str_replace(dirname(__DIR__, 2) . '/', '', $line), '#1234567890 ');
                }, explode(PHP_EOL, $entry->getTraceAsString()));

                $lines = [];
                foreach (file($entry->getFile()) as $line => $content) {
                    $lines[$line + 1] = $content;
                }

                $exceptionTrace = [];
                foreach ($traces as $key => $trace) {
                    $tracedKey = array_search($trace, $traced, true);
                    if ($tracedKey === false && $tracedKey !== $key) {
                        $exceptionTrace[] = $trace;
                    } else {
                        break;
                    }
                }

                /** @noinspection SlowArrayOperationsInLoopInspection */
                $traced = array_merge($traced, $exceptionTrace);

                $error = [
                    'code' => $entry->getCode(),
                    'type' => (new ReflectionClass($entry))->getShortName(),
                    'message' => $entry->getMessage(),
                    'file' => str_replace(dirname(__DIR__, 2) . '/', '', $entry->getFile()),
                    'line' => $entry->getLine(),
                    'trace' => array_reverse($exceptionTrace),
                    'lines' => $lines,
                ];

                if ($entry instanceof RenderingException && null !== $templateFile = $entry->getTemplateFile()) {
                    $templateLine = $entry->getTemplateLine();

                    $error['template'] = [
                        'line' => $templateLine,
                        'file' => $templateFile,
                    ];
                }
                $exceptionsArray[] = $error;
            }

            $cache = $this->cache;
            if ($cache === null) {
                $cache = new Cache($this->config->cache['engine'], $this->config->cache);
            }

            $templater = new Templater($this->getTemplaterConfig(), $cache->getDriver());
            $templater->addFunction(new BaseFunction('asset', [$this, 'convertAssetURL']));
            $bindings = $this->getTemplateBindings();

            try {
                $bindings = array_replace_recursive([
                    'template' => ['name' => 'Error', 'identifier' => 'error'],
                ], $bindings);

                $response = $templater->render('error/error.html.twig', array_replace_recursive($bindings, [
                    'exceptions' => $exceptionsArray,
                    'others' => empty($previousOutputs) ? null : (array)explode(PHP_EOL, $previousOutputs)
                ]));
            } catch (Exception $e) {
                $bindings = array_replace_recursive([
                    'template' => ['name' => 'Unhandled Error', 'identifier' => 'error'],
                ], $bindings);

                $response = $templater->render('error/unhandled_error.html.twig', $bindings);
            }

            $this->logException($throwable);

            echo $response;
            exit($throwable->getCode());
        } catch (Exception $exception) {
            $this->logException($exception, Logger::EMERGENCY);
            $this->logException($throwable);

            static::displayFatalError($throwable, empty($previousOutputs) ? null : htmlspecialchars(strip_tags($previousOutputs)));
        }
    }

    public static function displayFatalError(Throwable $error, ?string $additions = null): void
    {
        Http::sendStatusHeader(500);

        /** @var Throwable[] $chain */
        echo "FATAL ERROR\n";

        $config = Config::getInstance();
        $prev = $error;

        do {

            $errorType = substr(strrchr(get_class($prev), "\\"), 1);
            if ($prev instanceof RenderingException && null !== $templateFile = $prev->getTemplateFile()) {
                $lines = $templateFile->readAsLines();
                $line = $lines[$prev->getTemplateLine() - 1];

                try {
                    $templateFilename = ($templateFile->storage() instanceof Storage\Memory) ? '[MEMORY]' : $templateFile->path()->filename;
                } catch (FileSystemRuntimeException $e) {
                    $templateFilename = '[UNKNOWN]';
                }

                echo sprintf(" - [%d]: %s in %s:%d - \"%s\" at line %s:%d \"%s\"\n", $prev->getCode(), $errorType, basename($prev->getFile()), $prev->getLine(), $prev->getMessage(), $templateFilename, $prev->getTemplateLine(), htmlspecialchars(trim($line)));
            } else {
                echo sprintf(" - [%d]: %s in %s:%d - \"%s\"\n", $prev->getCode(), $errorType, basename($prev->getFile()), $prev->getLine(), $prev->getMessage());
            }

            if ($config !== null && $config->development) {
                echo sprintf("%s\n", implode("\n", array_map(function (string $line): string {
                    return sprintf('  | %s', ltrim($line, '# '));
                }, explode("\n", $prev->getTraceAsString()))));
            }

        } while ($prev = $prev->getPrevious());

        if ($additions !== null && $config !== null && $config->development) {
            echo "\nADDITIONS\n{$additions}";
        }
        exit($error->getCode());
    }

    private function logException(Throwable $throwable, int $severity = null): void
    {
        $severity = $severity ?? ($throwable->getCode() > 0 && $throwable->getCode() < 500 ? Logger::NOTICE : Logger::ERROR);
        $this->logger->addRecord(
            $severity,
            $throwable->getMessage(),
            ['exception' => $throwable]
        );
    }

    /**
     * @return Directory
     * @throws AccessDeniedException
     * @throws FileSystemRuntimeException
     * @throws UnexpectedValueException
     */
    private function getRootDir(): Directory
    {
        if (null !== $path = $this->config->path) {

            if (strpos($path, '/') === 0) {
                $storage = new Storage\Disk($path);
            } else {
                $storage = new Storage\Disk(__DIR__ . '/../../../' . $path);
            }

        } else {
            $storage = new Storage\Disk(__DIR__ . '/../../../');
        }

        $rootDir = new Directory($storage, Constraint::STRICT & ~Constraint::DISALLOW_LINK);
        if (!$rootDir->isDir() || !$rootDir->isReadable()) {
            throw new RuntimeException("Unable to read root directory: {$rootDir->path()->raw}", 500);
        }

        return $rootDir;
    }

    /**
     * @param string $searchTerm
     * @throws AccessDeniedException
     * @throws FileSystemRuntimeException
     * @throws UnexpectedValueException
     */
    public function displayPathSearch(string $searchTerm): void
    {
        $this->display('page/search.html.twig', 200, [
            'subject' => $searchTerm,
            'searcher' => new Search($this->getRootDir(), $this->config, $this->cache),
        ]);
    }

    /**
     * @param string $path
     * @throws AccessDeniedException
     * @throws ConstraintsException
     * @throws FileNotFoundException
     * @throws FileSystemException
     * @throws FileSystemRuntimeException
     * @throws UnexpectedValueException
     */
    public function displayPath(string $path): void
    {
        $rootDir = $this->getRootDir();

        $path = ltrim($path, '/');
        if ($path === '') {
            $this->displayPathIndex($rootDir, $rootDir);
        }

        $storage = new Storage\Disk($rootDir, $path);
        $pathIgnore = new PathIgnore($rootDir, $this->config);

        if (!$storage->isReadable() || $pathIgnore->isForbiddenStorage($storage)) {
            throw new FileNotFoundException("File not found: {$path}", 404);
        }


        if ($storage->isFile()) {
            $this->displayPathFile(new File($storage));
        }

        if (!$storage->isDir()) {
            $filepath = $storage->path()->real ?? $storage->path()->raw;
            throw new FileNotFoundException("Unable to read file: {$filepath}", 404);
        }

        if (strpos($storage->path()->real, $rootDir->path()->real) !== 0) {
            throw new RuntimeException('Access denied.', 403);
        }

        $this->displayPathIndex($rootDir, new Directory($storage, $rootDir->storage()->getConstraints()));
    }

    /**
     * @param Directory $rootDir
     * @param Directory $dir
     * @throws AccessDeniedException
     * @throws ConstraintsException
     * @throws FileNotFoundException
     * @throws FileSystemException
     * @throws FileSystemRuntimeException
     */
    private function displayPathIndex(Directory $rootDir, Directory $dir): void
    {
        $indexer = new DirectoryList($rootDir, $dir, $this->config);

        $bindings = [
            'index' => $indexer
        ];

        // git root directory
        $gitConfig = $dir->file('.git/config');
        if ($gitConfig->isFile() && null !== $url = $this->parseGitConfig($gitConfig)) {
            $bindings['git'] = [
                'url' => $url
            ];
            $this->display('page/index.html.twig', 200, $bindings);
        }

        // git submodule, resolve .git/config ref first
        $gitRootRef = $dir->file('.git', Constraint::LOOSE);
        if ($gitRootRef->isFile()) {
            $gitRootPath = trim($gitRootRef->read());

            if (strpos($gitRootPath, 'gitdir:') === 0) {
                $gitRootPath = trim(substr($gitRootPath, strlen('gitdir:')));
            }

            $gitConfig = $dir->file($gitRootPath . '/config');
            if ($gitConfig->isFile() && null !== $url = $this->parseGitConfig($gitConfig)) {
                $bindings['git'] = [
                    'url' => $url
                ];
                $this->display('page/index.html.twig', 200, $bindings);
            }
        }

        $this->display('page/index.html.twig', 200, $bindings);
    }

    /**
     * @param File $gitConfig
     * @return string|null
     * @throws AccessDeniedException
     * @throws ConstraintsException
     * @throws FileNotFoundException
     */
    private function parseGitConfig(File $gitConfig): ?string
    {
        $config = parse_ini_string($gitConfig->read(), true, INI_SCANNER_NORMAL);
        $url = trim($config['remote origin']['url']);
        if (strpos($url, 'http') === 0) {
            return $url;
        }
        return null;
    }

    /**
     * @param string $path
     * @throws AccessDeniedException
     * @throws ConstraintsException
     * @throws FileNotFoundException
     * @throws FileSystemException
     * @throws FileSystemRuntimeException
     * @throws UnexpectedValueException
     * @throws JsonException
     */
    public function displayPathInfo(string $path): void
    {
        $rootDir = $this->getRootDir();
        $storage = new Storage\Disk($rootDir, $path);
        $pathIgnore = new PathIgnore($rootDir, $this->config);

        if (!$storage->isReadable() || $pathIgnore->isForbiddenStorage($storage)) {
            throw new FileNotFoundException("File not found: {$path}", 404);
        }

        if (strpos($storage->path()->real, $rootDir->path()->real) !== 0) {
            throw new RuntimeException('Access denied.', 403);
        }

        $info = (new FileInfo($storage, $rootDir->storage()->getConstraints(), $this->cache))->getInfo();

        Http::sendStatusHeader(200);
        Http::sendHeaders([
            'Content-Type' => 'application/json; charset=utf8',
            'Cache-Control' => ['public', 'must-revalidate', 'max-age=0'],
            'Pragma' => 'no-cache',
        ]);

        echo json_encode($info, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT, 512);
        exit(0);
    }

    /**
     * @param File $file
     * @throws AccessDeniedException
     * @throws ConstraintsException
     * @throws FileNotFoundException
     * @throws FileSystemRuntimeException
     * @throws UnexpectedValueException
     */
    private function displayPathFile(File $file): void
    {
        $this->streamFile($file);
    }

    /**
     * @param string $path
     * @throws AccessDeniedException
     * @throws ConstraintsException
     * @throws FileNotFoundException
     * @throws FileSystemException
     * @throws FileSystemRuntimeException
     * @throws UnexpectedValueException
     */
    public function downloadDirectoryZip(string $path): void
    {
        $rootDir = $this->getRootDir();
        $storage = new Storage\Disk($rootDir, $path);
        $pathIgnore = new PathIgnore($rootDir, $this->config);

        if (!$storage->isReadable() || $pathIgnore->isForbiddenStorage($storage)) {
            throw new FileNotFoundException("File not found: {$path}", 404);
        }

        if (strpos($storage->path()->real, $rootDir->path()->real) !== 0) {
            throw new RuntimeException('Access denied.', 403);
        }

        if (!$storage->isDir()) {
            $this->streamFile(new File($storage));
        }

        $zipCache = new Storage\Disk\Temp();
        $zip = new File\Zip($zipCache);
        $zip->addDirectoryStorage($storage, '/', static function (Storage\Disk $storage) use ($pathIgnore): bool {
            if (!$storage->isReadable()) {
                return false;
            }
            if ($pathIgnore->isForbiddenStorage($storage)) {
                return false;
            }
            if ($storage->path()->filename === PathIgnore::FILEIGNORE_FILENAME) {
                return false;
            }
            return true;
        });
        $zip->commit();

        $this->streamFile($zip, "{$storage->path()->basename}.zip");
    }

    /**
     * @param File $file
     * @param string|null $asName
     * @throws AccessDeniedException
     * @throws ConstraintsException
     * @throws FileNotFoundException
     * @throws FileSystemRuntimeException
     * @throws UnexpectedValueException
     */
    private function streamFile(File $file, ?string $asName = null): void
    {
        $size = $file->getSize();
        $rangeStart = 0;
        $rangeEnd = $size;

        // parse http range request header
        $range = $this->http->get('HTTP_RANGE', Http::SERVER, null);
        if (($range !== null) && preg_match('/bytes=\h*(\d+)-(\d*)[\D.*]?/i', $range, $matches) === 1) {
            $rangeStart = (int)$matches[0];

            if (!empty($matches[1])) {
                $rangeEnd = (int)$matches[1];
            }
        }

        if ($rangeStart > 0 || $rangeEnd < $size) {
            Http::sendStatusHeader(206);
        } else {
            Http::sendStatusHeader(200);
        }

        $filename = $asName ?? $file->path()->filename;

        Http::sendHeaders([
            'Content-Type' => $file->getType(true),
            'Cache-Control' => ['public', 'must-revalidate', 'max-age=0'],
            'Pragma' => 'no-cache',
            'Accept-Ranges' => 'bytes',
            'Content-Length' => $rangeEnd - $rangeStart,
            'Content-Range' => "{$rangeStart}-{$rangeEnd}/{$size}",
            'Content-Disposition' => ['attachment', "filename=\"{$filename}\""],
            'Content-Transfer-Encoding' => 'binary',
            'Last-Modified' => gmdate('D, d M Y H:i:s T', $file->getTime()),
            'Connection' => 'close',
        ]);

        $file->stream($rangeStart, $rangeEnd - $rangeStart);
        exit(0);
    }

    /**
     * @param string $markdownString
     * @param string|null $relativeUrl
     * @return string
     */
    public function convertMarkdown(string $markdownString, ?string $relativeUrl = null): string
    {
        $converter = new GithubFlavoredMarkdownConverter([
            'allow_unsafe_links' => true,
        ]);

        $html = $converter->convertToHtml($markdownString);

        if ($relativeUrl !== null) {
            $relativeUrl = sprintf('%s/', rtrim(trim($relativeUrl, ' '), '/'));
        }

        // rewrite relative urls
        $html = trim(str_replace([
            '"./', '\'./'
        ], [
            "\"{$relativeUrl}", "'{$relativeUrl}"
        ], $html));

        return $html;
    }

    /**
     * @param string $filename
     * @param string[] $flags
     * @return string
     * @throws AccessDeniedException
     * @throws ConstraintsException
     * @throws FileNotFoundException
     * @throws FileSystemException
     * @throws FileSystemRuntimeException
     * @throws PhpfastcacheInvalidArgumentException
     * @throws TemplaterRuntimeException
     * @throws UnexpectedValueException
     */
    public function convertAssetURL(string $filename, string ...$flags): string
    {
        $assetDir = new Directory(new Storage\Disk($this->config->assets['path']), Constraint::IN_OPENBASEDIR);

        $assetFile = $assetDir->file($filename);
        if (!$assetFile->isFile() || !$assetFile->isReadable()) {
            throw new FileNotFoundException("File {$assetFile->path()->filepath} not found!", 404);
        }

        $flags = array_filter($flags, static function (string $flag): bool {
            return in_array($flag, AssetParser::FLAGS, true);
        });

        // inline asset file
        if (in_array(AssetParser::FLAG_INLINE, $flags, true)) {
            $parser = new AssetParser($assetDir, $this->config, $this->cache->getDriver());
            $assetVars = $this->config->assets['variables'];

            switch ($assetFile->path()->extension) {

                case 'css':
                case 'scss':
                case 'sass':
                    $content = trim($parser->parse($assetFile, $assetVars));
                    return empty($content) ? '' : sprintf('<style type="text/css">%s%s%s</style>', PHP_EOL, $content, PHP_EOL);

                case 'js':
                    $content = trim($parser->parse($assetFile, $assetVars));
                    return empty($content) ? '' : sprintf('<script>%s%s%s</script>', PHP_EOL, $content, PHP_EOL);
            }

            throw new TemplaterRuntimeException("Unsupported Asset-File: {$assetFile->path()->filepath}", 400);
        }

        $baseURL = rtrim($this->http->getBaseURL(null, $this->config->URLLocationPath), '/');

        if (in_array($assetFile->path()->extension, ['css', 'scss', 'sass'], true)) {
            return $this->renderStyleAsset($assetFile, $baseURL, $flags);
        }

        if (in_array($assetFile->path()->extension, ['js', 'script'], true)) {
            return $this->renderScriptAsset($assetFile, $baseURL);
        }

        throw new TemplaterRuntimeException("Unsupported Asset-File: {$assetFile->path()->filepath}", 400);
    }

    /**
     * @param File $assetFile
     * @param string $baseURL
     * @param string[] $flags
     * @return string
     * @throws FileSystemRuntimeException
     */
    private function renderStyleAsset(File $assetFile, string $baseURL, array $flags): string
    {
        $filename = ltrim($assetFile->path()->filepath, '/');

        if ($this->config->assets['useCachebuster']) {
            $fileURL = sprintf('%s/assets/%s?v=%d:%s',
                $baseURL, $filename, $assetFile->getTime(), $this->config->development ? '1' : '0'
            );
        } else {
            $fileURL = sprintf('%s/assets/%s.css', $baseURL, $filename,);
        }

        $media = 'all';
        switch (true) {
            case in_array(AssetParser::FLAG_CSSMEDIA_ALL, $flags, true):
                $media = 'all';
                break;

            case in_array(AssetParser::FLAG_CSSMEDIA_SCREEN, $flags, true):
                $media = 'screen';
                break;

            case in_array(AssetParser::FLAG_CSSMEDIA_PRINT, $flags, true):
                $media = 'print';
                break;
        }

        $linkTag = "<link rel=\"stylesheet\" href=\"{$fileURL}\" media=\"{$media}\" />";

        switch (true) {
            case in_array(AssetParser::FLAG_PRELOAD, $flags, true):
                return "<link rel=\"preload\" href=\"{$fileURL}\" as=\"style\" onload=\"this.onload=null;this.rel='stylesheet'\"><noscript>{$linkTag}</noscript>";

            case in_array(AssetParser::FLAG_PRELOAD_FALLBACK, $flags, true):
                return "<link rel=\"preload\" href=\"{$fileURL}\" as=\"style\" onload=\"this.onload=null;this.rel='stylesheet'\">{$linkTag}";

            case in_array(AssetParser::FLAG_LAZYLOAD, $flags, true):
                return "<link href=\"{$fileURL}\" rel=\"stylesheet\" media=\"none\" onload=\"media='{$media}'\" /><noscript>{$linkTag}</noscript>";

            default:
                return $linkTag;
        }
    }

    /**
     * @param File $assetFile
     * @param string $baseURL
     * @return string
     * @throws FileSystemRuntimeException
     */
    private function renderScriptAsset(File $assetFile, string $baseURL): string
    {
        $filename = ltrim(str_replace(".{$assetFile->path()->extension}", '', $assetFile->path()->filepath), '/');

        if ($this->config->assets['useCachebuster']) {
            $fileURL = sprintf('%s/assets/%s.js?v=%d:%s',
                $baseURL, $filename, $assetFile->getTime(), $this->config->development ? '1' : '0'
            );
        } else {
            $fileURL = sprintf('%s/assets/%s.js', $baseURL, $filename,);
        }

        switch (true) {
            default:
                return "<script async src=\"{$fileURL}\"></script>";

        }
    }
}
