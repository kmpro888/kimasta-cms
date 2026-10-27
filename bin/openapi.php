<?php

declare(strict_types=1);

/*
 * Writes the OpenAPI description of the API from the routes the application actually registers.
 *
 *   php bin/openapi.php
 *
 * The output is committed, and a contract test fails when it no longer matches the router. That way
 * the document is either correct or the build is red — there is no state in which it is quietly
 * out of date.
 */

use CMS\Core\Routing\Router;
use CMS\Modules\Api\Service\OpenApiDocument;

require dirname(__DIR__) . '/vendor/autoload.php';

$app = require dirname(__DIR__) . '/app/bootstrap.php';
$app->boot();

$container = $app->container();

/** @var Router $router */
$router = $container->get(Router::class);

$version = '1.0.0';

$document = new OpenApiDocument($router, $version);
$spec = $document->build();

$target = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'openapi.json';
@mkdir(dirname($target), 0775, true);

$json = json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

if ($json === false) {
    fwrite(STDERR, "Could not encode the document.\n");
    exit(1);
}

file_put_contents($target, $json . "\n");

$operations = 0;

foreach ($spec['paths'] as $path) {
    $operations += count($path);
}

fwrite(STDOUT, sprintf(
    "Wrote %s: %d path(s), %d operation(s).\n",
    'docs/api/openapi.json',
    count($spec['paths']),
    $operations
));
