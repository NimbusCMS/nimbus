<?php

declare(strict_types=1);

namespace Nimbus\Mail;

/**
 * The one seam for sending email. Core code depends only on this interface;
 * a transport (log / native / API provider) is chosen by configuration, and a
 * new one — an SMTP-library adapter, a provider plugin — slots in behind it
 * without touching a caller. Bodies are plain text: the smallest thing that
 * serves reset and future notification mail, with no MIME to hand-roll.
 *
 * A transport throws {@see MailerException} on failure; callers that must not
 * leak (e.g. the no-enumeration reset flow) catch it and carry on.
 */
interface Mailer
{
    public function send(string $to, string $subject, string $textBody): void;
}
