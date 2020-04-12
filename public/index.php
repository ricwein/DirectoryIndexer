<?php

require_once __DIR__ . '/../vendor/autoload.php';

use FastRoute\RouteCollector;
use ricwein\FileSystem\Exceptions\FileNotFoundException;
use ricwein\DirectoryIndex\Config\Config;
use ricwein\DirectoryIndex\Core\Renderer;
use ricwein\DirectoryIndex\Core\Router;
use ricwein\FileSystem\File;
use ricwein\FileSystem\Storage;
use ricwein\Templater\Exceptions\RenderingException;
use ricwein\Templater\Exceptions\TemplatingException;
use ricwein\Templater\Exceptions\UnexpectedValueException;

try {
    $config = Config::getInstance();
} catch (FileNotFoundException|UnexpectedValueException $error) {
    Renderer::displayFatalError($error);
}

$router = new Router($config);

try {
    $router->setup();
    $router->dispatch();
} catch (Exception|Error $exception) {
    $router->handleException($exception);
}

