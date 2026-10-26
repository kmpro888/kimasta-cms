# Search Module — Content Search

Provides `SearchServiceInterface` over Content's query service: FULLTEXT first, a
portable fallback when the index cannot be trusted.

## Ownership

No tables. The FULLTEXT index `ft_contents_search` belongs to Content and is created
by a Content migration.

## Why there is a fallback at all

`LIKE '%term%'` cannot use an index, so it scans every row of the site. FULLTEXT does
not. But FULLTEXT is not always trustworthy, and this is the part that matters:

```text
SHOW VARIABLES LIKE 'innodb_ft_max_token_size';
  84  → MySQL 8 default, ordinary words indexed
  10  → this installation
```

With the window at 10, every word longer than ten characters is **absent from the
index**. Searching "Extraordinary" (13) returns zero rows — no error, just nothing —
and a prefix query for "document" cannot find "documentation" either.

Silent false negatives are worse than a slower query, so:

| Condition | Strategy |
|---|---|
| index missing | `like` |
| `innodb_ft_max_token_size` < 20 | `like` |
| any token outside `[min, max]` | `like` |
| otherwise | `fulltext` |

The decision is per query, and the API reports it in `meta.strategy` so an operator
can see why search is slow on a given host without guessing.

## Both strategies agree

Words are OR-ed in both paths. The LIKE path is word-wise, not a literal-phrase
match, because `red boat` must behave the same whichever strategy runs.

## Injection safety

- The boolean query strips `+ - > < ( ) ~ * " @` from every word, so a visitor cannot
  inject an exclusion or a quoted phrase.
- The LIKE path strips `%` and `_`, so a visitor cannot turn a search into a wildcard
  scan.
- Operator-only input produces `1 = 0` (match nothing) rather than matching everything.

## Not implemented

- **Relevance ordering.** Results are ordered by publish date. `MATCH` can produce a
  score, but ranking would need the score in the SELECT list and a second placeholder,
  and a half-done relevance sort is worse than none.
- No indexing of media, terms or comments — content only.
- No `total` short-circuit for very large result sets.
