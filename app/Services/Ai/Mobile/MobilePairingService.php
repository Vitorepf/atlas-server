<?php

namespace App\Services\Ai\Mobile;

use App\Models\AtlasMobileDevice;
use App\Models\MobilePairingCode;
use App\Services\AuditLogService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MobilePairingService
{
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(private readonly AuditLogService $audit)
    {
    }

    /**
     * @return array{code:string,expires_at:string,pairing_id:string}
     */
    public function initiate(string $deviceLabel, string $userId = 'vitor'): array
    {
        $ttlMinutes = max(5, (int) config('atlas.mobile.pairing_ttl_minutes', 60));
        $code = $this->generateCode();

        $pairing = MobilePairingCode::query()->create([
            'user_id' => $userId,
            'code_hash' => $this->hashSecret($code),
            'device_label' => Str::limit(trim($deviceLabel) !== '' ? trim($deviceLabel) : 'Atlas mobile device', 80, ''),
            'expires_at' => now()->addMinutes($ttlMinutes),
        ]);

        $this->audit->record('mobile.pairing.initiated', [
            'subject_type' => 'mobile_pairing_code',
            'subject_id' => $pairing->id,
            'actor_type' => 'operator_cli',
            'severity' => 'info',
            'summary' => 'Mobile pairing code generated.',
            'evidence' => [
                'device_label' => $pairing->device_label,
                'expires_at' => $pairing->expires_at?->toJSON(),
            ],
            'privacy' => ['sensitivity' => 'private'],
        ]);

        return [
            'code' => $code,
            'expires_at' => $pairing->expires_at?->toJSON() ?? now()->addMinutes($ttlMinutes)->toJSON(),
            'pairing_id' => $pairing->id,
        ];
    }

    /**
     * @param  array{platform:string,device_label?:string|null,expo_push_token?:string|null,app_version?:string|null,os_version?:string|null,notification_permissions?:string|null}  $data
     * @return array{device:AtlasMobileDevice,device_token:string}
     */
    public function confirm(string $code, array $data): array
    {
        $pairing = MobilePairingCode::query()
            ->where('code_hash', $this->hashSecret($this->normalizeCode($code)))
            ->first();

        if (! $pairing) {
            throw ValidationException::withMessages(['code' => 'Codigo de pareamento invalido.']);
        }

        if ($pairing->locked_until && $pairing->locked_until->isFuture()) {
            throw ValidationException::withMessages(['code' => 'Codigo temporariamente bloqueado por tentativas invalidas.']);
        }

        if ($pairing->consumed_at !== null) {
            throw ValidationException::withMessages(['code' => 'Codigo de pareamento ja foi usado.']);
        }

        if ($pairing->expires_at->isPast()) {
            throw ValidationException::withMessages(['code' => 'Codigo de pareamento expirado.']);
        }

        $platform = strtolower((string) $data['platform']);
        if (! in_array($platform, ['ios', 'android'], true)) {
            throw ValidationException::withMessages(['platform' => 'Platform precisa ser ios ou android.']);
        }

        $deviceToken = 'atlas_mobile_'.bin2hex(random_bytes(32));
        $expoPushToken = $this->nullableString($data['expo_push_token'] ?? null);
        $label = $this->nullableString($data['device_label'] ?? null) ?: $pairing->device_label;

        $device = AtlasMobileDevice::query()->create([
            'user_id' => $pairing->user_id,
            'device_label' => Str::limit($label, 80, ''),
            'platform' => $platform,
            'app_version' => $this->nullableString($data['app_version'] ?? null),
            'os_version' => $this->nullableString($data['os_version'] ?? null),
            'expo_push_token' => $expoPushToken,
            'push_token_hash' => $expoPushToken ? hash('sha256', $expoPushToken) : null,
            'device_token_hash' => $this->hashSecret($deviceToken),
            'notification_permissions' => $this->nullableString($data['notification_permissions'] ?? null) ?: 'unknown',
            'last_seen_at' => now(),
            'paired_at' => now(),
            'metadata' => [],
        ]);

        $pairing->update(['consumed_at' => now()]);

        $this->audit->record('mobile.pairing.confirmed', [
            'subject_type' => 'atlas_mobile_device',
            'subject_id' => $device->id,
            'actor_type' => 'mobile_device',
            'actor_id' => $device->id,
            'severity' => 'info',
            'summary' => 'Mobile device paired.',
            'evidence' => [
                'device_label' => $device->device_label,
                'platform' => $device->platform,
                'notification_permissions' => $device->notification_permissions,
            ],
            'privacy' => ['sensitivity' => 'private'],
        ]);

        return [
            'device' => $device,
            'device_token' => $deviceToken,
        ];
    }

    public function revoke(AtlasMobileDevice $device, string $actorType = 'operator_cli'): AtlasMobileDevice
    {
        if ($device->revoked_at === null) {
            $device->update([
                'revoked_at' => now(),
                'expo_push_token' => null,
                'push_token_hash' => null,
            ]);
        }

        $this->audit->record('mobile.device.revoked', [
            'subject_type' => 'atlas_mobile_device',
            'subject_id' => $device->id,
            'actor_type' => $actorType,
            'actor_id' => $device->id,
            'severity' => 'warning',
            'summary' => 'Mobile device revoked.',
            'evidence' => [
                'device_label' => $device->device_label,
                'platform' => $device->platform,
            ],
            'privacy' => ['sensitivity' => 'private'],
        ]);

        return $device->refresh();
    }

    public function updatePushToken(AtlasMobileDevice $device, ?string $expoPushToken, ?string $permissions = null): AtlasMobileDevice
    {
        $expoPushToken = $this->nullableString($expoPushToken);

        $device->update([
            'expo_push_token' => $expoPushToken,
            'push_token_hash' => $expoPushToken ? hash('sha256', $expoPushToken) : null,
            'notification_permissions' => $this->nullableString($permissions) ?: $device->notification_permissions,
            'last_seen_at' => now(),
        ]);

        return $device->refresh();
    }

    public function hashSecret(string $secret): string
    {
        $key = (string) config('app.key', 'atlas');

        return hash_hmac('sha256', $secret, $key);
    }

    private function generateCode(): string
    {
        $code = '';
        $max = strlen(self::CODE_ALPHABET) - 1;
        for ($i = 0; $i < 8; $i++) {
            $code .= self::CODE_ALPHABET[random_int(0, $max)];
        }

        return $code;
    }

    private function normalizeCode(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Z0-9]/i', '', $code) ?? '');
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
