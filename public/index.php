<?php

require_once __DIR__ . '/../vendor/autoload.php';

use ricwein\Indexer\Config\Config;
use ricwein\Indexer\Core\Router;

$config = Config::getInstance();
$router = new Router($config);

try {
    $router->setup();
    $router->dispatch();
} catch (Exception|Error $exception) {
    $router->handleException($exception);
}

