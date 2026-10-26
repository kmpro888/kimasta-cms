<?php

declare(strict_types=1);

/*
 * PHPUnit bootstrap.
 *
 * Loads the Composer autoloader and defines a fixed test root so tests never
 * depend on the process working directory.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

if (!defined('CMS_TEST_ROOT')) {
    define('CMS_TEST_ROOT', dirname(__DIR__));
}

if (!defined('CMS_TEST_STORAGE')) {
    define('CMS_TEST_STORAGE', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cms_tests_' . getmypid());
}

date_default_timezone_set('UTC');
