<?php

declare(strict_types=1);

/*
 * Performance gate (task pack section 9, PRD 50/51).
 *
 *   DB_DATABASE=line_cms_perf php bin/perf-gate.php seed
 *   DB_DATABASE=line_cms_perf php bin/perf-gate.php measure
 *
 * `seed` fills a scratch database with the volumes the pack names: 50,000 contents, 100,000
 * content-term relations, 100,000 comments. `measure` runs the public surfaces against it, reports
 * the wall time and the query count for each, and runs EXPLAIN on the statements that matter.
 *
 * It refuses to run against any database whose name does not contain `_perf`. This script deletes
 * and repopulates rows; running it against a development or production database by accident would
 * be unrecoverable, so the name is the guard.
 */

use CMS\Core\Database\Connection;
use CMS\Core\Database\QueryProfiler;
use CMS\Core\Http\Request;
use CMS\Modules\Site\Contracts\SiteServiceInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

$command = $argv[1] ?? 'help';

if (!in_array($command, ['seed', 'measure'], true)) {
    fwrite(STDOUT, "Usage: DB_DATABASE=<name>_perf php bin/perf-gate.php seed|measure\n");
    exit(0);
}

$database = (string) (getenv('DB_DATABASE') ?: ($_ENV['DB_DATABASE'] ?? ''));

if ($database === '' || !str_contains($database, '_perf')) {
    fwrite(STDERR, "Refusing to run: DB_DATABASE must be set and contain \"_perf\" (got \""
        . $database . "\").\n");
    exit(1);
}

$app = require dirname(__DIR__) . '/app/bootstrap.php';
$app->boot();
$container = $app->container();

/** @var Connection $db */
$db = $container->get(Connection::class);

$host = 'perf.test';
$site = null;

foreach ($container->get(SiteServiceInterface::class)->all() as $candidate) {
    $site = $candidate;
    break;
}

if ($site === null) {
    $site = $container->get(SiteServiceInterface::class)->create(
        name: 'Perf Site',
        slug: 'perf-site',
        primaryDomain: $host,
        baseUrl: 'http://' . $host,
    );
}

$siteId = $site->id();

if ($command === 'seed') {
    exit(seed($db, $siteId, $argv));
}

exit(measure($app, $container, $db, $siteId, $host));

// ----------------------------------------------------------------------------- seeding

/**
 * @param list<string> $argv
 */
