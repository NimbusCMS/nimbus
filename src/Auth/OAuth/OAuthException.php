<?php

declare(strict_types=1);

namespace Nimbus\Auth\OAuth;

/** A provider call failed (token exchange, userinfo, transport). Never carries a secret. */
final class OAuthException extends \RuntimeException
{
}
