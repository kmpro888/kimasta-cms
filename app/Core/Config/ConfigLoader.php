<?php

declare(strict_types=1);

namespace CMS\Core\Config;

/**
 * Builds a Config instance from /config/*.php plus .env overlays.
 *
 * Environment variables are resolved once, here, so that runtime modules
 * never call getenv() and configuration stays a pure value object.
 */
final class ConfigLoader
{
    public function __construct(
        private string $configPath,
        private Env $env,
    ) {
    }

    /**
     * @param list<string> $only Restrict loading to these logical names.
     */
    public function load(array $only = []): Config
    {
        $items = [];

        foreach ($this->fileNames($only) as $name) {
            $file = $this->configPath . DIRECTORY_SEPARATOR . $name . '.php';

            if (!is_file($file)) {
                continue;
            }

            $value = require $file;

            if (!is_array($value)) {
                continue;
            }

            $items[$name] = $value;
        }

        $items = $this->applyEnvironment($items);

        return new Config($items);
    }

    /**
     * @param list<string> $only
     * @return list<string>
     */
    private function fileNames(array $only): array
    {
        if ($only !== []) {
            return array_values(array_unique($only));
        }

        $files = glob($this->configPath . DIRECTORY_SEPARATOR . '*.php') ?: [];
        $names = [];

        foreach ($files as $file) {
            $names[] = pathinfo($file, PATHINFO_FILENAME);
        }

        sort($names);

        return $names;
    }

    /**
     * @param array<string, mixed> $items
     * @return array<string, mixed>
     */
    private function applyEnvironment(array $items): array
    {
        $env = $this->env;

        $app = is_array($items['app'] ?? null) ? $items['app'] : [];
        $app['env'] = $env->get('APP_ENV', (string) ($app['env'] ?? 'production'));
        $app['debug'] = $env->bool('APP_DEBUG', (bool) ($app['debug'] ?? false));
        $app['key'] = $env->get('APP_KEY', (string) ($app['key'] ?? ''));
        $app['url'] = rtrim($env->get('APP_URL', (string) ($app['url'] ?? '')), '/');
        $app['timezone'] = $env->get('APP_TIMEZONE', (string) ($app['timezone'] ?? 'UTC'));
        $app['base_path'] = $env->get('APP_BASE_PATH', (string) ($app['base_path'] ?? ''));
        $app['storage_path'] = $env->get('STORAGE_PATH', (string) ($app['storage_path'] ?? ''));
        $items['app'] = $app;

        $database = is_array($items['database'] ?? null) ? $items['database'] : [];
        $database['host'] = $env->get('DB_HOST', (string) ($database['host'] ?? 'localhost'));
        $database['port'] = $env->int('DB_PORT', (int) ($database['port'] ?? 3306));
        $database['database'] = $env->get('DB_DATABASE', (string) ($database['database'] ?? ''));
        $database['username'] = $env->get('DB_USERNAME', (string) ($database['username'] ?? ''));
        $database['password'] = $env->get('DB_PASSWORD', (string) ($database['password'] ?? ''));
        $database['charset'] = $env->get('DB_CHARSET', (string) ($database['charset'] ?? 'utf8mb4'));
        $items['database'] = $database;

        $logging = is_array($items['logging'] ?? null) ? $items['logging'] : [];
        $logging['level'] = $env->get('LOG_LEVEL', (string) ($logging['level'] ?? 'warning'));
        $logging['path'] = $env->get('LOG_PATH', (string) ($logging['path'] ?? ''));
        $items['logging'] = $logging;

        $security = is_array($items['security'] ?? null) ? $items['security'] : [];
        $security['session_lifetime'] = $env->int(
            'SESSION_LIFETIME',
            (int) ($security['session_lifetime'] ?? 120)
        );
        $items['security'] = $security;

        // Migration source. A foreign database and its uploads path are deployment facts,
        // not code, so they belong in the environment like the platform credentials.
        $migration = is_array($items['migration'] ?? null) ? $items['migration'] : [];
        $migration['source_dsn'] = $env->get('MIGRATION_SOURCE_DSN', (string) ($migration['source_dsn'] ?? ''));
        $migration['source_username'] = $env->get('MIGRATION_SOURCE_USERNAME', (string) ($migration['source_username'] ?? ''));
        $migration['source_password'] = $env->get('MIGRATION_SOURCE_PASSWORD', (string) ($migration['source_password'] ?? ''));
        $migration['source_prefix'] = $env->get('MIGRATION_SOURCE_PREFIX', (string) ($migration['source_prefix'] ?? 'wp_'));
        $migration['uploads_path'] = $env->get('MIGRATION_UPLOADS_PATH', (string) ($migration['uploads_path'] ?? ''));
        $items['migration'] = $migration;

        $mail = is_array($items['mail'] ?? null) ? $items['mail'] : [];
        $mail['from_address'] = $env->get('MAIL_FROM_ADDRESS', (string) ($mail['from_address'] ?? ''));
        $mail['from_name'] = $env->get('MAIL_FROM_NAME', (string) ($mail['from_name'] ?? ''));
        $items['mail'] = $mail;

        return $items;
    }
}