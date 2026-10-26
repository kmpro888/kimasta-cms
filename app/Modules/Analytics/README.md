# Analytics Module — Third-Party Integration

Renders the configured analytics snippet. Collects **nothing** locally.

## Ownership

No tables. Configuration lives in site settings under the `analytics` namespace.

## Why no local page views

The task pack forbids a database write per visitor, and rightly: it is the single
quickest way to make a shared-host CMS fall over. The provider does the counting; this
module only emits their snippet.

## The security problem this module actually solves

This is the one place where a setting becomes **executable JavaScript in every page**.
An administrator edits a value; it is printed into a `<script>` block for every
visitor. One unvalidated value is stored XSS at site scale.

So:

| Value | Rule |
|---|---|
| `google_id` | must match `^[A-Z0-9][A-Z0-9\-]{4,40}$` |
| `matomo_host` | must match a bare hostname (letters, digits, dots, hyphens) |
| `matomo_site_id` | must be a positive integer |

Anything that fails produces **no snippet at all**. Failing closed is the only safe
default when the output is script — a mangled partial snippet is worse than none.

The tracker URL is forced to `https://`, so a configured `http://` cannot downgrade
it, and a host with a scheme, path or quote is rejected outright.

## Providers

| Provider | Emits |
|---|---|
| `none` (default) | nothing |
| `google` | GA4 / gtag.js |
| `matomo` | Matomo `_paq` tracker |

An unknown provider value emits nothing rather than falling through to a default
tracker.

## Tests

13 unit tests, most of them adversarial: markup in the id, a quote that would close
the string literal, a scheme smuggled into the host, a non-numeric site id, and the
disabled/unknown cases.
