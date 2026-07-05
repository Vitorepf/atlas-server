<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure gate that compresses reasoning trace evidence into provider-safe
 * decision facts for task origin without leaking raw prompts.
 *
 * Rules:
 *   - Raw traces (raw_prompt, provider_trace, api_key) are EXCLUDED
 *   - Decision facts (decision, rationale, evidence_refs) are PRESERVED
 *   - Missing decision facts BLOCK compression
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainReasoningTraceCompressionGate
{
    public const SCHEMA = 'atlas.external_brain.reasoning_trace_compression_gate.v1';

    private const FORBIDDEN_KEYS = [
        'raw_prompt', 'provider_trace', 'api_key', 'secret',
        'password', 'token', 'authorization', 'credential',
    ];

    private const REQUIRED_FACTS = ['decision', 'rationale'];

    /**
     * @param  array<string, mixed>  $trace
     * @return array<string, mixed>
     */
    public function compress(array $trace): array
    {
        $failures = [];

        // Check for required decision facts.
        foreach (self::REQUIRED_FACTS as $fact) {
            $value = trim((string) ($trace[$fact] ?? ''));
            if ($value === '') {
                $failures[] = 'missing_decision_fact:'.$fact;
            }
        }

        if ($failures !== []) {
            return [
                'schema_version' => self::SCHEMA,
                'compressed' => false,
                'failures' => $failures,
                'decision_facts' => [],
                'provider_safe' => true,
            ];
        }

        // Extract only provider-safe decision facts.
        $decisionFacts = [];
        foreach ($trace as $key => $value) {
            $lowerKey = strtolower($key);
            $isForbidden = false;
            foreach (self::FORBIDDEN_KEYS as $forbidden) {
                if (str_contains($lowerKey, $forbidden)) {
                    $isForbidden = true;
                    break;
                }
            }
            if (! $isForbidden) {
                $decisionFacts[$key] = $value;
            }
        }

        return [
            'schema_version' => self::SCHEMA,
            'compressed' => true,
            'failures' => [],
            'decision_facts' => $decisionFacts,
            'provider_safe' => true,
            'excluded_keys' => array_values(array_filter(array_keys($trace), static function (string $key): bool {
                $lower = strtolower($key);
                foreach (self::FORBIDDEN_KEYS as $forbidden) {
                    if (str_contains($lower, $forbidden)) {
                        return true;
                    }
                }

                return false;
            })),
        ];
    }
}
