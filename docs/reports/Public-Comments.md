# Public Comments: Anonymous, Moderated

The decision was **A — anonymous with moderation**. This is that decision implemented.

Until now a reader could see approved comments and could not write one. The platform had no reader
identity, and the old partial said so honestly:

> Display only. Posting is not offered because this platform has no reader identity: `comments`
> requires a user id and the only sign-in is the staff admin.

Half of that was true. The other half was an assumption that turned out to be wrong.

---

## The assumption that was wrong

`comments.user_id` is `NOT NULL` but carries **no foreign key** — it is checked nowhere against
`users`. A guest comment therefore needs no account, only a marker. `user_id = 0` is that marker: it
distinguishes a visitor from staff in the moderation queue, and it required no schema change.

So the missing piece was not identity. It was a name to display and a form to type it in.

---

## What exists now

```text
POST /comment          a visitor posts; nothing is published until approved
```

The form is on every article whose `comment_status` is `open`:

```text
Your name        [________________]
Comment          [________________]
Post comment
Your comment appears once a moderator has approved it.
```

The last line is not decoration. Every submission is stored as `pending`, and the promise is stated
before the visitor types rather than after.

---

## Three defences, no CAPTCHA

| | |
|---|---|
| **honeypot** | a `website` field hidden off-screen; when it is filled the response is *byte-identical* to a real submission and nothing is stored, so a script learns nothing |
| **rate limit** | five comments per address per ten minutes, on the existing filesystem limiter (no Redis, no session) |
| **the queue** | a human approves every comment; unreviewed public text is an injection surface with extra steps |

The rate limiter is the same one the API and admin login use, so a shared host gets one mechanism
rather than three.

`Cache-Control: no-store` on every one of these responses: a confirmation is an answer to one
submission, and a shared cache holding it would hand it to a stranger.

---

## Why the confirmation is not a theme template

The response is a small self-contained page written in the controller, and there is no redirect.

A `303` back to the article would need either a query flag or a session flash, and both end up in a
**cached** page: the flag would be baked into the cache and every later visitor would be told their
comment was received. Writing the confirmation as the POST response avoids the whole problem, and a
theme that had to supply a template for it could not be swapped without breaking commenting.

---

## The comment service

```php
// CommentServiceInterface
public function submit(
    int $siteId,
    int $contentId,
    int $userId,          // 0 for a guest
    string $body,
    ?int $parentId = null,
    ?string $authorName = null,   // NEW: required when $userId is 0
): Comment;
```

The name is validated only for guests (2–190 characters, the column width). A signed-in author keeps
being identified by their account name, so an admin who comments cannot pretend to be somebody else
in the queue.

The service still enforces everything it did before: body length, the content must be publicly
visible, the thread must be open, and comment depth is capped.

---

## Verified on the running site

```text
POST /comment  content_id=3, name "Visitor Demo"
  -> 200 "waiting for a moderator"
  -> comments: id=2  user_id=0  status=pending  author_name="Visitor Demo"

POST /comment with the honeypot filled
  -> 200 (identical page)
  -> nothing stored
```

The article page shows the form, the existing approved comment, and the moderation note.

A lesson worth recording: the first screenshot showed the form with **invisible input boxes**,
because I used `var(--line)` and `var(--ink)` in the theme CSS when the theme defines `--border` and
`--fg`. The markup was right and the tests passed; only looking at the render caught it.

---

## Files

```text
app/Modules/Theme/Http/PublicCommentController.php    NEW
app/Modules/Comment/Service/CommentService.php        guest name, user_id = 0
app/Modules/Comment/Contracts/CommentServiceInterface.php  optional $authorName
app/Modules/Theme/ThemeServiceProvider.php            POST /comment + binding
app/Modules/Theme/Rendering/PageViewModelFactory.php  comment_content_id
themes/default/partials/comments.php                  the form
themes/default/assets/theme.css                       form styles
tests/Integration/Modules/Theme/PublicCommentPostTest.php  NEW  8 tests
```

---

## Tests

| | |
|---|---|
| happy path | posts, returns the confirmation, **not** in the public thread, **is** in the queue, `user_id = 0`, name stored |
| honeypot | identical response, nothing stored |
| closed thread | 409, nothing stored |
| validation | one-character body refused (422); blank name refused (422) |
| rate limit | five succeed, the sixth is 429 and is not stored |
| tenant | another site's article is 404, nothing stored |
| markup | the form, the name field, the honeypot and the moderation note are on the article page |

```text
PHPUnit 11.5.57 - PHP 8.3.6
OK (1350 tests, 4245 assertions)
```

---

## Known limitations

1. **No reply form.** The schema supports threaded comments and the service caps depth, but a
   visitor can only start a new thread, not answer one.
2. **No email to the commenter.** There is no address to send to, so "your comment was approved"
   cannot be told.
3. **No notification on a new comment.** The moderation queue is the only signal; nothing alerts a
   moderator that something is waiting.
4. **The rate limit is per address, not per article.** A shared office cannot leave more than five
   comments in ten minutes anywhere on the site.
5. **`user_id = 0` is a convention, not a constraint.** Nothing in the schema prevents another row
   from using it for something else later; a `CHECK` or a nullable column would say it better.
6. **No spam scoring.** Every comment is pending, so a human still reads each one — the honeypot and
   the limiter reduce volume, they do not judge content.
7. **The form has no CSRF token.** Anonymous visitors have no session to bind one to; the defences
   are origin-less by nature, which is why the queue approves everything.

---

## CONTRACT_CHANGE_REQUEST

none

`CommentServiceInterface` gained one optional parameter. It is not a frozen contract, and every
existing caller keeps working unchanged.

---

## Ready to merge

yes
