<?php

declare(strict_types=1);

/*
 * WordPress migration source.
 *
 * The source is a foreign database, so it is configured separately from the platform's
 * own connection. `source_dsn` empty means "the platform database, with the prefix below",
 * which is the common case when WordPress and this CMS share one database during a
 * cutover.
 *
 * This file exists so the values are actually settable. Before it, the migration provider
 * read `migration.*` from config and silently used defaults -- an operator could set an env
 * var and see no effect.
 */
return [
    // e.g. 'mysql:host=localhost;dbname=wordpress;charset=utf8mb4'
    'source_dsn' => '',
    'source_username' => '',
    'source_password' => '',
    'source_prefix' => 'wp_',

    // Where the old site's uploads live. Media is only imported when the file is present
    // here; a row with no file would render a broken image forever.
    'uploads_path' => '',
];
