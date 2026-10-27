<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Migration;

use CMS\Modules\Comment\Contracts\CommentServiceInterface;
use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Migration\Contracts\MigrationServiceInterface;
use CMS\Modules\Migration\Repository\MigrationRepositoryInterface;
use CMS\Modules\Migration\Service\MigrationService;
use CMS\Modules\Migration\Source\WordPressSource;
use CMS\Modules\Seo\Contracts\RedirectServiceInterface;
use CMS\Modules\Seo\Contracts\SeoServiceInterface;
use CMS\Modules\Site\Contracts\SiteServiceInterface;
use CMS\Modules\Taxonomy\Contracts\TaxonomyServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AppTestCase;

/**
 * A migration against an export shaped like a real WordPress site.
 *
 * The existing fixture proves the importer agrees with itself. This one is shaped after what an
 * operator actually hands over: a multisite network with more than one set of tables, a category
 * tree three levels deep, a comment thread with replies, a guest commenter, `wp_usermeta` and
 * `wp_termmeta`, and the row types a real database contains but nobody reads (revisions,
 * nav_menu_items, attachments, drafts).
 *
 * Four defects were found by writing it, all of them silent data loss:
 *
 *   - the category tree was flattened (`parent` was read and discarded);
 *   - comment threads were flattened (`comment_parent` was read and discarded);
 *   - guest comments were dropped, though the platform had begun accepting anonymous comments;
 *   - `wp_usermeta` and `wp_termmeta` were never read, so nothing said they were left behind.
 */
