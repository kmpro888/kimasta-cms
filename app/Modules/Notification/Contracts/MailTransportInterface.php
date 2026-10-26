<?php

declare(strict_types=1);

namespace CMS\Modules\Notification\Contracts;

/**
 * Email transport.
 *
 * Behind an interface so the platform does not hard-code PHP's `mail()`, and so tests can
 * assert what *would* have been sent without opening a socket. `mail()` is the default
 * because it is what a shared host offers; an operator with an SMTP relay can bind a
 * different implementation without touching the notification service.
 *
 * FROZEN CONTRACT.
 */
interface MailTransportInterface
{
    /**
     * Deliver one message.
     *
     * @throws \RuntimeException when delivery fails; the outbox records the reason and
     *                           retries a bounded number of times
     */
    public function send(string $to, string $subject, string $body): void;
}
