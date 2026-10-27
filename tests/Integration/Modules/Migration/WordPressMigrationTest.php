<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Migration;

use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Migration\Contracts\MigrationServiceInterface;
use CMS\Modules\Migration\Repository\MigrationRepositoryInterface;
use CMS\Modules\Migration\Service\MigrationService;
use CMS\Modules\Migration\Source\WordPressSource;
use CMS\Modules\Seo\Contracts\RedirectServiceInterface;
use CMS\Modules\Taxonomy\Contracts\TaxonomyServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AppTestCase;

/**
 * WordPress migration against a synthetic source.
 *
 * The suite creates real `wp_*` tables, so the reader, the mappers and the idempotency
 * bookkeeping are all exercised end to end rather than mocked.
 */
#[CoversClass(MigrationService::class)]
#[CoversClass(WordPressSource::class)]
final class WordPressMigrationTest extends AppTestCase
{
    private MigrationServiceInterface $migration;

    private string $uploadsPath;

    protected function setUp(): void
    {
        parent::setUp();

        // The uploads directory must exist before the fixture seeds an image into it.
        $this->uploadsPath = $this->path('wp-uploads');
        @mkdir($this->uploadsPath . '/2020/01', 0775, true);

        $this->createSourceSchema();
        $this->seedSource();

        $this->migration = $this->buildService();
    }

    /**
     * Build the service with an explicit uploads path and a predictable run id.
     */
    private function buildService(?string $uploadsPath = null): MigrationServiceInterface
    {
        $c = $this->app->container();

        return new MigrationService(
            $c->get(WordPressSource::class),
            $c->get(MigrationRepositoryInterface::class),
            $c->get(ContentServiceInterface::class),
            $c->get(TaxonomyServiceInterface::class),
            $c->get(\CMS\Modules\Media\Contracts\MediaServiceInterface::class),
            $c->get(\CMS\Modules\Comment\Contracts\CommentServiceInterface::class),
            $c->get(\CMS\Modules\Identity\Contracts\UserServiceInterface::class),
            $c->get(\CMS\Modules\Seo\Contracts\SeoServiceInterface::class),
            $c->get(RedirectServiceInterface::class),
            $c->get(\CMS\Modules\Content\Contracts\UrlGeneratorInterface::class),
            $c->get(\CMS\Modules\Site\Contracts\SiteServiceInterface::class),
            $uploadsPath ?? $this->uploadsPath,
            'run_test',
        );
    }

    // ------------------------------------------------------------ source fixture

