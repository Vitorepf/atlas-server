<?php

namespace App\Http\Middleware;

use App\Models\AtlasMobileDevice;
use App\Services\Ai\Mobile\MobilePairingService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateMobileDevice
{
    // Cacheia apenas o id do device por hash. Não cachear o model Eloquent:
    // em reloads/opcache/deploys, modelos serializados podem voltar como
    // __PHP_Incomplete_Class e derrubar o gateway mobile.
    private const DEVICE_CACHE_TTL_SECONDS = 60;

    // Só persiste last_seen_at se o último write foi há mais que isso —
    // evita UPDATE em todo request mobile (tipicamente 1-3 por minuto
    // ativos). Granularidade de 60s é mais que suficiente pra "online status".
    private const LAST_SEEN_THROTTLE_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) $request->header('Authorization', '');
        if (! str_starts_with($header, 'Bearer ')) {
            return $this->unauthorized('Missing mobile bearer token.');
        }

        $token = trim(substr($header, 7));
        if ($token === '') {
            return $this->unauthorized('Invalid mobile bearer token.');
        }

        $hash = app(MobilePairingService::class)->hashSecret($token);
        $cacheKey = self::deviceCacheKey($hash);

        $device = $this->deviceForHash($hash, $cacheKey);

        if (! $device) {
            // Não cachear o "não existe" — apaga a chave pra que retry
            // após pareamento veja o device imediatamente.
            Cache::forget($cacheKey);
            return $this->unauthorized('Invalid or revoked mobile bearer token.');
        }

        $this->touchLastSeen($device);
        $request->attributes->set('atlas_mobile_device', $device);

        return $next($request);
    }

    private function deviceForHash(string $hash, string $cacheKey): ?AtlasMobileDevice
    {
        $cachedId = Cache::get($cacheKey);
        if (is_string($cachedId) && trim($cachedId) !== '') {
            $device = AtlasMobileDevice::query()
                ->whereKey($cachedId)
                ->where('device_token_hash', $hash)
                ->whereNull('revoked_at')
                ->first();

            if ($device instanceof AtlasMobileDevice) {
                return $device;
            }

            Cache::forget($cacheKey);
        } elseif ($cachedId !== null) {
            Cache::forget($cacheKey);
        }

        $device = AtlasMobileDevice::query()
            ->where('device_token_hash', $hash)
            ->whereNull('revoked_at')
            ->first();

        if ($device instanceof AtlasMobileDevice) {
            Cache::put($cacheKey, (string) $device->getKey(), self::DEVICE_CACHE_TTL_SECONDS);
        }

        return $device;
    }

    private function touchLastSeen(AtlasMobileDevice $device): void
    {
        $now = now();
        $lastSeen = $device->last_seen_at;
        if ($lastSeen && $lastSeen->diffInSeconds($now) < self::LAST_SEEN_THROTTLE_SECONDS) {
            return;
        }

        // Update direto na coluna sem disparar events do model. Evita
        // trigger de auditorias/observers em path quente. updated_at é
        // mantido pelo trigger trg_<tabela>_updated_at no DB (CLAUDE.md).
        AtlasMobileDevice::query()
            ->whereKey($device->getKey())
            ->update(['last_seen_at' => $now]);
        $device->setAttribute('last_seen_at', $now);
    }

    private function unauthorized(string $message): Response
    {
        return response()->json([
            'error' => [
                'code' => 'UNAUTHORIZED',
                'message' => $message,
            ],
        ], 401);
    }

    public static function deviceCacheKey(string $hash): string
    {
        return 'atlas.mobile.device.by-hash:'.$hash;
    }
}
