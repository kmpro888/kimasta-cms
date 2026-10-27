# Comment Replies and Email

Threading already existed in the service — the migration round proved a three-deep thread survives
an import — but **nothing on the public site could create a reply**, and the two people in a
conversation were never told it had moved.

---

## What changed

**A reply can be posted from the article.** Every visible comment carries a *Reply* link
(`?reply_to=<id>#comment-form`). The form then shows *Replying to …* with a cancel link, and posts
`parent_id`.

**The reply state is never cached.** `hasVaryingQuery()` now treats `reply_to` like `page` and `q`:
the page is a cache miss every time. Without that, the next reader would be handed somebody else's
reply box — the same bug the `?page=2` check exists to prevent.

**A reply names the right person, and only that person.** The form resolves `reply_to` against the
visible thread, so a hand-edited URL cannot label the box with a name that is not in the thread.

A reply to a comment on **another article** is rejected as `404` — absent, not present-but-forbidden.

---

## Who gets told what

| Event | Recipients | Before |
|---|---|---|
| a comment arrives | moderators (**+ the parent's author**, if the comment is a reply) | moderators only |
| a comment is approved | **its author**, if they have an account | the *article's* author — the wrong person, if anybody |

The approval path was silently notifying the wrong recipient: `comment.approved` carried no author,
so the listener fell through to its default and told the article author that a comment they did not
write had been approved.

Two bugs surfaced while wiring this, both of the "silent 0" kind:

- `comment.approved` did not carry `site_id` in its payload. The listener reads the tenant from the
  payload, saw `0`, and returned before notifying anyone. Adding the author fields alone would have
  looked correct and done nothing.
- `Request::create()` does not parse a query string out of the path, so the first version of the
  reply-box test was exercising `/blog/x?reply_to=5` as a *path* and getting a cached page. The test
  now passes the query as its own argument.

Email is not sent inline: the notification module already queues it, and `bin/cron.php` drains the
outbox. Both new notifications therefore reach email through the existing queue rather than a new
transport.

---

## The tests

| | |
|---|---|
| threading | a reply posted over HTTP is stored with the parent, and the approved thread is `[0, 1]` |
| isolation | a parent from another article answers `404` |
| the form | names the person, carries the hidden `parent_id`, and is a cache miss on the second request |
| reply notification | the person answered is notified |
| approval notification | the commenter is notified |
| anonymity | a moderator watching receives the pending notification, and nobody receives an approval one |

```text
php vendor/bin/phpunit --filter CommentReplyTest
OK (6 tests, 14 assertions)
```

---

## Files

```text
themes/default/partials/comments.php             Reply link, "Replying to", hidden parent_id
app/Modules/Theme/Http/PublicPageController.php  reply_to bypasses the page cache
app/Modules/Theme/Http/PublicCommentController.php  accepts and forwards parent_id
app/Modules/Comment/Service/CommentService.php   parent_user_id, site_id and author on approved
app/Modules/Notification/Contracts/NotificationServiceInterface.php  comment.approved event
app/Modules/Notification/Service/NotificationService.php            recipients, title, body
lang/en.php, lang/id.php                        reply, replying_to, cancel_reply
tests/Integration/Modules/Comment/CommentReplyTest.php  NEW  6 tests
```

---

## Known limitations

1. **No reply email to guests.** An anonymous commenter leaves no address, so a guest who is answered
   is never told — there is nothing to send to.
2. **No unsubscribe or digest.** A busy thread queues one email per reply.
3. **No "you were mentioned" beyond direct parentage**; deeper participants are not notified.
4. **The reply form is not pre-filled** with the parent's text, and the textarea is not focused.
5. **No edit or delete window** for a commenter after posting.
6. **Email delivery itself is `mail()`**, queued and retried three times; a shared host without a
   working MTA will queue and fail, and the outbox shows that rather than hiding it.

---

## Ready to merge

yes
