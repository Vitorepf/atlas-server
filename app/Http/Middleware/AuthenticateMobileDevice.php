<?php

namespace App\Http\Middleware;

use App\Models\AtlasMobileDevice;
use App\Services\Ai\Mobile\MobilePairingService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateMobileDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) $request->header('Authorization', '');
        if (! str_starts_with($header, 'Bearer ')) {
            return response()->json([
                'error' => [
                    'code' => 'UNAUTHORIZED',
                    'message' => 'Missing mobile bearer token.',
                ],
            ], 401);
        }

        $token = trim(substr($header, 7));
        if ($token === '') {
            return response()->json([
                'error' => [
                    'code' => 'UNAUTHORIZED',
                    'message' => 'Invalid mobile bearer token.',
                ],
            ], 401);
        }

        $hash = app(MobilePairingService::class)->hashSecret($token);
        $device = AtlasMobileDevice::query()
            ->where('device_token_hash', $hash)
            ->whereNull('revoked_at')
            ->first();

        if (! $device) {
            return response()->json([
                'error' => [
                    'code' => 'UNAUTHORIZED',
                    'message' => 'Invalid or revoked mobile bearer token.',
                ],
            ], 401);
        }

        $device->update(['last_seen_at' => now()]);
        $request->attributes->set('atlas_mobile_device', $device->refresh());

        return $next($request);
    }
}
