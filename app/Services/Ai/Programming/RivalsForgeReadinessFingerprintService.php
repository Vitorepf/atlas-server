<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use App\Services\Ai\Support\AiValueNormalizer;

/**
 * Rivals Forge Readiness Fingerprint v1.
 *
 * Canonical fingerprint shared by preflight, dry-run, runbook and the real
 * runner. Two operators of the Rivals battery (operator-facing runbook vs.
 * runner) MUST compute the same fingerprint when they intend the same run,
 * and MUST disagree visibly when the intent diverges. A divergence becomes
 * `fingerprint_mismatch_runbook_vs_run` with the exact list of fields that
 * differ — so the operator knows the run config no longer matches what was
 * reviewed during the runbook.
 *
 * Schema: atlas.programming.rivals_forge_readiness_fingerprint.v1
 */
class RivalsForgeReadinessFingerprintService
{
    public const SCHEMA_VERSION = 'atlas.programming.rivals_forge_readiness_fingerprint.v1';

    /** @var list<string> Tuple of fields that participate in the fingerprint. Order matters for human diff readability. */
    public const COMPONENT_KEYS = [
        'suite_id',
        'preset',
        'atlas_model',
        'baseline_model',
        'atlas_workspace_hash',
        'baseline_workspace_hash',
        'case_ids',
        'gate_profile',
        'test_command',
    ];

    /**
     * Build a deterministic fingerprint from an intent map. Any missing key
     * is normalized to a canonical sentinel so two operators producing the
     * same intent (one explicitly, one via defaults) still match.
     *
     * @param  array<string,mixed>  $intent
     * @return array<string,mixed>
     */
    public function compute(array $intent): array
    {
        $components = $this->normalizeComponents($intent);
        $serialized = json_encode($components, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
        $value = hash('sha256', $serialized);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'value' => $value,
            'components' => $components,
            'computed_at' => now()->toJSON(),
        ];
    }

    /**
     * Diagnose whether an expected fingerprint (e.g. captured at runbook
     * time) matches the actual fingerprint (e.g. computed at run time) and
     * surface the per-field diff so the operator sees exactly which knob
     * moved between review and dispatch.
     *
     * @param  array<string,mixed>|null  $expected
     * @param  array<string,mixed>  $actual
     * @return array<string,mixed>
     */
    public function diagnose(?array $expected, array $actual): array
    {
        if ($expected === null || ! isset($expected['value'])) {
            return [
                'matches' => false,
                'reason' => 'no_expected_fingerprint',
                'diff_fields' => array_values(self::COMPONENT_KEYS),
                'expected' => null,
                'actual' => $actual['value'] ?? null,
            ];
        }

        $expectedComponents = (array) ($expected['components'] ?? []);
        $actualComponents = (array) ($actual['components'] ?? []);

        $diffFields = [];
        foreach (self::COMPONENT_KEYS as $key) {
            if (($expectedComponents[$key] ?? null) !== ($actualComponents[$key] ?? null)) {
                $diffFields[] = $key;
            }
        }

        $matches = $diffFields === [] && ($expected['value'] ?? null) === ($actual['value'] ?? null);

        return [
            'matches' => $matches,
            'reason' => $matches ? 'fingerprints_match' : 'fingerprint_mismatch_runbook_vs_run',
            'diff_fields' => $diffFields,
            'expected' => $expected['value'] ?? null,
            'actual' => $actual['value'] ?? null,
            'reconciliation_hint' => $matches
                ? null
                : 'Re-run runbook with the same flags as the dispatched run, or align the run flags with the reviewed runbook.',
        ];
    }

    /**
     * @param  array<string,mixed>  $intent
     * @return array<string,mixed>
     */
    private function normalizeComponents(array $intent): array
    {
        $workspace = AiValueNormalizer::trimmedStringOrNull($intent['atlas_workspace'] ?? null);
        $baselineWorkspace = AiValueNormalizer::trimmedStringOrNull($intent['baseline_workspace'] ?? null);
        $caseIds = (array) ($intent['case_ids'] ?? []);
        $caseIdsNormalized = collect($caseIds)
            ->filter(fn (mixed $v): bool => is_string($v) && trim($v) !== '')
            ->map(static fn (string $v): string => trim($v))
            ->unique()
            ->sort()
            ->values()
            ->all();

        return [
            'suite_id' => $this->normalize($intent['suite_id'] ?? null, AtlasForgeNativeRivalsProtocolService::DEFAULT_SUITE_ID),
            'preset' => $this->normalize($intent['preset'] ?? null, 'unspecified'),
            'atlas_model' => $this->normalize($intent['atlas_model'] ?? null, 'opus'),
            'baseline_model' => $this->normalize($intent['baseline_model'] ?? null, $this->normalize($intent['atlas_model'] ?? null, 'opus')),
            'atlas_workspace_hash' => $workspace === null ? 'unspecified' : hash('sha256', $workspace),
            'baseline_workspace_hash' => $baselineWorkspace === null ? 'unspecified' : hash('sha256', $baselineWorkspace),
            'case_ids' => $caseIdsNormalized === [] ? ['default'] : $caseIdsNormalized,
            'gate_profile' => $this->normalize($intent['gate_profile'] ?? null, 'strict'),
            'test_command' => $this->normalize($intent['test_command'] ?? null, 'preset_default'),
        ];
    }

    private function normalize(mixed $value, string $fallback): string
    {
        $trimmed = is_string($value) ? trim($value) : '';

        return $trimmed === '' ? $fallback : $trimmed;
    }

}
