<?php

declare(strict_types=1);

namespace CMS\Modules\Search\Service;

use CMS\Core\Database\Connection;
use CMS\Modules\Content\Contracts\ContentQueryServiceInterface;
use CMS\Modules\Search\Contracts\SearchServiceInterface;
use Throwable;

/**
 * FULLTEXT-first content search with a portable fallback.
 *
 * Strategy selection happens once per request and is cached, because probing
 * `information_schema` on every search would cost more than the search itself.
 *
 * The fallback is not decoration: `LIKE '%term%'` cannot use an index, so it scans
 * every row of the site. It exists so the product degrades in speed rather than
 * breaking on a host that forbids FULLTEXT.
 */
final class SearchService implements SearchServiceInterface
{
    private const FULLTEXT_INDEX = 'ft_contents_search';

    /**
     * Minimum `innodb_ft_max_token_size` for the index to be trusted.
     *
     * MySQL 8 defaults to 84, which indexes ordinary words. A host configured down to
     * 10 does not: "Extraordinary" (13) and "documentation" (13) are simply absent
     * from the index, so `MATCH` returns zero rows for words that are plainly on the
     * page, and a prefix query for "document" cannot find "documentation" either.
     *
     * Silent false negatives are worse than a slower query, so a small window
     * disqualifies the index entirely.
     */
    private const MINIMUM_MAX_TOKEN_SIZE = 20;

    private const DEFAULT_PER_PAGE = 20;

    private const MAX_PER_PAGE = 100;

    private ?bool $fulltextAvailable = null;

    private ?int $minTokenSize = null;

    private ?int $maxTokenSize = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly ContentQueryServiceInterface $query,
    ) {
    }

    public function search(int $siteId, string $term, array $options = []): array
    {
        $term = trim($term);

        $perPage = (int) ($options['limit'] ?? self::DEFAULT_PER_PAGE);
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));

        $page = max(1, (int) ($options['page'] ?? 1));
        $offset = isset($options['offset'])
            ? max(0, (int) $options['offset'])
            : ($page - 1) * $perPage;

        if ($term === '') {
            return [
                'items' => [],
                'total' => 0,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => 0,
                'strategy' => $this->strategy(),
            ];
        }

        $strategy = $this->strategyFor($term);

        $criteria = [
            'search' => $term,
            'search_mode' => $strategy,
            'exclude_trash' => true,
            'limit' => $perPage,
            'offset' => $offset,
        ];

        if (isset($options['type']) && is_string($options['type']) && $options['type'] !== '') {
            $criteria['type'] = $options['type'];
        }

        if (($options['include_unpublished'] ?? false) !== true) {
            $criteria['published_only'] = true;
        }

        $result = $this->query->paginate($siteId, $criteria);

        return [
            'items' => $result['items'],
            'total' => $result['total'],
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $result['total_pages'],
            'strategy' => $strategy,
        ];
    }

    public function strategy(): string
    {
        return $this->supportsFulltext() ? 'fulltext' : 'like';
    }

    /**
     * The strategy that can actually answer THIS term.
     *
     * A FULLTEXT index silently excludes tokens outside
     * `[innodb_ft_min_token_size, innodb_ft_max_token_size]`. On this installation
     * `innodb_ft_max_token_size` is 10, so searching "Extraordinary" (13 characters)
     * through the index returns nothing at all — no error, just zero rows.
     *
     * So the index is only used when every token is inside the configured window.
     * Outside it, the LIKE strategy runs: slower, but correct. Silently returning no
     * results for a word that is plainly on the page is the worst possible outcome.
     */
    public function strategyFor(string $term): string
    {
        if (!$this->supportsFulltext()) {
            return 'like';
        }

        $maximum = $this->maxTokenSize();
        $minimum = $this->minTokenSize();

        foreach ($this->tokens($term) as $token) {
            $length = mb_strlen($token);

            if ($length < max(1, $minimum) || $length > $maximum) {
                return 'like';
            }
        }

        return 'fulltext';
    }

    private function minTokenSize(): int
    {
        return $this->minTokenSize ??= $this->readTokenSize('innodb_ft_min_token_size', 3);
    }

    private function maxTokenSize(): int
    {
        return $this->maxTokenSize ??= $this->readTokenSize('innodb_ft_max_token_size', 84);
    }

    /**
     * @return list<string>
     */
    private function tokens(string $term): array
    {
        $words = preg_split('/\s+/u', $term) ?: [];
        $tokens = [];

        foreach ($words as $word) {
            $clean = preg_replace('/[+\-><()~*"@]+/u', '', $word) ?? '';
            $clean = trim($clean);

            if ($clean !== '') {
                $tokens[] = $clean;
            }
        }

        return $tokens;
    }

    private function readTokenSize(string $variable, int $fallback): int
    {
        try {
            $row = $this->connection->selectOne(
                'SELECT @@GLOBAL.' . $variable . ' AS value'
            );

            $value = $row['value'] ?? null;

            return is_numeric($value) ? (int) $value : $fallback;
        } catch (Throwable) {
            return $fallback;
        }
    }

    public function supportsFulltext(): bool
    {
        if ($this->fulltextAvailable !== null) {
            return $this->fulltextAvailable;
        }

        return $this->fulltextAvailable = $this->probeFulltext();
    }

    /**
     * Look for the FULLTEXT index rather than the storage engine.
     *
     * A missing index is the actual reason the query would fail, and it is also the
     * case an operator can fix.
     */
    private function probeFulltext(): bool
    {
        try {
            $row = $this->connection->selectOne(
                'SELECT COUNT(*) AS total FROM information_schema.statistics '
                . 'WHERE table_schema = DATABASE() AND table_name = :table AND index_name = :index',
                ['table' => 'contents', 'index' => self::FULLTEXT_INDEX]
            );

            if ((int) ($row['total'] ?? 0) === 0) {
                return false;
            }

            // An index that cannot hold ordinary words is worse than no index: it
            // answers every query, and answers some of them wrongly.
            return $this->maxTokenSize() >= self::MINIMUM_MAX_TOKEN_SIZE;
        } catch (Throwable) {
            // A query failing at this level means the connection is unusable; search
            // must not take the page down over a capability probe.
            return false;
        }
    }
}
