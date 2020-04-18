<?php

namespace ricwein\Indexer\Core;

use Exception;
use FastRoute;
use Phpfastcache\Exceptions\PhpfastcacheDriverCheckException;
use Phpfastcache\Exceptions\PhpfastcacheDriverException;
use Phpfastcache\Exceptions\PhpfastcacheDriverNotFoundException;
use Phpfastcache\Exceptions\PhpfastcacheInvalidArgumentException;
use Phpfastcache\Exceptions\PhpfastcacheInvalidConfigurationException;
use ReflectionException;
use ricwein\Indexer\Config\Config;
use ricwein\Indexer\Exception\NotFoundException;
use ricwein\Indexer\Exception\RoutingException;
use ricwein\Indexer\Network\Http;
use RuntimeException;
use Throwable;

class Router
{
    private Config $config;
    private Http $http;

    private ?Cache $cache = null;
    private ?Renderer $renderer = null;
    private ?FastRoute\Dispatcher $dispatcher = null;

    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->http = new Http();
    }

    /**
     * @param callable|null $routeCollection
     * @param array $bindings
     * @return self
     * @throws PhpfastcacheDriverCheckException
     * @throws PhpfastcacheDriverException
     * @throws PhpfastcacheDriverNotFoundException
     * @throws PhpfastcacheInvalidArgumentException
     * @throws PhpfastcacheInvalidConfigurationException
     * @throws ReflectionException
     */
    public function setup(?callable $routeCollection = null, array $bindings = []): self
    {
        $this->cache = new Cache(
            $this->config->cache['engine'],
            $this->config->cache
        );

        $this->renderer = new Renderer($this->config, $this->http, $this->cache);
        $this->renderer->setBindings($bindings);

        $routes = $this->defineRoutes($routeCollection);
        $this->dispatcher = FastRoute\simpleDispatcher($routes);

        return $this;
    }

    private function defineRoutes(?callable $routeCollection = null): callable
    {
        return function (FastRoute\RouteCollector $routes) use ($routeCollection) {
            if ($routeCollection !== null) {
                $routeCollection($routes, $this->renderer);
            }

            // Routes: assets
            $routes->addRoute('GET', '/assets/{path:.+}/{file}.{extension:css|scss|js}', function (string $path, string $file, string $extension) {
                $file = ltrim($file, '_');
                $this->renderer->displayAndRenderAssetFile("{$path}/{$file}.{$extension}");
            });

            // Routes: robots.txt
            $routes->addRoute('GET', '/robots.txt', function () {
                $this->renderer->display('res/robots.txt.twig', 200, ['robots' => ['rules' => [
                ]]]);
            });

            // Routes: fallback assets pipe-through
            $routes->addRoute('GET', '/assets/{filepath:.+}.{extension:woff|woff2|eot|svg|ttf|sass|map}', function (string $filepath, string $extension) {
                $this->renderer->displayAssetFile("{$filepath}.{$extension}");
            });

            // Routes: search controller
            if (null !== $subject = $this->http->getQueryParameter('subject')) {
                $routes->addRoute('GET', '/search/', function () use ($subject) {
                    $this->renderer->displayPathSearch($subject);
                });
            }

            // Routes: download a zipped directory
            $routes->addRoute('GET', '/download/{path:.*}', function (string $path) {
                $this->renderer->downloadDirectoryZip($path);
            });

            // Routes: view directory index for path
            $routes->addRoute('GET', '/{path:.*}', function (string $path) {
                $this->renderer->displayPath($path);
            });
        };
    }

    public function dispatch(): void
    {
        if ($this->dispatcher === null || $this->renderer === null) {
            throw new RuntimeException('The Router must be setup before it can dispatch requests, but it isn\'t', 500);
        }

        // Fetch method and URI from somewhere
        /** @var string $httpMethod */
        $httpMethod = $this->http->get('REQUEST_METHOD', [Http::SERVER]);
        $location = $this->http->getLocation($this->config->URLLocationPath);

        // Strip query string (?foo=bar) and decode URI
        $location = sprintf('/%s', ltrim($location, '/'));
        if (false !== $pos = strpos($location, '?')) {
            $location = substr($location, 0, $pos);
        }
        $location = rawurldecode($location);

        $routeInfo = $this->dispatcher->dispatch($httpMethod, $location);

        switch ($routeInfo[0]) {

            case FastRoute\Dispatcher::NOT_FOUND:
                $this->renderer->displayError(new NotFoundException("Page not found: {$location}"));
                break;

            case FastRoute\Dispatcher::METHOD_NOT_ALLOWED:
                $allowedMethods = implode(', ', (array)$routeInfo[1]);
                $this->renderer->displayError(
                    new RoutingException('Method not Allowed.', 405, new Exception("Allowed for this route are: {$allowedMethods}"))
                );
                break;

            case FastRoute\Dispatcher::FOUND:
                $handler = $routeInfo[1];
                $vars = $routeInfo[2];
                call_user_func_array($handler, $vars);
                break;
        }
    }

    /**
     * @param Throwable $throwable
     */
    public function handleException(Throwable $throwable): void
    {
        $renderer = $this->renderer;
        if ($renderer === null) {
            $renderer = new Renderer($this->config, $this->http, $this->cache);
        }
        $renderer->displayError($throwable);
    }
}
