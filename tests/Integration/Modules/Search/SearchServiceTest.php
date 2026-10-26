<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Search;

use CMS\Modules\Search\Contracts\SearchServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AppTestCase;

/**
 * Content search strategies.
 *
 * The product promise is that search is index-backed. These tests pin both the
 * primary strategy and the fallback, because the fallback is what keeps the site
 * working on a host where FULLTEXT is unavailable.
 */
#[CoversClass(\CMS\Modules\Search\Service\SearchService::class)]
final class SearchServiceTest extends AppTestCase
{
    private SearchServiceInterface $search;

    protected function setUp(): void
    {
        parent::setUp();

        $this->search = $this->app->container()->get(SearchServiceInterface::class);
    }

    private function article(string $title, string $body, string $status = 'published'): int
    {
        return (int) $this->app->container()->get(\CMS\Modules\Content\Contracts\ContentServiceInterface::class)
            ->create($this->siteId, [
                'title' => $title,
                'author_id' => 1,
                'status' => $status,
                'content_json' => [['type' => 'paragraph', 'version' => 1, 'data' => ['text' => $body]]],
            ])->id;
    }

    #[Test]
    public function theIndexIsUsedOnlyWhenTheTokenWindowAllowsIt(): void
    {
        // The index exists (the migration creates it), but it is only trustworthy when
        // MySQL indexes ordinary words. `innodb_ft_max_token_size` defaults to 84; a
        // host configured down to 10 drops every word longer than ten characters from
        // the index, which becomes silent zero-row results.
        $row = $this->db->selectOne('SELECT @@GLOBAL.innodb_ft_max_token_size AS value');
        $max = (int) ($row['value'] ?? 84);

        $expected = $max >= 20 ? 'fulltext' : 'like';

        self::assertSame($expected, $this->search->strategy());
        self::assertSame($expected === 'fulltext', $this->search->supportsFulltext());
    }

    #[Test]
    public function searchFindsContentByTitle(): void
    {
        $this->article('Extraordinary Discovery', 'Body text about something else.');

        $result = $this->search->search($this->siteId, 'Extraordinary');

        self::assertSame(1, $result['total']);
        self::assertSame('Extraordinary Discovery', $result['items'][0]->title);
    }

    #[Test]
    public function searchFindsContentByBodyText(): void
    {
        $this->article('Unrelated Title', 'The lighthouse keeper wrote about shipping lanes.');

        $result = $this->search->search($this->siteId, 'lighthouse');

        self::assertSame(1, $result['total']);
        self::assertSame('Unrelated Title', $result['items'][0]->title);
    }

    #[Test]
    public function searchMatchesWordPrefixes(): void
    {
        // A search box is expected to match partial words, which is why the boolean
        // query appends `*`.
        $this->article('Report', 'Documentation standards and documentaries.');

        $result = $this->search->search($this->siteId, 'document');

        self::assertGreaterThanOrEqual(1, $result['total']);
    }

    #[Test]
    public function aVeryShortTermStillFindsContent(): void
    {
        $this->article('A Title', 'A body with the word at in it.');

        $result = $this->search->search($this->siteId, 'at');

        // Whether or not the index covers such a short token, the search must find it
        // rather than returning nothing.
        self::assertSame(1, $result['total']);
    }

    #[Test]
    public function searchDoesNotReturnDraftsByDefault(): void
    {
        $this->article('Secret Draft Topic', 'Unpublished words.', 'draft');

        self::assertSame(0, $this->search->search($this->siteId, 'unpublished')['total']);
    }

    #[Test]
    public function searchCanIncludeUnpublishedWhenAsked(): void
    {
        $this->article('Secret Draft Topic', 'Unpublished words.', 'draft');

        $result = $this->search->search($this->siteId, 'unpublished', ['include_unpublished' => true]);

        self::assertSame(1, $result['total']);
    }

    #[Test]
    public function searchIsScopedToTheTenant(): void
    {
        $otherSite = $this->createSiteRow('other', 'other.example');

        $this->app->container()->get(\CMS\Modules\Content\Contracts\ContentServiceInterface::class)
            ->create($otherSite, [
                'title' => 'Foreign Exclusive Story',
                'author_id' => 1,
                'status' => 'published',
                'content_json' => [['type' => 'paragraph', 'version' => 1, 'data' => ['text' => 'Foreign body']]],
            ]);

        self::assertSame(0, $this->search->search($this->siteId, 'Foreign')['total']);
        self::assertSame(1, $this->search->search($otherSite, 'Foreign')['total']);
    }

    #[Test]
    public function booleanOperatorsCannotChangeTheQuery(): void
    {
        $this->article('Operator Safety', 'Ordinary content.');

        // A visitor must not be able to inject `-` exclusion or a quoted phrase.
        $result = $this->search->search($this->siteId, '-Operator +Safety "quoted"');

        // The words are stripped of operators and searched, not obeyed.
        self::assertGreaterThanOrEqual(1, $result['total']);
    }

    #[Test]
    public function anEmptyTermReturnsAnEmptyResult(): void
    {
        $this->article('Anything', 'Some body.');

        $result = $this->search->search($this->siteId, '   ');

        self::assertSame([], $result['items']);
        self::assertSame(0, $result['total']);
    }

    #[Test]
    public function resultsArePaginated(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->article('Paginated Topic ' . $i, 'Body about pagination.');
        }

        $first = $this->search->search($this->siteId, 'pagination', ['limit' => 2, 'page' => 1]);
        $second = $this->search->search($this->siteId, 'pagination', ['limit' => 2, 'page' => 2]);

        self::assertCount(2, $first['items']);
        self::assertSame(5, $first['total']);
        self::assertSame(3, $first['total_pages']);
        self::assertNotSame($first['items'][0]->id, $second['items'][0]->id);
    }

    #[Test]
    public function theApiReportsWhichStrategyRan(): void
    {
        $this->article('Strategy Report', 'Body.');

        $response = $this->callForStrategy();

        // The response states which strategy answered, so an operator can tell why
        // search is slow on a host without having to guess.
        self::assertContains($response['meta']['strategy'], ['fulltext', 'like']);
    }

    /**
     * @return array<string, mixed>
     */
    private function callForStrategy(): array
    {
        $controller = $this->app->container()->get(\CMS\Modules\Api\Http\Controller\ContentController::class);

        $request = \CMS\Core\Http\Request::create('GET', '/api/v1/search', $this->host, ['q' => 'Strategy']);

        $request = $request
            ->withAttribute('api.site', $this->app->container()
                ->get(\CMS\Modules\Site\Contracts\SiteServiceInterface::class)->findById($this->siteId))
            ->withAttribute('api.actor', $this->app->container()
                ->get(\CMS\Modules\Identity\Contracts\ActorProviderInterface::class)
                ->actorForUser(null, $this->siteId));

        return json_decode($controller->search($request)->content(), true);
    }
}