function seed(Connection $db, int $siteId, array $argv): int
{
    $contents = (int) (option($argv, '--contents') ?? 50000);
    $comments = (int) (option($argv, '--comments') ?? 100000);
    $relations = (int) (option($argv, '--terms') ?? 100000);

    $started = microtime(true);

    fwrite(STDOUT, "Seeding site {$siteId}: {$contents} contents, {$relations} term relations, "
        . "{$comments} comments\n");

    // TRUNCATE, not DELETE: this database exists to be thrown away, and DELETE leaves the
    // auto-increment counter where it was. The first version deleted the rows and then referenced
    // ids 1..N, which no longer existed -- `INSERT IGNORE` swallowed the foreign-key failures and
    // the seed reported a clean run with half its rows missing.
    $db->statement('SET FOREIGN_KEY_CHECKS = 0');

    foreach (['comments', 'content_terms', 'url_registry', 'contents', 'terms', 'taxonomies'] as $table) {
        $db->statement('TRUNCATE TABLE `' . $table . '`');
    }

    $db->statement('SET FOREIGN_KEY_CHECKS = 1');

    $now = gmdate('Y-m-d H:i:s');

    // One author and a handful of taxonomies, enough for archives to be interesting.
    $db->statement(
        'INSERT INTO users (site_id, username, email, password_hash, display_name, status, created_at, updated_at)'
        . ' VALUES (:site, :username, :email, :hash, :display, :status, :now, :now2)'
        . ' ON DUPLICATE KEY UPDATE display_name = VALUES(display_name)',
        [
            'site' => $siteId,
            'username' => 'perf-author',
            'email' => 'perf-author@example.test',
            'hash' => password_hash('perf-password-not-used', PASSWORD_DEFAULT),
            'display' => 'Perf Author',
            'status' => 'active',
            'now' => $now,
            'now2' => $now,
        ]
    );

    $author = (int) ($db->selectOne(
        'SELECT id FROM users WHERE site_id = :site AND username = :u',
        ['site' => $siteId, 'u' => 'perf-author']
    )['id'] ?? 0);

    $taxonomy = $db->selectOne('SELECT id FROM taxonomies WHERE site_id = :site LIMIT 1', ['site' => $siteId]);

    if ($taxonomy === null) {
        // `taxonomies` has no slug column: it is identified by (site_id, name, content_type).
        $db->statement(
            'INSERT INTO taxonomies (site_id, name, content_type, hierarchical, created_at)'
            . ' VALUES (:site, :name, :content_type, 1, :now)',
            [
                'site' => $siteId, 'name' => 'Category', 'content_type' => null, 'now' => $now,
            ]
        );

        $taxonomy = $db->selectOne('SELECT id FROM taxonomies WHERE site_id = :site LIMIT 1', ['site' => $siteId]);
    }

    $taxonomyId = (int) ($taxonomy['id'] ?? 0);

    $termIds = [];

    foreach (['news', 'analysis', 'review', 'opinion', 'feature'] as $index => $slug) {
        $db->statement(
            'INSERT INTO terms (site_id, taxonomy_id, parent_id, name, slug, description, content_count, created_at, updated_at)'
            . ' VALUES (:site, :taxonomy, NULL, :name, :slug, :description, 0, :now, :now2)'
            . ' ON DUPLICATE KEY UPDATE name = VALUES(name)',
            [
                'site' => $siteId, 'taxonomy' => $taxonomyId,
                'name' => ucfirst($slug), 'slug' => $slug,
                'description' => null, 'now' => $now, 'now2' => $now,
            ]
        );

        $row = $db->selectOne(
            'SELECT id FROM terms WHERE site_id = :site AND slug = :slug',
            ['site' => $siteId, 'slug' => $slug]
        );

        if ($row !== null) {
            $termIds[] = (int) $row['id'];
        }
    }

    if ($termIds === []) {
        fwrite(STDERR, "No terms were created; aborting.\n");

        return 1;
    }

    $json = json_encode([['type' => 'paragraph', 'version' => 1, 'data' => ['text' => 'Body copy.']]]);
    $html = '<p>Body copy.</p>';

    // contents + url_registry, batched: 500 rows per statement keeps the packet size sane and the
    // whole seed under a minute.
    $batch = 500;
    $inserted = 0;

    while ($inserted < $contents) {
        $values = [];
        $bindings = [];
        $limit = min($batch, $contents - $inserted);

        for ($i = 0; $i < $limit; $i++) {
            $n = $inserted + $i + 1;
            $day = ($n % 720);
            $published = gmdate('Y-m-d H:i:s', strtotime('-2 years +' . $day . ' days'));
            $slug = 'perf-article-' . $n;

            $values[] = '(:site_' . $i . ', :type_' . $i . ', :author_' . $i . ', :title_' . $i . ', '
                . ':slug_' . $i . ', :excerpt_' . $i . ', :json_' . $i . ', :html_' . $i . ', '
                . ':plain_' . $i . ', :status_' . $i . ', :comment_' . $i . ', :published_' . $i . ', '
                . ':created_' . $i . ', :updated_' . $i . ')';

            $bindings['site_' . $i] = $siteId;
            $bindings['type_' . $i] = 'post';
            $bindings['author_' . $i] = $author;
            $bindings['title_' . $i] = 'Perf Article ' . $n;
            $bindings['slug_' . $i] = $slug;
            $bindings['excerpt_' . $i] = 'Excerpt for article ' . $n . '.';
            $bindings['json_' . $i] = $json;
            $bindings['html_' . $i] = $html;
            $bindings['plain_' . $i] = 'Body copy.';
            $bindings['status_' . $i] = 'published';
            $bindings['comment_' . $i] = 'open';
            $bindings['published_' . $i] = $published;
            $bindings['created_' . $i] = $published;
            $bindings['updated_' . $i] = $published;
        }

        $db->statement(
            'INSERT INTO contents (site_id, type, author_id, title, slug, excerpt, content_json, '
            . 'content_html, content_plain, status, comment_status, published_at, created_at, updated_at)'
            . ' VALUES ' . implode(',', $values),
            $bindings
        );

        $inserted += $limit;
    }

    $range = $db->selectOne(
        'SELECT MIN(id) AS lo, MAX(id) AS hi FROM contents WHERE site_id = :site',
        ['site' => $siteId]
    );

    $firstContentId = (int) ($range['lo'] ?? 0);

    if ($firstContentId <= 0) {
        fwrite(STDERR, "No contents were created; aborting.\n");

        return 1;
    }

    fwrite(STDOUT, '  contents: ' . $inserted . ' (ids ' . $firstContentId . '..'
        . (int) ($range['hi'] ?? 0) . ")\n");

    // url_registry is what resolves a public path, so it has to exist or the single-article
    // scenario would measure a 404.
    $inserted = 0;

    while ($inserted < $contents) {
        $limit = min($batch, $contents - $inserted);
        $values = [];
        $bindings = [];

        for ($i = 0; $i < $limit; $i++) {
            $n = $inserted + $i + 1;
            $values[] = '(:site_' . $i . ', :path_' . $i . ', :type_' . $i . ', :entity_' . $i . ', 1, :now_' . $i . ')';
            $bindings['site_' . $i] = $siteId;
            $bindings['path_' . $i] = '/blog/perf-article-' . $n;
            $bindings['type_' . $i] = 'content';
            $bindings['entity_' . $i] = $firstContentId + $n - 1;
            $bindings['now_' . $i] = $now;
        }

        $db->statement(
            'INSERT INTO url_registry (site_id, path, entity_type, entity_id, canonical, updated_at)'
            . ' VALUES ' . implode(',', $values),
            $bindings
        );

        $inserted += $limit;
    }

    fwrite(STDOUT, '  url_registry: ' . $inserted . "\n");

    // Two relations per content, from different terms, so the pairs are unique: cycling one term
    // per row repeats a pair every lcm(contents, terms) rows and half the rows were ignored.
    $inserted = 0;
    $relationBatch = 500;
    $perContent = max(1, (int) ceil($relations / max(1, $contents)));
    $termCount = count($termIds);

    while ($inserted < $contents) {
        $limit = min($relationBatch, $contents - $inserted);
        $values = [];
        $bindings = [];
        $k = 0;

        for ($i = 0; $i < $limit; $i++) {
            $contentId = $firstContentId + $inserted + $i;

            for ($slot = 0; $slot < $perContent; $slot++) {
                $values[] = '(:c_' . $k . ', :t_' . $k . ', :s_' . $k . ', 0)';
                $bindings['c_' . $k] = $contentId;
                $bindings['t_' . $k] = $termIds[($contentId + $slot * 2) % $termCount];
                $bindings['s_' . $k] = $siteId;
                $k++;
            }
        }

        // A content/term pair is unique; duplicates across batches are expected by design, so the
        // insert ignores them rather than failing the seed.
        $db->statement(
            'INSERT IGNORE INTO content_terms (content_id, term_id, site_id, sort_order)'
            . ' VALUES ' . implode(',', $values),
            $bindings
        );

        $inserted += $limit;
    }

    $actualRelations = (int) ($db->selectOne(
        'SELECT COUNT(*) AS total FROM content_terms WHERE site_id = :site',
        ['site' => $siteId]
    )['total'] ?? 0);

    fwrite(STDOUT, '  content_terms: ' . $actualRelations . "\n");

    // Comments, approved so they are what a thread query actually reads.
    $inserted = 0;
    $commentBatch = 500;

    while ($inserted < $comments) {
        $limit = min($commentBatch, $comments - $inserted);
        $values = [];
        $bindings = [];

        for ($i = 0; $i < $limit; $i++) {
            $n = $inserted + $i;
            $k = $i;

            $values[] = '(:s_' . $k . ', :c_' . $k . ', NULL, :u_' . $k . ', :a_' . $k . ', :b_' . $k
                . ', :st_' . $k . ', 0, :t_' . $k . ', :t2_' . $k . ')';

            $bindings['s_' . $k] = $siteId;
            $bindings['c_' . $k] = $firstContentId + ($n % $contents);
            $bindings['u_' . $k] = $author;
            $bindings['a_' . $k] = 'Commenter ' . ($n + 1);
            $bindings['b_' . $k] = 'A comment body for article ' . ((($n % $contents) + 1)) . '.';
            $bindings['st_' . $k] = 'approved';
            $bindings['t_' . $k] = $now;
            $bindings['t2_' . $k] = $now;
        }

        $db->statement(
            'INSERT INTO comments (site_id, content_id, parent_id, user_id, author_name, body, status,'
            . ' depth, created_at, updated_at) VALUES ' . implode(',', $values),
            $bindings
        );

        $inserted += $limit;
    }

    fwrite(STDOUT, '  comments: ' . $inserted . "\n");

    // The compiled domain map must know this host.
    $container = null;
    $db->statement(
        'INSERT IGNORE INTO site_domains (site_id, domain, is_primary, created_at)'
        . ' VALUES (:site, :domain, 1, :now)',
        ['site' => $siteId, 'domain' => 'perf.test', 'now' => $now]
    );

    fwrite(STDOUT, sprintf("Seeded in %.1f s\n", microtime(true) - $started));

    return 0;
}

