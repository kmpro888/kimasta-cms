<?php

declare(strict_types=1);

/*
 * Outgoing mail identity.
 *
 * Used by the notification email transport. Delivery itself goes through PHP's `mail()`
 * because that is what a shared host offers; an operator with an SMTP relay binds a
 * different MailTransportInterface.
 *
 * `from_address` must be a mailbox on a domain the host is allowed to send for, or every
 * notification will be filed as spam and the site's domain reputation suffers.
 */
return [
    'from_address' => '',
    'from_name' => '',
];