#[CoversClass(MigrationService::class)]
final class RealisticExportTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema('wp_');
        $this->createSchema('wp_2_');

        // A real multisite database carries the network tables next to the per-blog ones.
        $this->insert('DROP TABLE IF EXISTS `wp_site`');
        $this->insert('CREATE TABLE `wp_site` (id INT PRIMARY KEY, domain VARCHAR(200), path VARCHAR(100))');
        $this->insert('DROP TABLE IF EXISTS `wp_blogs`');
        $this->insert('CREATE TABLE `wp_blogs` (blog_id INT PRIMARY KEY, site_id INT, domain VARCHAR(200), path VARCHAR(100))');

        $this->seedBlog(
            prefix: 'wp_',
            postId: 10,
            slug: 'network-post',
            comments: true,
        );

        $this->seedBlog(
            prefix: 'wp_2_',
            postId: 20,
            slug: 'second-blog-post',
            comments: false,
        );
    }

    private function createSchema(string $prefix): void
    {
        $tables = [
            'posts' => 'ID BIGINT UNSIGNED PRIMARY KEY, post_author BIGINT UNSIGNED NOT NULL, '
                . 'post_date DATETIME NULL, post_date_gmt DATETIME NULL, post_modified DATETIME NULL, '
                . 'post_title TEXT NULL, post_name VARCHAR(200) NULL, post_excerpt TEXT NULL, '
                . 'post_content LONGTEXT NULL, post_status VARCHAR(20) NULL, post_type VARCHAR(20) NULL, '
                . 'post_parent BIGINT UNSIGNED NOT NULL DEFAULT 0, guid VARCHAR(255) NULL, '
                . 'menu_order INT NOT NULL DEFAULT 0, post_mime_type VARCHAR(100) NULL, '
                . 'comment_status VARCHAR(20) NOT NULL DEFAULT \'open\', comment_count BIGINT NOT NULL DEFAULT 0, '
                . 'post_password VARCHAR(255) NOT NULL DEFAULT \'\'',
            'postmeta' => 'meta_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, post_id BIGINT UNSIGNED NOT NULL, '
                . 'meta_key VARCHAR(255) NULL, meta_value LONGTEXT NULL',
            'users' => 'ID BIGINT UNSIGNED PRIMARY KEY, user_login VARCHAR(60) NULL, user_email VARCHAR(100) NULL, '
                . 'display_name VARCHAR(250) NULL, user_registered DATETIME NULL',
            'usermeta' => 'umeta_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, '
                . 'meta_key VARCHAR(255) NULL, meta_value LONGTEXT NULL',
            'terms' => 'term_id BIGINT UNSIGNED PRIMARY KEY, name VARCHAR(200) NULL, slug VARCHAR(200) NULL',
            'term_taxonomy' => 'term_taxonomy_id BIGINT UNSIGNED PRIMARY KEY, term_id BIGINT UNSIGNED NOT NULL, '
                . 'taxonomy VARCHAR(32) NULL, description LONGTEXT NULL, parent BIGINT UNSIGNED NOT NULL DEFAULT 0',
            'term_relationships' => 'object_id BIGINT UNSIGNED NOT NULL, term_taxonomy_id BIGINT UNSIGNED NOT NULL, '
                . 'PRIMARY KEY (object_id, term_taxonomy_id)',
            'termmeta' => 'meta_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, term_id BIGINT UNSIGNED NOT NULL, '
                . 'meta_key VARCHAR(255) NULL, meta_value LONGTEXT NULL',
            'comments' => 'comment_ID BIGINT UNSIGNED PRIMARY KEY, comment_post_ID BIGINT UNSIGNED NOT NULL, '
                . 'comment_author VARCHAR(245) NULL, comment_author_email VARCHAR(100) NULL, '
                . 'comment_date DATETIME NULL, comment_content TEXT NULL, comment_approved VARCHAR(20) NULL, '
                . 'comment_parent BIGINT UNSIGNED NOT NULL DEFAULT 0',
            'options' => 'option_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, option_name VARCHAR(191) NULL, '
                . 'option_value LONGTEXT NULL',
        ];

        foreach ($tables as $name => $definition) {
            // Dropped, not "IF NOT EXISTS": these fixture tables are outside the harness's
            // truncation list, so a previous test's rows would collide on the primary keys.
            $this->insert('DROP TABLE IF EXISTS `' . $prefix . $name . '`');
            $this->insert('CREATE TABLE `' . $prefix . $name . '` (' . $definition . ')');
        }
    }


    /**
     * Seed through a labelled call: a fixture failure names the statement instead of only the
     * driver's complaint.
     *
     * @param array<string, mixed> $bindings
     */
    private function insert(string $sql, array $bindings = []): void
    {
        try {
            $this->db->statement($sql, $bindings);
        } catch (\PDOException $exception) {
            throw new \RuntimeException(
                'Seed failed: ' . preg_replace('/\s+/', ' ', substr($sql, 0, 120)) . ' — ' . $exception->getMessage(),
                0,
                $exception
            );
        }
    }
    private function seedBlog(string $prefix, int $postId, string $slug, bool $comments): void
    {
        $this->insert(
            'INSERT INTO `' . $prefix . 'users` (ID, user_login, user_email, display_name, user_registered) VALUES '
            . '(1, :admin, :admin_email, :admin_name, :at), '
            . '(2, :editor, :editor_email, :editor_name, :at2)',
            [
                'admin' => 'admin', 'admin_email' => 'admin@example.test', 'admin_name' => 'Site Admin',
                'editor' => 'editor', 'editor_email' => 'editor@example.test', 'editor_name' => 'The Editor',
                'at' => '2020-01-01 00:00:00', 'at2' => '2020-01-02 00:00:00',
            ]
        );

        // Entity meta: capabilities and a nickname, plus a term thumbnail. None of it has an
        // equivalent here, and none of it may vanish without being reported.
        $this->insert(
            'INSERT INTO `' . $prefix . 'usermeta` (user_id, meta_key, meta_value) VALUES '
            . '(1, :caps, :admin_caps), (1, :nick, :admin_nick), (2, :caps2, :editor_caps)',
            [
                'caps' => 'wp_capabilities', 'admin_caps' => 'a:1:{s:13:"administrator";b:1;}',
                'nick' => 'nickname', 'admin_nick' => 'Site Admin',
                'caps2' => 'wp_capabilities', 'editor_caps' => 'a:1:{s:6:"editor";b:1;}',
            ]
        );

        $this->insert(
            'INSERT INTO `' . $prefix . 'terms` (term_id, name, slug) VALUES '
            . '(1, :news, :news_slug), (2, :politics, :politics_slug), (3, :election, :election_slug), '
            . '(4, :tag, :tag_slug)',
            [
                'news' => 'News', 'news_slug' => 'news',
                'politics' => 'Politics', 'politics_slug' => 'politics',
                'election' => 'Election', 'election_slug' => 'election',
                'tag' => 'Featured', 'tag_slug' => 'featured',
            ]
        );

        // A three-level category chain: News -> Politics -> Election.
        $this->insert(
            'INSERT INTO `' . $prefix . 'term_taxonomy` (term_taxonomy_id, term_id, taxonomy, description, parent) '
            . 'VALUES (1, 1, :category, :d1, 0), (2, 2, :category2, NULL, 1), (3, 3, :category3, NULL, 2), '
            . '(4, 4, :tag, NULL, 0)',
            [
                'category' => 'category', 'd1' => 'Everything', 'category2' => 'category',
                'category3' => 'category', 'tag' => 'post_tag',
            ]
        );

        $this->insert(
            'INSERT INTO `' . $prefix . 'termmeta` (term_id, meta_key, meta_value) VALUES (1, :key, :value)',
            ['key' => 'thumbnail_id', 'value' => '99']
        );

        $this->insert(
            'INSERT INTO `' . $prefix . 'options` (option_name, option_value) VALUES (:name, :value)',
            ['name' => 'permalink_structure', 'value' => '/archives/%postname%/']
        );

        // The row types a real database contains: a post, a page with a child, an attachment, a
        // revision, a nav menu item and a draft.
        $this->insert(
            'INSERT INTO `' . $prefix . 'posts` (ID, post_author, post_date, post_date_gmt, post_modified, '
            . 'post_title, post_name, post_excerpt, post_content, post_status, post_type, post_parent, guid, menu_order) '
            . 'VALUES '
            . '(:id, 2, :date, :date2, :date3, :title, :slug, :excerpt, :content, :published, :post, 0, :guid, 0), '
            . '(:page, 2, :date4, :date5, :date6, :page_title, :page_slug, NULL, :page_content, :published2, :page_type, 0, :guid2, 0), '
            . '(:child, 2, :date7, :date8, :date9, :child_title, :child_slug, NULL, :child_content, :published3, :page_type2, :child_parent, :guid3, 0), '
            . '(:attachment, 2, :date10, :date11, :date12, :attachment_title, :attachment_slug, NULL, :attachment_content, :inherit, :attachment_type, 0, :guid4, 0), '
            . '(:revision, 2, :date13, :date14, :date15, :revision_title, :revision_slug, NULL, :revision_content, :inherit2, :revision_type, :id2, :guid5, 0), '
            . '(:menu, 2, :date16, :date17, :date18, :menu_title, :menu_slug, NULL, :menu_content, :published4, :menu_type, 0, :guid6, 1), '
            . '(:draft, 2, :date19, :date20, :date21, :draft_title, :draft_slug, NULL, :draft_content, :draft_status, :post2, 0, :guid7, 0)',
            [
                'id' => $postId, 'id2' => $postId, 'page' => $postId + 1, 'child' => $postId + 2,
                'child_parent' => $postId + 1,
                'attachment' => $postId + 3, 'revision' => $postId + 4, 'menu' => $postId + 5, 'draft' => $postId + 6,
                'date' => '2020-03-01 10:00:00', 'date2' => '2020-03-01 10:00:00', 'date3' => '2020-03-02 10:00:00',
                'date4' => '2020-03-03 10:00:00', 'date5' => '2020-03-03 10:00:00', 'date6' => '2020-03-04 10:00:00',
                'date7' => '2020-03-05 10:00:00', 'date8' => '2020-03-05 10:00:00', 'date9' => '2020-03-06 10:00:00',
                'date10' => '2020-03-07 10:00:00', 'date11' => '2020-03-07 10:00:00', 'date12' => '2020-03-08 10:00:00',
                'date13' => '2020-03-09 10:00:00', 'date14' => '2020-03-09 10:00:00', 'date15' => '2020-03-10 10:00:00',
                'date16' => '2020-03-11 10:00:00', 'date17' => '2020-03-11 10:00:00', 'date18' => '2020-03-12 10:00:00',
                'date19' => '2020-03-13 10:00:00', 'date20' => '2020-03-13 10:00:00', 'date21' => '2020-03-14 10:00:00',
                'title' => 'Network Post', 'slug' => $slug, 'excerpt' => 'An excerpt.',
                'content' => '<p>Body of the network post.</p>',
                'page_title' => 'About', 'page_slug' => 'about', 'page_content' => '<p>About us.</p>',
                'child_title' => 'Team', 'child_slug' => 'team', 'child_content' => '<p>The team.</p>',
                'attachment_title' => 'Photo', 'attachment_slug' => 'photo', 'attachment_content' => '',
                'revision_title' => 'Network Post (revision)', 'revision_slug' => $slug . '-revision', 'revision_content' => '<p>Old.</p>',
                'menu_title' => 'Menu item', 'menu_slug' => 'menu-item', 'menu_content' => '',
                'draft_title' => 'Unfinished', 'draft_slug' => 'unfinished', 'draft_content' => '<p>Draft.</p>',
                'published' => 'publish', 'published2' => 'publish', 'published3' => 'publish', 'published4' => 'publish',
                'post' => 'post', 'post2' => 'post', 'page_type' => 'page', 'page_type2' => 'page',
                'attachment_type' => 'attachment', 'revision_type' => 'revision', 'menu_type' => 'nav_menu_item',
                'inherit' => 'inherit', 'inherit2' => 'inherit', 'draft_status' => 'draft',
                'guid' => 'https://example.test/?p=' . $postId,
                'guid2' => 'https://example.test/?page_id=' . ($postId + 1),
                'guid3' => 'https://example.test/?page_id=' . ($postId + 2),
                'guid4' => 'https://example.test/wp-content/uploads/2020/03/photo.jpg',
                'guid5' => 'https://example.test/?p=' . $postId . '-revision',
                'guid6' => 'https://example.test/?p=' . ($postId + 5),
                'guid7' => 'https://example.test/?p=' . ($postId + 6),
            ]
        );

        // One placeholder per value: this project runs with native prepares, where reusing a named
        // parameter in the same statement is an error rather than a convenience.
        $this->insert(
            'INSERT INTO `' . $prefix . 'postmeta` (post_id, meta_key, meta_value) VALUES '
            . '(:post_a, :yoast, :yoast_value), (:post_b, :robots, :robots_value), (:post_c, :unknown, :unknown_value)',
            [
                'post_a' => $postId, 'post_b' => $postId, 'post_c' => $postId,
                'yoast' => '_yoast_wpseo_title', 'yoast_value' => 'SEO title',
                'robots' => '_yoast_wpseo_meta-robots-noindex', 'robots_value' => '1',
                'unknown' => '_some_plugin_field', 'unknown_value' => 'kept in the log',
            ]
        );

        // Election category and the Featured tag, attached to the post.
        $this->insert(
            'INSERT INTO `' . $prefix . 'term_relationships` (object_id, term_taxonomy_id) VALUES (:id, 3), (:id2, 4)',
            ['id' => $postId, 'id2' => $postId]
        );

        if (!$comments) {
            return;
        }

        // A thread: a registered commenter, a reply from the same person, and a guest reply to that.
        $this->insert(
            'INSERT INTO `' . $prefix . 'comments` (comment_ID, comment_post_ID, comment_author, comment_author_email, '
            . 'comment_date, comment_content, comment_approved, comment_parent) VALUES '
            . '(100, :post, :a1, :e1, :d1, :c1, :approved, 0), '
            . '(101, :post2, :a2, :e2, :d2, :c2, :approved2, 100), '
            . '(102, :post3, :a3, :e3, :d3, :c3, :approved3, 101)',
            [
                'post' => $postId, 'post2' => $postId, 'post3' => $postId,
                'a1' => 'The Editor', 'e1' => 'editor@example.test',
                'a2' => 'The Editor', 'e2' => 'editor@example.test',
                'a3' => 'A Guest', 'e3' => 'guest@example.test',
                'd1' => '2020-03-20 09:00:00', 'd2' => '2020-03-20 10:00:00', 'd3' => '2020-03-20 11:00:00',
                'c1' => 'First comment.', 'c2' => 'A reply to the first.', 'c3' => 'A guest reply, deeper still.',
                'approved' => '1', 'approved2' => '1', 'approved3' => '1',
            ]
        );
    }

    private function service(string $prefix): MigrationServiceInterface
    {
        $c = $this->app->container();

        return new MigrationService(
            new WordPressSource($this->db->pdo(), $prefix),
            $c->get(MigrationRepositoryInterface::class),
            $c->get(ContentServiceInterface::class),
            $c->get(TaxonomyServiceInterface::class),
            $c->get(\CMS\Modules\Media\Contracts\MediaServiceInterface::class),
            $c->get(CommentServiceInterface::class),
            $c->get(\CMS\Modules\Identity\Contracts\UserServiceInterface::class),
            $c->get(SeoServiceInterface::class),
            $c->get(RedirectServiceInterface::class),
            $c->get(\CMS\Modules\Content\Contracts\UrlGeneratorInterface::class),
            $c->get(SiteServiceInterface::class),
            $this->path('wp-uploads'),
            'run_realistic_' . $prefix,
        );
    }

    #[Test]
    public function theCategoryTreeSurvivesThreeLevels(): void
    {
        $this->service('wp_')->runAll($this->siteId, 50);

        $taxonomy = $this->app->container()->get(TaxonomyServiceInterface::class);

        $roots = $taxonomy->listTerms($this->siteId, 'category');
        $names = array_map(static fn ($t): string => $t->name, $roots);

        self::assertContains('News', $names, 'The top category must be a root.');
        self::assertNotContains('Politics', $names, 'A child must not be flattened to the root.');

        $newsId = 0;

        foreach ($roots as $term) {
            if ($term->name === 'News') {
                $newsId = (int) $term->id;
            }
        }

        $children = $taxonomy->listTerms($this->siteId, 'category', $newsId);
        self::assertCount(1, $children);
        self::assertSame('Politics', $children[0]->name);

        $grandchildren = $taxonomy->listTerms($this->siteId, 'category', (int) $children[0]->id);
        self::assertCount(1, $grandchildren);
        self::assertSame('Election', $grandchildren[0]->name);
    }

    #[Test]
    public function theCommentThreadKeepsItsShape(): void
    {
        $this->service('wp_')->runAll($this->siteId, 50);

        $content = $this->app->container()->get(ContentServiceInterface::class)
            ->getBySlug('network-post', 'post', $this->siteId);

        $thread = $this->app->container()->get(CommentServiceInterface::class)
            ->thread($this->siteId, (int) $content->id);

        self::assertCount(3, $thread);

        $depths = array_map(static fn ($c): int => (int) $c->depth, $thread);
        $bodies = array_map(static fn ($c): string => $c->body, $thread);

        self::assertSame([0, 1, 2], $depths, 'The reply chain must not be flattened.');
        self::assertSame('First comment.', $bodies[0]);
        self::assertSame('A reply to the first.', $bodies[1]);
        self::assertSame('A guest reply, deeper still.', $bodies[2]);
    }

    #[Test]
    public function aGuestReplyIsImportedAnonymously(): void
    {
        $this->service('wp_')->runAll($this->siteId, 50);

        $content = $this->app->container()->get(ContentServiceInterface::class)
            ->getBySlug('network-post', 'post', $this->siteId);

        $thread = $this->app->container()->get(CommentServiceInterface::class)
            ->thread($this->siteId, (int) $content->id);

        $guests = array_values(array_filter($thread, static fn ($c): bool => (int) $c->userId === 0));

        self::assertCount(1, $guests);
        self::assertSame('A Guest', $guests[0]->authorName);
    }

    #[Test]
    public function userAndTermMetaAreReportedRatherThanSilentlyDropped(): void
    {
        $migration = $this->service('wp_');
        $migration->runAll($this->siteId, 50);

        $logs = $migration->logs($this->siteId, 'warning', 300);
        $fields = array_map(static fn (array $l): string => (string) ($l['field'] ?? ''), $logs);

        self::assertContains('wp_capabilities', $fields, 'usermeta must be reported.');
        self::assertContains('thumbnail_id', $fields, 'termmeta must be reported.');
    }

    #[Test]
    public function aSecondBlogIsMigratedFromItsOwnTables(): void
    {
        $otherSite = $this->createSiteRow('second', 'second.example');

        $this->service('wp_2_')->runAll($otherSite, 50);

        $contents = $this->app->container()->get(ContentServiceInterface::class);
        $second = $contents->getBySlug('second-blog-post', 'post', $otherSite);

        self::assertSame('Network Post', $second->title);

        // The first site must not have received the second blog's content.
        self::assertNull(
            $this->contentIdFor('second-blog-post', $this->siteId),
            'A per-blog prefix must not leak into the other tenant.'
        );
    }

    #[Test]
    public function theRowTypesNobodyReadsDoNotBecomeContent(): void
    {
        $this->service('wp_')->runAll($this->siteId, 50);

        // A revision, a nav menu item and an attachment must not arrive as post or page. An
        // attachment may legitimately exist as a media row, which is why the check is by type.
        foreach (['photo', 'menu-item', 'network-post-revision'] as $slug) {
            foreach (['post', 'page'] as $type) {
                self::assertNull(
                    $this->contentIdFor($slug, $this->siteId, $type),
                    'Slug "' . $slug . '" must not have been imported as a ' . $type . '.'
                );
            }
        }

        // A draft is not rubbish: it is content somebody was working on, and it is imported as a
        // draft rather than published or dropped.
        $draftId = $this->contentIdFor('unfinished', $this->siteId, 'post');

        self::assertNotNull($draftId, 'A draft post must be migrated.');
        self::assertSame(
            'draft',
            $this->db->selectOne('SELECT status FROM contents WHERE id = :id', ['id' => $draftId])['status'] ?? null,
            'and must stay a draft.'
        );

        self::assertNotNull($this->contentIdFor('network-post', $this->siteId, 'post'));
        self::assertNotNull($this->contentIdFor('about', $this->siteId, 'page'));
        self::assertNotNull($this->contentIdFor('team', $this->siteId, 'page'));
    }

    private function contentIdFor(string $slug, int $siteId, ?string $type = null): ?int
    {
        $sql = 'SELECT id FROM contents WHERE site_id = :site AND slug = :slug';
        $bindings = ['site' => $siteId, 'slug' => $slug];

        if ($type !== null) {
            $sql .= ' AND type = :type';
            $bindings['type'] = $type;
        }

        $row = $this->db->selectOne($sql . ' LIMIT 1', $bindings);

        return $row === null ? null : (int) $row['id'];
    }
}
