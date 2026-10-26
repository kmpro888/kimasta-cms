# Comment Module — Comment Threads

## Ownership

Table: `comments`.

## Rules

1. **Submission requires an authenticated user.** Anonymous commenting is out of
   scope, which removes the need for a captcha in the core.
2. **Depth is bounded at write time** (`MAX_DEPTH = 3`). A reply beyond the limit is
   refused, so rendering is a flat loop and a thread can always be paginated. Walking
   up an unbounded tree at render time is the classic way to make a comment section
   expensive.
3. **New comments are `pending`.** Auto-approving would put unreviewed text on a
   public page.
4. **The approved count is derived, never stored.** A denormalised counter drifts the
   first time a write path forgets it; one indexed `COUNT` is only paid on pages that
   show the number.
5. **`author_name` is denormalised at submission.** Renaming a user must not rewrite
   history in old threads.

## States

```text
pending → approved → trash
         ↘ spam
```

`spam` is distinct from `trash` on purpose: a moderator's decision should not be
confused with a deletion.

## Events

Emitted **after** the write succeeds:

```text
comment.created    on submission
comment.approved   only on a real transition (twice-approving emits once)
comment.deleted    on hard delete
```

## Invariants enforced by tests

- a pending comment never appears in the public thread
- approving twice emits one event, not two
- a reply to a pending parent is refused (it would be an orphan in public)
- a reply cannot attach to another article's thread
- comments are refused on a draft and on a closed article
- deleting a parent removes replies through the foreign-key cascade
- a cross-site comment id is a 404

## Not implemented

- Nesting beyond depth 3 is refused, not flattened into the last level. A "reply to a
  deep comment" affordance would need UI work.
- No editing a comment after submission.
- No email notification on a new comment; notifications are Agent 14E and not built.
- No per-user rate limit on submission.
