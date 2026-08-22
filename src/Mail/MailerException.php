<?php

declare(strict_types=1);

namespace Nimbus\Mail;

/** A transport failed to hand off a message (bad address, provider error, I/O). */
final class MailerException extends \RuntimeException
{
}
