<?php

namespace App\Services;

class CapturePrivacyService
{
    private const SENSITIVITIES = ['normal', 'private', 'sensitive'];

    public function __construct(private readonly AtlasDomainRegistry $domains) {}

    public function normalizeMetadata(array $metadata, string $domain, string $kind): array
    {
        $privacy = $this->arrayValue($metadata['privacy'] ?? []);
        $sensitivity = $this->normalizeSensitivity(
            $privacy['sensitivity']
                ?? $metadata['sensitivity']
                ?? $metadata['sensitivity_level']
                ?? null,
            $domain,
        );
        $externalAiAllowed = $this->externalAiAllowed($sensitivity, $privacy['external_ai_allowed'] ?? null, $domain);
        $policy = [
            ...$privacy,
            'domain' => $domain,
            'capture_kind' => $kind,
            'sensitivity' => $sensitivity,
            'external_ai_allowed' => $externalAiAllowed,
            'semantic_processing' => true,
            'vault_visibility' => $this->vaultVisibility($sensitivity),
            'raw_retention' => 'preserve_original',
            'normalized_at' => now()->toJSON(),
        ];

        return [
            ...$metadata,
            'sensitivity' => $sensitivity,
            'privacy' => $policy,
            'privacy_audit' => array_slice([
                [
                    'event' => 'capture_privacy_normalized',
                    'domain' => $domain,
                    'kind' => $kind,
                    'sensitivity' => $sensitivity,
                    'external_ai_allowed' => $externalAiAllowed,
                    'at' => now()->toJSON(),
                ],
                ...$this->arrayList($metadata['privacy_audit'] ?? []),
            ], 0, 20),
        ];
    }

    public function externalAiAllowedForMetadata(?array $metadata): bool
    {
        if (! is_array($metadata)) {
            return true;
        }

        $allowed = data_get($metadata, 'privacy.external_ai_allowed');
        if (is_bool($allowed)) {
            return $allowed;
        }
        $sensitivity = data_get($metadata, 'privacy.sensitivity', data_get($metadata, 'sensitivity', 'normal'));

        $domain = data_get($metadata, 'privacy.domain');

        return $this->externalAiAllowed(
            is_string($sensitivity) ? $sensitivity : 'normal',
            null,
            is_string($domain) ? $domain : null,
        );
    }

    private function normalizeSensitivity(mixed $value, string $domain): string
    {
        $candidate = is_string($value) ? trim($value) : '';
        if (in_array($candidate, self::SENSITIVITIES, true)) {
            return $candidate;
        }

        $default = $this->domains->defaultSensitivity($domain);

        return in_array($default, self::SENSITIVITIES, true) ? $default : 'normal';
    }

    private function externalAiAllowed(string $sensitivity, mixed $explicit, ?string $domain = null): bool
    {
        if (is_bool($explicit)) {
            return $explicit;
        }

        if ($domain && $this->domains->externalAiPolicy($domain) === 'block_all') {
            return false;
        }

        $blocked = config('atlas.privacy.block_external_ai_for_sensitivity', ['private', 'sensitive']);
        $blocked = is_array($blocked) ? $blocked : ['private', 'sensitive'];

        return ! in_array($sensitivity, $blocked, true);
    }

    private function vaultVisibility(string $sensitivity): string
    {
        return match ($sensitivity) {
            'sensitive' => 'restricted',
            'private' => 'private',
            default => 'standard',
        };
    }

    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function arrayList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, fn (mixed $item): bool => is_array($item)));
    }
}
