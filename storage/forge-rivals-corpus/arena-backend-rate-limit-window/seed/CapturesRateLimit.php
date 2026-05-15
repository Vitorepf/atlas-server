<?php

declare(strict_types=1);

namespace App\Http\Middleware;

/**
 * Stub — no rate limiting yet. Arm implements sliding-window here.
 */
final class CapturesRateLimit
{
    public function handle(mixed $request, callable $next): mixed
    {
        return $next($request);
    }
}
