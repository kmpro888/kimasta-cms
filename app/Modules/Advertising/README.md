# Advertising Module — Placements & Targeting

## Ownership

Tables: `ads`, `ad_rules`.

## The contract

The theme asks by placement and prints; it never queries and never applies targeting:

```php
$ads = (array) $view->get('ads', []);
echo $ads['before_article'];
```

Selection happens in the service, before rendering. That is what keeps the rule "theme
must not query the database" true even for advertising.

## Placements

```text
header  sidebar  before_article  middle_article  after_article  sticky  footer
```

Defined as constants **and validated**. A template that asks for `sidebaar` throws rather
than rendering an empty slot forever — a silent no-op is the worst outcome for a
placement typo, because nobody notices the revenue is missing.

## Targeting

Rules are rows, not columns, because the set of dimensions grows while the ad record does
not. A fixed set of nullable columns would need a migration per dimension; a generic
`meta` blob is what this project forbids for core fields.

| Rule | Matches when |
|---|---|
| `device` | context device is one of the listed values |
| `content_type` | context type is one of the listed values |
| `term` | any term id overlaps |

Rules are **AND-ed**: "mobile AND category 5" runs only when both hold. No rules means no
targeting, which matches everything — what an operator expects from an untargeted ad.

### Unknown rule types fail closed

An unsupported rule **at write time** is refused. An unsupported rule **already stored**
(by a future version, or a manual insert) makes the ad ineligible rather than being
ignored. Silently skipping a rule would run an ad in a place its author did not intend;
that is worse than not running it.

## Dates

`starts_at` / `ends_at` on the ad itself, checked against the injected `ClockInterface`.
Expired and not-yet-live ads are simply not returned.

## Ordering

Highest `priority` first, then oldest. The service returns the whole eligible list rather
than one ad, so a template may stack a slot — and so the operator's priority is visible in
the result instead of being resolved by a tie-break nobody can see.

## Security: the ad body is raw HTML, on purpose

An ad is a third-party snippet. Sanitising it breaks most networks' tags, so
`body_html` is stored and printed as-is.

That makes writing an ad equivalent to writing a template file. The security boundary is
therefore **who may store one**: `ads.manage`, granted to `administrator` only and
deliberately **not** to `editor`. Granting it to editors would quietly turn "may edit
articles" into "may inject site-wide markup".

What *is* escaped is the `target_url` — an attacker-controlled URL in an attribute is a
different problem from an author-controlled creative — and the rendered wrapper adds
`rel="nofollow sponsored noopener"`.

## Aggregates deliberately absent

No `impressions` or `clicks` column. A row write per visitor view is exactly the load a
shared host cannot take, and the platform already supports an analytics provider for
counting.

## Performance

One repository read serves every slot on a page (`renderPlacements`). Ad rules for all ads
are loaded in a single query, not one per ad.

## Not implemented

- No ad rotation or frequency capping.
- No path/regex targeting (device, content type, term only).
- No ad scheduling UI; the API and console are the interface.
- No A/B testing, no click tracking, no budget pacing.
