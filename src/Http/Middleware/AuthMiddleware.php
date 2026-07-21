<?php

declare(strict_types=1);

namespace Nimbus\Http\Middleware;

use Nimbus\Auth\Auth;
use Nimbus\Http\Request;
use Nimbus\Http\Response;

/**
 * Redirects unauthenticated requests to the login page before the handler runs.
 * Applied to the admin route group so every admin route is gated in one place
 * (fine-grained admin/manage checks still live in the controllers).
 */
final class AuthMiddleware
{
    public function __construct(private Auth $auth)
    {
    }

    public function __invoke(Request $request): ?Response
    {
        return $this->auth->check() ? null : Response::redirect('/admin/login');
    }
}
