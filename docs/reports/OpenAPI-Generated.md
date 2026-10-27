# OpenAPI, Generated From the Router

A hand-written specification is a second source of truth, and the second one always loses: accurate
on the day it is written, wrong the first time somebody adds an endpoint, and believed anyway.

So the document is **generated**, committed, and checked three ways.

---

## How it works

```text
php bin/openapi.php     -> docs/api/openapi.json
```

`OpenApiDocument` reads the router and emits:

```text
57 paths, 80 operations, 19 tags
servers: [{ url: "/" }]          (paths already carry /api/v1)
components:  SuccessEnvelope, ErrorEnvelope, securitySchemes.session
```

Each operation gets an `operationId` (the route name, or a readable fallback such as
`get_ads_by_id`), a tag from its first resource segment, path parameters **with their constraint
kept in the description** (`{id:\d+}` becomes `{id}`, "must match /\d+/"), and the two frozen
envelopes as its only response shapes.

---

## The three checks

| | |
|---|---|
| **drift** | the committed JSON is compared to what the router produces right now. Add a route and forget to regenerate, and the build fails with `Run: php bin/openapi.php` |
| **shape** | every operation has an id, a tag, a `200` and a `default`; ids are unique; every documented path exists and every existing `/api` route is documented |
| **truth** | operations the document marks `public` answer without a session; operations it marks `session` are refused (`401`/`403`/`419`) when called anonymously |

The third is the one that makes this worth having. A document that is right about *which* endpoints
exist and wrong about *who may call them* sends clients into production with a false map.

```text
php vendor/bin/phpunit --filter ApiDocumentationTest
OK (6 tests, 549 assertions)
```

---

## Authentication: three classes, not a guess

The first version marked **every** operation `session`, which was wrong: read endpoints may serve
public content, and others are administrative listings guarded by a capability. The generator now
distinguishes:

| class | meaning | count |
|---|---|---|
| `public` | no session: sign-in, forgot/reset password, the CSRF bootstrap, health | 5 |
| `session` | the identity endpoints and **every write** | 42 |
| `varies` | reads whose policy lives in the controller, not the router | 34 |

`varies` is published as `x-auth: varies` with the security requirement omitted, because inventing
one would be worse than saying "depends". The report names it as an unproven class rather than
implying coverage it does not have.

---

## The problem that only appeared on the second run

The document was generated once from the development database and once from the test database, and
they disagreed: the development site had the example plugin active, so its route appeared.

A committed artifact that depends on which plugin happens to be active is not an artifact. The
document is now restricted to a declared list of **core tags**; a route outside it is skipped, and
`info.description` says so. A test asserts that no unexpected tag appears in the document, so a
plugin route cannot slip in unnoticed.

---

## Files

```text
app/Modules/Api/Service/OpenApiDocument.php   NEW  the generator
bin/openapi.php                               NEW  the CLI
docs/api/openapi.json                         NEW  the committed document (generated)
tests/Contract/Api/ApiDocumentationTest.php   NEW  6 tests
```

---

## Known limitations

1. **No per-endpoint request or response schemas.** Controllers parse input by hand; there is no
   schema to derive, and writing them by hand would recreate the drift this exists to prevent. The
   envelopes are documented; the payloads inside them are not.
2. **Plugin routes are excluded**, so the document is the core API and the description says so.
3. **`varies` is not probed.** A read without a valid id cannot distinguish "public" from
   "capability required".
4. **Path constraints are prose**, not validated by clients.
5. **Only the happy path's status codes are declared**: `200` and a `default` that carries every
   error. A client cannot tell a `404` from a `409` except by reading `error.code`.
6. **No request examples**, no pagination parameters in the listing operations.

---

## Ready to merge

yes
