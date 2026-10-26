<?php

declare(strict_types=1);

namespace CMS\Modules\Notification\Mail;

use CMS\Modules\Notification\Contracts\MailTransportInterface;

/**
 * PHP's built-in `mail()`.
 *
 * The only transport a plain shared host guarantees. `mail()` returns false when the local
 * MTA refuses the message; that is surfaced as an exception so the outbox can record it and
 * retry, rather than losing the message silently.
 */
final class NativeMailTransport implements MailTransportInterface
{
    public function __construct(private readonly string $fromAddress, private readonly string $fromName = '') {
    }

    public function send(string $to, string $subject, string $body): void
    {
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . $this->formatFrom(),
        ];

        // A subject containing a newline could inject additional headers.
        $safeSubject = str_replace(["\r", "\n"], ' ', $subject);
        $safeTo = str_replace(["\r", "\n"], '', $to);

        if (@mail($safeTo, $safeSubject, $body, implode("\r\n", $headers)) !== true) {
            throw new \RuntimeException('mail() refused the message.');
        }
    }

    private function formatFrom(): string
    {
        // Header injection is possible through either field, so both are stripped.
        $name = str_replace(["\r", "\n", '"'], '', $this->fromName);
        $address = str_replace(["\r", "\n"], '', $this->fromAddress);

        return $name === '' ? $address : '"' . $name . '" <' . $address . '>';
    }
}
