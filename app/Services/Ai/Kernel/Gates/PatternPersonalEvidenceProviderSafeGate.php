<?php

namespace App\Services\Ai\Kernel\Gates;

class PatternPersonalEvidenceProviderSafeGate
{
    /**
     * @param  array<string,mixed>  $pattern
     * @return array<string,mixed>
     */
    public function evaluate(array $pattern): array
    {
        $refs = (array) ($pattern['personal_evidence_refs'] ?? []);
        $unsafe = collect($refs)->contains(fn (mixed $ref): bool => is_array($ref)
            && (int) ($ref['privacy_class'] ?? 1) >= 3
            && (bool) ($ref['redacted'] ?? false) === false);

        return [
            'schema_version' => 'atlas.gate.pattern_personal_evidence_provider_safe.v1',
            'gate' => 'pattern_personal_evidence_provider_safe',
            'status' => $unsafe ? 'blocked' : 'passed',
            'reason' => $unsafe ? 'pattern_personal_evidence_requires_redaction' : 'pattern_personal_evidence_provider_safe',
        ];
    }
}
