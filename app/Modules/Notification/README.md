# Notification Module — Internal + Email

## Ownership

Tables: `notifications`, `notification_outbox`.

## Two channels, two delivery profiles

| Channel | Written | Why |
|---|---|---|
| `internal` | synchronously, in the request | one cheap insert; the recipient reads it from their inbox |
| `email` | queued in `notification_outbox`, drained by cron | sending SMTP inline blocks the request on a remote server and loses the message on timeout |

Email is a **queue, not a send**. `bin/cron.php` calls `processOutbox()`. No long-running
worker — which the production constraints forbid.

## Events consumed

```text
content.submitted
content.revision_requested
content.approved
content.scheduled
content.published
```

## Recipients are derived, never stored

- `content.submitted` → users holding `workflow.review` (minus the author, who does not
  need to be told about their own submission)
- everything else → the content author

Resolved through the role assignment at send time, so a role change takes effect
immediately instead of leaving a stale recipient list behind.

## Event × channel switches

Each pair is independently switchable, stored in site settings under the `notifications`
namespace as `<event>.<channel>`.

| Default | Value |
|---|---|
| internal | **on** |
| email | **off** |

Email defaults off deliberately: a fresh install must not start mailing people because
someone published a post, and a misconfigured site that mails on every event gets its
domain blocked.

## Bounded retries

`attempts` and `last_error` exist so a permanently invalid address ends up as `failed`
after 3 attempts instead of being retried forever or silently dropped. Backoff is
`300s × attempt`, stored in `available_at` so cron picks it up when due.

## Privacy

`markRead()` and the inbox query both put `user_id` in the `WHERE` clause, so an id
belonging to another user matches nothing. The API answers 404 rather than 403, which
would confirm that another user has a notification with that id.

## Not implemented

- No SMTP transport out of the box; `NativeMailTransport` wraps `mail()`. Bind a different
  `MailTransportInterface` for a relay.
- No digest or batching: one email per event per recipient.
- No per-user (as opposed to per-site) channel preference.
- No notification templates; titles and bodies are composed in the service.
- No purge of old notifications; the table grows until an operator prunes it.
