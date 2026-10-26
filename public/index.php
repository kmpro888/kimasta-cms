<?php

declare(strict_types=1);

use CMS\Core\Http\Request;

/** @var \CMS\Core\Bootstrap\Application $app */
$app = require dirname(__DIR__) . '/app/bootstrap.php';

$response = $app->handle(Request::fromGlobals());

$response->send();