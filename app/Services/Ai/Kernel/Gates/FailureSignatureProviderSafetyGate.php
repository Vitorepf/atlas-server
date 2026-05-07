<?php

namespace App\Services\Ai\Kernel\Gates;

class FailureSignatureProviderSafetyGate
{
    /**
     * @param  array<string,mixed>  $signature
     * @return array<string,mixed>
     */
    public function evaluate(array $signature): array
    {
        $summary = (string) ($signature['context_summary'] ?? '');
        $privacyClass = (int) data_get($signature, 'canonical_features.privacy_class', 1);
        $redacted = ! preg_match('/(sk|pk|rk|ghp|gho|xox[baprs])-[-_a-zA-Z0-9]{12,}|[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $summary);

        if ($privacyClass >= 3 && ! $redacted) {
            return [
                'schema_version' => 'atlas.gate.failure_signature_provider_safety.v1',
                'gate' => 'failure_signature_provider_safety',
                'status' => 'blocked',
                'reason' => 'private_failure_context_not_redacted',
            ];
        }

        return [
            'schema_version' => 'atlas.gate.failure_signature_provider_safety.v1',
            'gate' => 'failure_signature_provider_safety',
            'status' => $redacted ? 'passed' : 'blocked',
            'reason' => $redacted ? 'provider_safe_failure_summary' : 'failure_summary_contains_secret_or_pii',
        ];
    }
}
