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

        if ($this->liveKitUrl() === null) {
            return [
                'issued' => false,
                'status' => 'not_issued_missing_config',
                'issuer' => 'atlas_voice_livekit_token_issuer',
                'reason' => 'livekit_url_missing',
            ];
        }

        $now = now();
        $ttl = max(60, min(3600, (int) config('atlas.voice.livekit.token_ttl_seconds', 900)));
        $expiresAt = $now->copy()->addSeconds($ttl);
        $roomName = (string) ($lease['room_name'] ?? 'atlas-voice');
        $participant = (string) ($lease['participant_identity'] ?? data_get($session, 'operator.operator_id', 'voice_operator'));
        $clientSurface = (string) ($session['client_surface'] ?? 'mobile');

        if (! str_starts_with($roomName, 'atlas-voice-')) {
            return [
                'issued' => false,
                'status' => 'not_issued_invalid_lease',
                'issuer' => 'atlas_voice_livekit_token_issuer',
                'reason' => 'livekit_room_outside_atlas_voice_namespace',
            ];
        }

        if (! str_starts_with($participant, $clientSurface.':')) {
            return [
                'issued' => false,
                'status' => 'not_issued_invalid_lease',
                'issuer' => 'atlas_voice_livekit_token_issuer',
                'reason' => 'livekit_participant_outside_client_surface_namespace',
            ];
        }

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

    /**
     * @return array<string,mixed>
     */
    public function readiness(): array
    {
        $enabled = $this->enabled();
        $urlConfigured = $this->liveKitUrl() !== null;
        $apiKeyConfigured = $this->apiKey() !== '';
        $apiSecretConfigured = $this->apiSecret() !== '';
        $ready = $enabled && $urlConfigured && $apiKeyConfigured && $apiSecretConfigured;

        return [
            'schema_version' => 'atlas.voice_realtime.livekit_token_issuer_readiness.v1',
            'status' => $ready ? 'ready' : 'blocked',
            'enabled' => $enabled,
            'livekit_url_configured' => $urlConfigured,
            'api_key_configured' => $apiKeyConfigured,
            'api_secret_configured' => $apiSecretConfigured,
            'secrets_exposed' => false,
            'next_action' => $ready ? 'run_voice_runtime_certification_with_require_sdk' : 'configure_livekit_token_issuer',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function configurationPlan(): array
    {
        $readiness = $this->readiness();
        $requiredEnv = [
            [
                'name' => 'ATLAS_VOICE_LIVEKIT_TOKEN_ISSUER_ENABLED',
                'config_key' => 'atlas.voice.livekit.token_issuer_enabled',
                'required_value_shape' => 'boolean_true',
                'configured' => (bool) $readiness['enabled'],
                'secret' => false,
            ],
            [
                'name' => 'LIVEKIT_URL',
                'config_key' => 'atlas.voice.livekit.url',
                'required_value_shape' => 'https_or_local_livekit_url',
                'configured' => (bool) $readiness['livekit_url_configured'],
                'secret' => false,
            ],
            [
                'name' => 'LIVEKIT_API_KEY',
                'config_key' => 'atlas.voice.livekit.api_key',
                'required_value_shape' => 'non_empty_livekit_api_key',
                'configured' => (bool) $readiness['api_key_configured'],
                'secret' => true,
            ],
            [
                'name' => 'LIVEKIT_API_SECRET',
                'config_key' => 'atlas.voice.livekit.api_secret',
                'required_value_shape' => 'non_empty_livekit_api_secret',
                'configured' => (bool) $readiness['api_secret_configured'],
                'secret' => true,
            ],
            [
                'name' => 'ATLAS_VOICE_LIVEKIT_TOKEN_TTL_SECONDS',
                'config_key' => 'atlas.voice.livekit.token_ttl_seconds',
                'required_value_shape' => 'integer_between_60_and_3600',
                'configured' => true,
                'secret' => false,
                'default' => 900,
            ],
        ];
        $missing = collect($requiredEnv)
            ->filter(fn (array $item): bool => ! (bool) $item['configured'])
            ->pluck('name')
            ->values()
            ->all();
        $templateLines = [
            'ATLAS_VOICE_LIVEKIT_TOKEN_ISSUER_ENABLED=true',
            'LIVEKIT_URL=https://<your-livekit-host>',
            'LIVEKIT_API_KEY=<set-in-local-env-only>',
            'LIVEKIT_API_SECRET=<set-in-local-env-only>',
            'ATLAS_VOICE_LIVEKIT_TOKEN_TTL_SECONDS=900',
        ];

        return [
            'schema_version' => 'atlas.voice_realtime.livekit_token_issuer_config_plan.v1',
            'status' => $missing === [] ? 'ready' : 'blocked',
            'surface_id' => 'voice_realtime',
            'runtime_id' => 'livekit_agents_sdk',
            'mobile_first' => true,
            'kernel_only' => true,
            'readiness' => $readiness,
            'required_env' => $requiredEnv,
            'missing_env' => $missing,
            'redacted_env_template' => $templateLines,
            'redacted_env_template_hash' => hash('sha256', implode("\n", $templateLines)),
            'security_contract' => [
                'secrets_exposed' => false,
                'writes_env_file' => false,
                'starts_daemon' => false,
                'issues_token_during_plan' => false,
                'raw_audio_persistence_allowed' => false,
                'direct_provider_call_allowed' => false,
                'direct_tool_execution_allowed' => false,
            ],
            'validation_commands' => [
                'php artisan atlas:ai:voice token-issuer-plan --json',
                'php artisan atlas:ai:voice runtime-certify --require-sdk --json',
                'php artisan atlas:ai:voice promotion-review-packet --hours=24 --json',
            ],
            'next_action' => $missing === []
                ? 'run_voice_runtime_certification_with_require_sdk'
                : 'set_missing_livekit_env_in_local_environment_only',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function smoke(bool $ephemeralTestConfig = false): array
    {
        $originalConfig = [
            'enabled' => config('atlas.voice.livekit.token_issuer_enabled'),
            'url' => config('atlas.voice.livekit.url'),
            'api_key' => config('atlas.voice.livekit.api_key'),
            'api_secret' => config('atlas.voice.livekit.api_secret'),
            'ttl_seconds' => config('atlas.voice.livekit.token_ttl_seconds'),
        ];

        if ($ephemeralTestConfig) {
            config()->set('atlas.voice.livekit.token_issuer_enabled', true);
            config()->set('atlas.voice.livekit.url', 'http://livekit-smoke.test');
            config()->set('atlas.voice.livekit.api_key', 'atlas-smoke-key');
            config()->set('atlas.voice.livekit.api_secret', 'atlas-smoke-secret');
            config()->set('atlas.voice.livekit.token_ttl_seconds', 120);
        }

        try {
            $readiness = $this->readiness();
            $lease = [
                'room_name' => 'atlas-voice-smoke',
                'participant_identity' => 'mobile:smoke',
            ];
            $session = [
                'session_id' => 'voice_session_token_issuer_smoke',
                'client_surface' => 'mobile',
            ];
            $issued = ($readiness['status'] ?? null) === 'ready'
                ? $this->issue($session, $lease)
                : [
                    'issued' => false,
                    'status' => 'not_issued_missing_config',
                    'issuer' => 'atlas_voice_livekit_token_issuer',
                    'reason' => 'livekit_token_issuer_not_ready',
                ];
            $token = is_string($issued['access_token'] ?? null) ? $issued['access_token'] : null;
            unset($issued['access_token']);

            return [
                'schema_version' => 'atlas.voice_realtime.livekit_token_issuer_smoke.v1',
                'status' => ($issued['issued'] ?? false) === true ? 'passed' : 'blocked',
                'surface_id' => 'voice_realtime',
                'runtime_id' => 'livekit_agents_sdk',
                'mobile_first' => true,
                'kernel_only' => true,
                'ephemeral_test_config' => $ephemeralTestConfig,
                'production_readiness' => $ephemeralTestConfig
                    ? 'not_proven_by_ephemeral_smoke'
                    : 'current_environment_checked',
                'readiness' => $readiness,
                'token_issued' => ($issued['issued'] ?? false) === true,
                'token_hash' => $token !== null ? hash('sha256', $token) : null,
                'token_segments_count' => $token !== null ? count(explode('.', $token)) : 0,
                'access_token_exposed' => false,
                'issued_token' => $issued,
                'smoke_lease' => $lease,
                'security_contract' => [
                    'secrets_exposed' => false,
                    'access_token_exposed' => false,
                    'writes_env_file' => false,
                    'starts_daemon' => false,
                    'raw_audio_persistence_allowed' => false,
                    'direct_provider_call_allowed' => false,
                    'direct_tool_execution_allowed' => false,
                    'ephemeral_config_persists_after_command' => false,
                ],
                'next_action' => match (true) {
                    ($issued['issued'] ?? false) === true && ! $ephemeralTestConfig => 'run_voice_runtime_certification_with_require_sdk',
                    ($issued['issued'] ?? false) === true && $ephemeralTestConfig => 'configure_real_livekit_env_then_run_token_issuer_smoke_without_ephemeral_test_config',
                    default => 'configure_livekit_token_issuer',
                },
            ];
        } finally {
            config()->set('atlas.voice.livekit.token_issuer_enabled', $originalConfig['enabled']);
            config()->set('atlas.voice.livekit.url', $originalConfig['url']);
            config()->set('atlas.voice.livekit.api_key', $originalConfig['api_key']);
            config()->set('atlas.voice.livekit.api_secret', $originalConfig['api_secret']);
            config()->set('atlas.voice.livekit.token_ttl_seconds', $originalConfig['ttl_seconds']);
        }
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
