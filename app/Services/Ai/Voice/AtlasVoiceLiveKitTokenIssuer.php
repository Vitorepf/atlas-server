<?php

namespace App\Services\Ai\Voice;

use Illuminate\Support\Str;

final class AtlasVoiceLiveKitTokenIssuer
{
    /**
     * @param  array<string,mixed>  $session
     * @param  array<string,mixed>  $lease
     * @return array<string,mixed>
     */
    public function issue(array $session, array $lease): array
    {
        if (! $this->enabled()) {
            return [
                'issued' => false,
                'status' => 'not_issued_scaffold',
                'issuer' => 'livekit_pending',
                'reason' => 'livekit_token_issuer_disabled',
            ];
        }

        $apiKey = $this->apiKey();
        $apiSecret = $this->apiSecret();
        if ($apiKey === '' || $apiSecret === '') {
            return [
                'issued' => false,
                'status' => 'not_issued_missing_config',
                'issuer' => 'atlas_voice_livekit_token_issuer',
                'reason' => 'livekit_api_key_or_secret_missing',
            ];
        }

        $now = now();
        $ttl = max(60, min(3600, (int) config('atlas.voice.livekit.token_ttl_seconds', 900)));
        $expiresAt = $now->copy()->addSeconds($ttl);
        $roomName = (string) ($lease['room_name'] ?? 'atlas-voice');
        $participant = (string) ($lease['participant_identity'] ?? data_get($session, 'operator.operator_id', 'voice_operator'));

        $claims = [
            'iss' => $apiKey,
            'sub' => $participant,
            'nbf' => $now->timestamp,
            'exp' => $expiresAt->timestamp,
            'jti' => (string) Str::ulid(),
            'video' => [
                'roomJoin' => true,
                'room' => $roomName,
                'canPublish' => true,
                'canSubscribe' => true,
                'canPublishData' => true,
            ],
            'metadata' => json_encode([
                'surface_id' => 'voice_realtime',
                'session_id' => $session['session_id'] ?? null,
                'client_surface' => $session['client_surface'] ?? null,
                'kernel_decision_required_per_turn' => true,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ];

        return [
            'issued' => true,
            'status' => 'issued',
            'issuer' => 'atlas_voice_livekit_token_issuer',
            'access_token' => $this->jwt($claims, $apiSecret),
            'expires_at' => $expiresAt->toJSON(),
            'ttl_seconds' => $ttl,
            'grant' => [
                'room_join' => true,
                'room' => $roomName,
                'can_publish' => true,
                'can_subscribe' => true,
                'can_publish_data' => true,
            ],
        ];
    }

    public function enabled(): bool
    {
        return (bool) config('atlas.voice.livekit.token_issuer_enabled', false);
    }

    public function liveKitUrl(): ?string
    {
        $url = rtrim(trim((string) config('atlas.voice.livekit.url', '')), '/');

        return $url !== '' ? $url : null;
    }

    private function apiKey(): string
    {
        return trim((string) config('atlas.voice.livekit.api_key', ''));
    }

    private function apiSecret(): string
    {
        return trim((string) config('atlas.voice.livekit.api_secret', ''));
    }

    /**
     * @param  array<string,mixed>  $claims
     */
    private function jwt(array $claims, string $secret): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $segments = [
            $this->base64Url(json_encode($header, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            $this->base64Url(json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
        ];
        $signature = hash_hmac('sha256', implode('.', $segments), $secret, true);
        $segments[] = $this->base64Url($signature);

        return implode('.', $segments);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