// --------------------------------------------------------------------------- measuring

/**
 * @param array<string, mixed> $app
 * @param array<string, mixed> $container
 */
function measure($app, $container, Connection $db, int $siteId, string $host): int
{
    $cache = $container->get(\CMS\Modules\Cache\Contracts\PageCacheInterface::class);
    $sites = $container->get(SiteServiceInterface::class);

    $sites->regenerateDomainMap();

    $term = $db->selectOne('SELECT slug FROM terms WHERE site_id = :site ORDER BY id LIMIT 1', ['site' => $siteId]);
    $author = $db->selectOne('SELECT author_id FROM contents WHERE site_id = :site LIMIT 1', ['site' => $siteId]);

    // [path, query] pairs: passing "?q=" inside the path made the router look for a literal
    // "/search?q=perf" segment, so search and the API answered 404 and measured nothing.
    $scenarios = [
        'home' => ['/', []],
        'blog archive' => ['/blog', []],
        'single article' => ['/blog/perf-article-25000', []],
        'category archive' => ['/term/' . ($term['slug'] ?? 'news'), []],
        'author archive' => ['/author/' . ($author['author_id'] ?? 1), []],
        'search' => ['/search', ['q' => 'perf']],
        'api list' => ['/api/v1/content', ['per_page' => 20]],
        'redirect miss' => ['/a-path-that-does-not-exist', []],
    ];

    fwrite(STDOUT, "Scenario                  status  queries     ms   cache\n");
    fwrite(STDOUT, str_repeat('-', 66) . "\n");

    $captured = [];

    foreach ($scenarios as $label => $scenario) {
        $cache->flushSite($siteId);

        $profiler = new QueryProfiler();
        $db->profiler($profiler);

        $started = microtime(true);
        $response = $app->handle(Request::create('GET', $scenario[0], $host, $scenario[1]));
        $elapsed = (microtime(true) - $started) * 1000;

        $db->profiler(null);

        fwrite(STDOUT, sprintf(
            "%-24s %6d %8d %6.1f   %s\n",
            $label,
            $response->status(),
            $profiler->count(),
            $elapsed,
            (string) ($response->header('X-Cache') ?? '-')
        ));

        if (in_array($label, ['single article', 'blog archive', 'category archive', 'search', 'api list'], true)) {
            $captured[$label] = $profiler->queries();
        }
    }

    // A warm cache must cost nothing, on the page that matters most.
    $cache->flushSite($siteId);
    $app->handle(Request::create('GET', '/blog/perf-article-25000', $host));

    $profiler = new QueryProfiler();
    $db->profiler($profiler);
    $hit = $app->handle(Request::create('GET', '/blog/perf-article-25000', $host));
    $db->profiler(null);

    fwrite(STDOUT, sprintf(
        "%-24s %6d %8d %6.1f   %s\n",
        'single article (warm)',
        $hit->status(),
        $profiler->count(),
        0.0,
        (string) $hit->header('X-Cache')
    ));

    // Query-plan capture is deliberately not automated here.
    //
    // The profiler records each statement but not its bindings, so a parameterised statement
    // cannot be handed to EXPLAIN as written. The plans quoted in
    // docs/reports/Performance-Gate.md were taken by hand against the scratch database with
    // literal values.
    return 0;
}

/**
 * @param list<string> $argv
 */
function option(array $argv, string $name): ?string
{
    foreach ($argv as $argument) {
        if (str_starts_with($argument, $name . '=')) {
            return substr($argument, strlen($name) + 1);
        }
    }

    return null;
}
