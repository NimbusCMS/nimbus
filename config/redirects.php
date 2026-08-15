<?php

declare(strict_types=1);

/**
 * URL redirects, applied before routing.
 *
 * Each key is an exact source path; the value is where it goes. A plain string
 * is a permanent (301) redirect; use the array form to choose the status.
 *
 *   return [
 *       '/old-post'   => '/posts/new-post',              // 301 (permanent)
 *       '/promo'      => ['to' => '/posts/sale', 'status' => 302],
 *   ];
 */

return [];
