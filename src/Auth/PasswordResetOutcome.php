<?php

declare(strict_types=1);

namespace Nimbus\Auth;

/** The result of attempting to set a new password with a reset token. */
enum PasswordResetOutcome
{
    /** The token was unknown, expired, or already used. */
    case InvalidToken;
    /** The token was valid but the chosen password is too weak (token left intact to retry). */
    case WeakPassword;
    /** The password was changed. */
    case Ok;
}
