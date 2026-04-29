<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateAtlasToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('atlas.token');

        if (! is_string($expected) || strlen($expected) < 24) {
            return response()->json([
                'error' => [
                    'code' => 'SERVER_MISCONFIGURED',
                    'message' => 'ATLAS_TOKEN is not configured.',
                ],
            ], 500);
        }

        if (! hash_equals($expected, (string) $request->header('X-Atlas-Token', ''))) {
            return response()->json([
                'error' => [
                    'code' => 'UNAUTHORIZED',
                    'message' => 'Invalid or missing X-Atlas-Token.',
                ],
            ], 401);
        }

        return $next($request);
    }
}
