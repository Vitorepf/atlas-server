<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure autonomy regression sentinel. Detects when a proposed brain evolution
 * reintroduces human/operator/provider dependency, prompt-only memory, or
 * external-tool-only execution into the steady-state design.
 *
 * Regression types detected:
 *   human_dependency          — steady state requires human approval or is human-owned
 *   operator_seeding_required — steady state requires operator to seed work
 *   provider_dependency       — always requires external provider in steady state
 *   prompt_only_memory        — memory lives only in prompts (no Atlas-native store)
 *   external_tool_only        — execution only via external tools
 *   ungated_bootstrap_muscle  — bootstrap used but not atlas_native-owned + not evidence-gated
 *
 * AC2: bootstrap_muscle_used is ALLOWED when:
 *   - final_runtime_owner = 'atlas_native'
 *   - evidence_gated = true
 *   If those conditions are not both met, the muscle counts as a provider_dependency.
 *
 * Severity:
 *   none     — no regressions
 *   warning  — regressions present but all are soft (operator_seeding only)
 *   blocking — any hard regression type
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainAutonomyRegressionSentinel
{
    public const SCHEMA = 'atlas.external_brain.autonomy_regression_sentinel.v1';

    public const VERDICT_PASS = 'pass';
    public const VERDICT_FAIL = 'fail';

    public const SEVERITY_NONE     = 'none';
    public const SEVERITY_WARNING  = 'warning';
    public const SEVERITY_BLOCKING = 'blocking';

    // Soft regressions produce warnings; hard regressions block.
    private const SOFT_REGRESSION_TYPES = ['operator_seeding_required'];

    /**
     * @param  array{
     *   requires_human_approval?: bool,
     *   requires_operator_seeding?: bool,
     *   requires_external_provider_always?: bool,
     *   prompt_only_memory?: bool,
     *   external_tool_only_execution?: bool,
     *   bootstrap_muscle_used?: bool,
     *   final_runtime_owner?: string,
     *   evidence_gated?: bool,
     * }  $input
     * @return array{schema:string, is_regression:bool, regression_types:list<string>, severity:string, allowed_bootstrap:bool, sentinel_verdict:string}
     */
    public function scan(array $input): array
    {
        $humanApproval       = (bool) ($input['requires_human_approval']          ?? false);
        $operatorSeeding     = (bool) ($input['requires_operator_seeding']         ?? false);
        $externalAlways      = (bool) ($input['requires_external_provider_always'] ?? false);
        $promptOnlyMemory    = (bool) ($input['prompt_only_memory']                ?? false);
        $externalToolOnly    = (bool) ($input['external_tool_only_execution']      ?? false);
        $bootstrapUsed       = (bool) ($input['bootstrap_muscle_used']             ?? false);
        $finalOwner          = (string) ($input['final_runtime_owner']             ?? 'atlas_native');
        $evidenceGated       = (bool) ($input['evidence_gated']                    ?? false);

        $regressions = [];

        if ($humanApproval || $finalOwner === 'human') {
            $regressions[] = 'human_dependency';
        }

        if ($operatorSeeding) {
            $regressions[] = 'operator_seeding_required';
        }

        // Provider dependency: always-required external provider in steady state.
        if ($externalAlways) {
            $regressions[] = 'provider_dependency';
        }

        // Ungated bootstrap muscle: bootstrap used but not properly constrained.
        // Allowed only when atlas_native is the final owner AND evidence-gated.
        $allowedBootstrap = $bootstrapUsed && $finalOwner === 'atlas_native' && $evidenceGated;

        if ($bootstrapUsed && ! $allowedBootstrap) {
            $regressions[] = 'ungated_bootstrap_muscle';
        }

        if ($promptOnlyMemory) {
            $regressions[] = 'prompt_only_memory';
        }

        if ($externalToolOnly) {
            $regressions[] = 'external_tool_only';
        }

        $isRegression = $regressions !== [];
        $severity     = $this->severity($regressions);
        $verdict      = ($severity === self::SEVERITY_BLOCKING)
            ? self::VERDICT_FAIL
            : self::VERDICT_PASS;

        return [
            'schema'           => self::SCHEMA,
            'is_regression'    => $isRegression,
            'regression_types' => $regressions,
            'severity'         => $severity,
            'allowed_bootstrap' => $allowedBootstrap,
            'sentinel_verdict' => $verdict,
        ];
    }

    private function severity(array $regressions): string
    {
        if ($regressions === []) {
            return self::SEVERITY_NONE;
        }

        foreach ($regressions as $type) {
            if (! in_array($type, self::SOFT_REGRESSION_TYPES, true)) {
                return self::SEVERITY_BLOCKING;
            }
        }

        return self::SEVERITY_WARNING;
    }
}