    private function createSourceSchema(): void
    {
        foreach ([
            'DROP TABLE IF EXISTS `wp_posts`',
            'DROP TABLE IF EXISTS `wp_postmeta`',
            'DROP TABLE IF EXISTS `wp_users`',
            'DROP TABLE IF EXISTS `wp_terms`',
            'DROP TABLE IF EXISTS `wp_term_taxonomy`',
            'DROP TABLE IF EXISTS `wp_term_relationships`',
            'DROP TABLE IF EXISTS `wp_comments`',
            'DROP TABLE IF EXISTS `wp_options`',
        ] as $sql) {
            $this->db->statement($sql);
        }

        $this->db->statement(
            'CREATE TABLE `wp_posts` (
                ID BIGINT UNSIGNED PRIMARY KEY,
                post_author BIGINT UNSIGNED NOT NULL DEFAULT 0,
                post_date DATETIME NOT NULL,
                post_date_gmt DATETIME NOT NULL,
                post_modified DATETIME NOT NULL,
                post_title TEXT NOT NULL,
                post_name VARCHAR(200) NOT NULL,
                post_excerpt TEXT NOT NULL,
                post_content LONGTEXT NOT NULL,
                post_status VARCHAR(20) NOT NULL,
                post_type VARCHAR(20) NOT NULL,
                post_mime_type VARCHAR(100) NOT NULL DEFAULT \'\',
                post_parent BIGINT UNSIGNED NOT NULL DEFAULT 0,
                guid VARCHAR(255) NOT NULL DEFAULT \'\',
                comment_status VARCHAR(20) NOT NULL DEFAULT \'open\'
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $this->db->statement(
            'CREATE TABLE `wp_postmeta` (
                meta_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                post_id BIGINT UNSIGNED NOT NULL,
                meta_key VARCHAR(255) NOT NULL,
                meta_value LONGTEXT NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $this->db->statement(
            'CREATE TABLE `wp_users` (
                ID BIGINT UNSIGNED PRIMARY KEY,
                user_login VARCHAR(60) NOT NULL,
                user_email VARCHAR(100) NOT NULL,
                display_name VARCHAR(250) NOT NULL,
                user_registered DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $this->db->statement(
            'CREATE TABLE `wp_terms` (
                term_id BIGINT UNSIGNED PRIMARY KEY,
                name VARCHAR(200) NOT NULL,
                slug VARCHAR(200) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $this->db->statement(
            'CREATE TABLE `wp_term_taxonomy` (
                term_taxonomy_id BIGINT UNSIGNED PRIMARY KEY,
                term_id BIGINT UNSIGNED NOT NULL,
                taxonomy VARCHAR(32) NOT NULL,
                description LONGTEXT NOT NULL,
                parent BIGINT UNSIGNED NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $this->db->statement(
            'CREATE TABLE `wp_term_relationships` (
                object_id BIGINT UNSIGNED NOT NULL,
                term_taxonomy_id BIGINT UNSIGNED NOT NULL,
                PRIMARY KEY (object_id, term_taxonomy_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $this->db->statement(
            'CREATE TABLE `wp_comments` (
                comment_ID BIGINT UNSIGNED PRIMARY KEY,
                comment_post_ID BIGINT UNSIGNED NOT NULL,
                comment_author VARCHAR(200) NOT NULL,
                comment_author_email VARCHAR(100) NOT NULL,
                comment_date DATETIME NOT NULL,
                comment_content TEXT NOT NULL,
                comment_approved VARCHAR(20) NOT NULL,
                comment_parent BIGINT UNSIGNED NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $this->db->statement(
            'CREATE TABLE `wp_options` (
                option_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                option_name VARCHAR(191) NOT NULL,
                option_value LONGTEXT NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    private function seedSource(): void
    {
        $this->db->statement(
            'INSERT INTO wp_users (ID, user_login, user_email, display_name, user_registered) VALUES
             (1, \'jane\', \'jane@old.test\', \'Jane Writer\', \'2018-01-01 00:00:00\'),
             (2, \'bob\', \'bob@old.test\', \'Bob Editor\', \'2018-02-01 00:00:00\')'
        );

        $this->db->statement(
            'INSERT INTO wp_terms (term_id, name, slug) VALUES
             (1, \'News\', \'news\'),
             (2, \'PHP\', \'php\')'
        );

        $this->db->statement(
            'INSERT INTO wp_term_taxonomy (term_taxonomy_id, term_id, taxonomy, description, parent) VALUES
             (1, 1, \'category\', \'Site news\', 0),
             (2, 2, \'post_tag\', \'\', 0)'
        );

        // A real image so the media importer validates it through the actual pipeline.
        $image = imagecreatetruecolor(12, 12);
        imagepng($image, $this->uploadsPath . '/2020/01/pic.png');

        $this->db->statement(
            'INSERT INTO wp_posts (ID, post_author, post_date, post_date_gmt, post_modified, post_title, '
            . 'post_name, post_excerpt, post_content, post_status, post_type, post_mime_type, guid, comment_status) VALUES
             (1, 1, \'2019-05-01 10:00:00\', \'2019-05-01 10:00:00\', \'2019-05-01 10:00:00\', '
            . '\'Hello World\', \'hello-world\', \'The first post\', '
            . '\'<p>Body of the post</p><h2>A heading</h2>\', \'publish\', \'post\', \'\', \'\', \'open\'),
             (2, 1, \'2019-06-01 10:00:00\', \'2019-06-01 10:00:00\', \'2019-06-01 10:00:00\', '
            . '\'About Us\', \'about\', \'\', \'<p>About the site</p>\', \'publish\', \'page\', \'\', \'\', \'closed\'),
             (3, 1, \'2020-01-01 00:00:00\', \'2020-01-01 00:00:00\', \'2020-01-01 00:00:00\', '
            . '\'A picture\', \'pic\', \'\', \'\', \'inherit\', \'attachment\', \'image/png\', '
            . '\'https://old.test/wp-content/uploads/2020/01/pic.png\', \'closed\'),
             (4, 2, \'2019-07-01 10:00:00\', \'2019-07-01 10:00:00\', \'2019-07-01 10:00:00\', '
            . '\'Draft Idea\', \'draft-idea\', \'\', \'<p>Not ready</p>\', \'draft\', \'post\', \'\', \'\', \'open\')'
        );

        $this->db->statement(
            'INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES
             (1, \'_thumbnail_id\', \'3\'),
             (1, \'_yoast_wpseo_title\', \'Hello World - Custom SEO Title\'),
             (1, \'_yoast_wpseo_metadesc\', \'A hand written description.\'),
             (1, \'_yoast_wpseo_canonical\', \'https://old.test/hello-world\'),
             (1, \'_yoast_wpseo_meta-robots-noindex\', \'1\'),
             (1, \'_my_custom_field\', \'some value nobody mapped\')'
        );

        $this->db->statement(
            'INSERT INTO wp_term_relationships (object_id, term_taxonomy_id) VALUES (1, 1), (1, 2)'
        );

        $this->db->statement(
            'INSERT INTO wp_comments (comment_ID, comment_post_ID, comment_author, comment_author_email, '
            . 'comment_date, comment_content, comment_approved, comment_parent) VALUES
             (1, 1, \'Jane Writer\', \'jane@old.test\', \'2019-05-02 10:00:00\', \'A real comment.\', \'1\', 0),
             (2, 1, \'Guest Person\', \'guest@elsewhere.test\', \'2019-05-03 10:00:00\', \'A guest comment.\', \'1\', 0)'
        );

        // A permalink structure that differs from this platform's, which forces redirects.
        $this->db->statement(
            'INSERT INTO wp_options (option_name, option_value) VALUES (\'permalink_structure\', \'/archives/%postname%/\')'
        );
    }

    // ------------------------------------------------------------------ analysis

    #[Test]
    public function theSourceIsAnalysedPerType(): void
    {
        $analysis = $this->migration->analyze($this->siteId);

        self::assertSame(2, $analysis['user']['source_total']);
        self::assertSame(2, $analysis['term']['source_total']);
        self::assertSame(1, $analysis['media']['source_total']);
        self::assertSame(2, $analysis['post']['source_total']);
        self::assertSame(1, $analysis['page']['source_total']);
        self::assertSame(2, $analysis['comment']['source_total']);

        // Nothing imported yet.
        self::assertSame(2, $analysis['user']['pending']);
    }

    #[Test]
    public function importOrderPutsDependenciesFirst(): void
    {
        // Content needs its author, its terms and its featured image; comments need content.
        self::assertSame(['user', 'term', 'media', 'post', 'page', 'comment'], $this->migration->types());
    }

    // --------------------------------------------------------------- single rows

    #[Test]
    public function aUserIsImported(): void
    {
        $result = $this->migration->runBatch($this->siteId, 'user', 10);

        self::assertSame(2, $result['imported']);

        $users = $this->app->container()->get(\CMS\Modules\Identity\Contracts\UserServiceInterface::class)
            ->listForSite($this->siteId);

        $emails = array_map(static fn ($u): string => $u->email, $users);
        self::assertContains('jane@old.test', $emails);

        // The WordPress hash is not portable; the reset is recorded rather than hidden.
        $logs = $this->migration->logs($this->siteId, 'info', 50);
        self::assertNotEmpty($logs);
    }

    #[Test]
    public function categoriesAndTagsBecomeTerms(): void
    {
        $result = $this->migration->runBatch($this->siteId, 'term', 10);

        self::assertSame(2, $result['imported']);

        $taxonomy = $this->app->container()->get(TaxonomyServiceInterface::class);

        $categories = $taxonomy->listTerms($this->siteId, 'category');
        $tags = $taxonomy->listTerms($this->siteId, 'tag');

        self::assertCount(1, $categories);
        self::assertSame('News', $categories[0]->name);
        self::assertCount(1, $tags);
        self::assertSame('PHP', $tags[0]->name);
    }

    #[Test]
    public function anAttachmentBecomesMedia(): void
    {
        // Media needs an uploader, which is why `types()` puts users first.
        $this->migration->runBatch($this->siteId, 'user', 50);

        $result = $this->migration->runBatch($this->siteId, 'media', 10);

        self::assertSame(1, $result['imported'], 'Media import failed: ' . $this->firstLogReason());

        $row = $this->db->selectOne('SELECT COUNT(*) AS total FROM media');
        self::assertSame(1, (int) $row['total']);
    }

    #[Test]
    public function aMissingFileIsLoggedAndNoRowIsCreated(): void
    {
        // An empty uploads path means the file lives on the old server, which is the normal
        // cutover situation.
        $service = $this->buildService('');

        $result = $service->runBatch($this->siteId, 'media', 10);

        self::assertSame(0, $result['imported']);

        $row = $this->db->selectOne('SELECT COUNT(*) AS total FROM media');
        // A media row with no file would render a broken image on every page.
        self::assertSame(0, (int) $row['total']);

        $logs = $this->migration->logs($this->siteId, 'warning', 100);
        self::assertNotEmpty($logs);
    }

    #[Test]
    public function aPostIsImportedWithSlugDateHtmlTermsAndSeo(): void
    {
        $this->migration->runAll($this->siteId, 50);

        $contents = $this->app->container()->get(ContentServiceInterface::class);
        $post = $contents->getBySlug('hello-world', 'post', $this->siteId);

        self::assertSame('Hello World', $post->title);
        self::assertSame('The first post', $post->excerpt);
        self::assertSame('published', $post->status);

        // The HTML body is preserved byte for byte through the raw-HTML block.
        self::assertSame('<p>Body of the post</p><h2>A heading</h2>', $post->blocks()[0]['data']['html']);

        // Publish date comes from WordPress, not from "now".
        self::assertSame('2019-05-01', $post->publishedAt?->format('Y-m-d'));

        // Author is the migrated WordPress user.
        $author = $this->app->container()->get(\CMS\Modules\Identity\Contracts\UserServiceInterface::class)
            ->find($post->authorId, $this->siteId);
        self::assertNotNull($author);
        self::assertSame('jane@old.test', $author->email);

        // Both terms attached.
        $terms = $this->app->container()->get(TaxonomyServiceInterface::class)
            ->termsForContents($this->siteId, [(int) $post->id]);
        self::assertCount(2, $terms[(int) $post->id]);

        // Featured image resolved through the media map.
        self::assertNotNull($post->featuredMediaId);

        // Yoast carried across.
        $seo = $this->app->container()->get(\CMS\Modules\Seo\Contracts\SeoServiceInterface::class)
            ->metaFor((int) $post->id, $this->siteId);

        self::assertSame('Hello World - Custom SEO Title', $seo->title);
        self::assertSame('A hand written description.', $seo->description);
        self::assertSame('https://old.test/hello-world', $seo->canonical);
        self::assertFalse($seo->robotsIndex);
    }

    #[Test]
    public function aPageIsImportedAsAPage(): void
    {
        $this->migration->runAll($this->siteId, 50);

        $page = $this->app->container()->get(ContentServiceInterface::class)
            ->getBySlug('about', 'page', $this->siteId);

        self::assertSame('page', $page->type);
        self::assertSame('About Us', $page->title);
    }

    #[Test]
    public function aDraftStaysADraft(): void
    {
        $this->migration->runAll($this->siteId, 50);

        $draft = $this->app->container()->get(ContentServiceInterface::class)
            ->getBySlug('draft-idea', 'post', $this->siteId);

        self::assertSame('draft', $draft->status);
    }

    #[Test]
    public function approvedCommentsAreImportedAndGuestsBecomeAnonymous(): void
    {
        $this->migration->runAll($this->siteId, 50);

        $comments = $this->app->container()->get(\CMS\Modules\Comment\Contracts\CommentServiceInterface::class);
        $post = $this->app->container()->get(ContentServiceInterface::class)
            ->getBySlug('hello-world', 'post', $this->siteId);

        $thread = $comments->thread($this->siteId, (int) $post->id);

        // The registered commenter keeps their account; the guest has no account, and since this
        // platform accepts anonymous comments the comment is migrated as one instead of being
        // dropped. Its author name is the only identity WordPress stored.
        self::assertCount(2, $thread);

        $bodies = array_map(static fn ($c): string => $c->body, $thread);
        self::assertContains('A real comment.', $bodies);

        $guests = array_values(array_filter($thread, static fn ($c): bool => (int) $c->userId === 0));

        self::assertCount(1, $guests, 'The guest comment must be imported with user_id 0.');
        self::assertNotSame('', trim((string) $guests[0]->authorName), 'and must keep its author name.');
    }

    #[Test]
    public function unknownPostmetaIsLoggedRatherThanDiscarded(): void
    {
        $this->migration->runAll($this->siteId, 50);

        $logs = $this->migration->logs($this->siteId, 'warning', 200);

        $unmapped = array_values(array_filter(
            $logs,
            static fn (array $row): bool => $row['field'] === '_my_custom_field'
        ));

        self::assertCount(1, $unmapped);
        self::assertSame(1, (int) $unmapped[0]['source_id']);
        self::assertStringContainsString('Unmapped postmeta', (string) $unmapped[0]['reason']);
    }

    // --------------------------------------------------------------- idempotency

    #[Test]
    public function rerunningACompletedBatchImportsNothing(): void
    {
        // Users first: content needs a migrated author, which is exactly the dependency the
        // import order exists to satisfy.
        $this->migration->runBatch($this->siteId, 'user', 50);

        $first = $this->migration->runBatch($this->siteId, 'post', 50);
        self::assertSame(2, $first['imported']);

        $second = $this->migration->runBatch($this->siteId, 'post', 50);

        // The whole point of migration_maps: a rerun after a timeout must not duplicate.
        self::assertSame(0, $second['imported']);
        self::assertSame(2, $second['skipped']);

        $row = $this->db->selectOne('SELECT COUNT(*) AS total FROM contents WHERE type = :t', ['t' => 'post']);
        self::assertSame(2, (int) $row['total']);
    }

    #[Test]
    public function anInterruptedRunResumesWithoutDuplicating(): void
    {
        $this->migration->runBatch($this->siteId, 'user', 50);

        // Batch size 1 simulates a timeout after a single row.
        $first = $this->migration->runBatch($this->siteId, 'post', 1);
        self::assertSame(1, $first['imported']);
        self::assertFalse($first['done']);

        // Resume from the returned cursor.
        $second = $this->migration->runBatch($this->siteId, 'post', 50, $first['next_after']);
        self::assertSame(1, $second['imported']);

        $row = $this->db->selectOne('SELECT COUNT(*) AS total FROM contents WHERE type = :t', ['t' => 'post']);
        self::assertSame(2, (int) $row['total']);
    }

    #[Test]
    public function contentCannotBeImportedBeforeItsAuthor(): void
    {
        // Ordering is enforced by the data, not by hoping the caller behaves: with no users
        // on the target there is nothing to attribute the post to.
        $result = $this->migration->runBatch($this->siteId, 'post', 50);

        self::assertSame(0, $result['imported']);
        self::assertSame(2, $result['failed']);

        $row = $this->db->selectOne('SELECT COUNT(*) AS total FROM contents');
        self::assertSame(0, (int) $row['total']);
    }

    // ---------------------------------------------------------------------- urls

    #[Test]
    public function aChangedPermalinkStructureProducesRedirects(): void
    {
        $this->migration->runAll($this->siteId, 50);

        $redirects = $this->app->container()->get(RedirectServiceInterface::class)
            ->listForSite($this->siteId);

        $sources = array_column($redirects, 'source_path');

        // WordPress served posts from /archives/, this platform serves /blog/.
        self::assertContains('/archives/hello-world', $sources);

        $resolved = $this->app->container()->get(RedirectServiceInterface::class)
            ->resolve($this->siteId, '/archives/hello-world');

        self::assertNotNull($resolved);
        self::assertSame('/blog/hello-world', $resolved['target_url']);
        self::assertSame(301, $resolved['status_code']);
    }

    #[Test]
    public function aMatchingPermalinkStructurePreservesUrlsWithoutRedirects(): void
    {
        $this->db->statement('DELETE FROM wp_options');
        $this->db->statement(
            'INSERT INTO wp_options (option_name, option_value) VALUES (\'permalink_structure\', \'/blog/%postname%/\')'
        );

        $this->migration->runAll($this->siteId, 50);

        $post = $this->app->container()->get(ContentServiceInterface::class)
            ->getBySlug('hello-world', 'post', $this->siteId);

        $report = $this->migration->report($this->siteId);

        self::assertSame(0, $report['urls']['redirected']);
        self::assertGreaterThanOrEqual(1, $report['urls']['preserved']);
        self::assertSame('post', $post->type);
    }

    #[Test]
    public function theReportSummarisesCountsLogsAndUrls(): void
    {
        $this->migration->runAll($this->siteId, 50);

        $report = $this->migration->report($this->siteId);

        self::assertArrayHasKey('source', $report);
        self::assertArrayHasKey('counts', $report);
        self::assertArrayHasKey('logs', $report);
        self::assertArrayHasKey('urls', $report);
        self::assertTrue($report['source']['available']);
        self::assertSame('/archives/%postname%/', $report['source']['permalink_structure']);
        self::assertGreaterThanOrEqual(1, $report['urls']['redirected']);
    }

    // ----------------------------------------------------------------- multisite

    #[Test]
    public function aSecondSiteImportsIndependently(): void
    {
        $otherSite = $this->createSiteRow('other', 'other.example');

        $this->migration->runAll($this->siteId, 50);
        $this->migration->runAll($otherSite, 50);

        // Each tenant gets its own copies and its own mapping rows.
        $first = $this->db->selectOne(
            'SELECT COUNT(*) AS total FROM contents WHERE site_id = :s',
            ['s' => $this->siteId]
        );
        $second = $this->db->selectOne(
            'SELECT COUNT(*) AS total FROM contents WHERE site_id = :s',
            ['s' => $otherSite]
        );

        self::assertGreaterThan(0, (int) $first['total']);
        self::assertSame((int) $first['total'], (int) $second['total']);

        $maps = $this->db->selectOne('SELECT COUNT(*) AS total FROM migration_maps');
        $perSite = $this->db->selectOne(
            'SELECT COUNT(*) AS total FROM migration_maps WHERE site_id = :s',
            ['s' => $this->siteId]
        );

        // Two tenants, each with an identical set of mappings.
        self::assertGreaterThan(0, (int) $perSite['total']);
        self::assertSame(2 * (int) $perSite['total'], (int) $maps['total']);
    }

    /**
     * The newest warning reason, so a failing assertion can say why.
     */
    private function firstLogReason(): string
    {
        $logs = $this->migration->logs($this->siteId, 'warning', 5);

        return $logs === []
            ? '(no log)'
            : (string) $logs[0]['field'] . ': ' . (string) $logs[0]['reason'];
    }
}
