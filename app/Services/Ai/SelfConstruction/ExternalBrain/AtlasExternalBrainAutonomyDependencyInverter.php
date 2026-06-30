<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure inverter. Finds places where the external brain still relies on human,
 * operator, Claude/Codex, or external-provider behavior in STEADY STATE and
 * proposes Atlas-native replacement task families with evidence floors.
 *
 * INVARIANT (AC2): a dependency marked is_bootstrap_only=true AND
 * atlas_native_path_proven=true is NOT flagged as a steady-state blocker — it
 * is a bootstrapping seam that has already been superseded natively. These are
 * collected but excluded from the inversions list.
 *
 * Input per dependency:
 *   stage, dependency_type (human|operator|claude_codex|external_provider),
 *   owner, description, is_bootstrap_only, atlas_native_path_proven
 *
 * Output per non-native dependency:
 *   stage, dependency_type, severity, atlas_native_replacement,
 *   proposed_task_family, owner, evidence_floor
 *
 * Severity:
 *   human            → critical   (zero autonomy without human)
 *   operator         → high       (requires operator attention)
 *   claude_codex     → medium     (costly provider in steady state)
 *   external_provider → medium    (external dependency not under Atlas control)
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainAutonomyDependencyInverter
{
    public const SCHEMA = 'atlas.external_brain.autonomy_dependency_inverter.v1';

    public const DEP_HUMAN             = 'human';
    public const DEP_OPERATOR          = 'operator';
    public const DEP_CLAUDE_CODEX      = 'claude_codex';
    public const DEP_EXTERNAL_PROVIDER = 'external_provider';

    public const SEV_CRITICAL = 'critical';
    public const SEV_HIGH     = 'high';
    public const SEV_MEDIUM   = 'medium';
    public const SEV_LOW      = 'low';

    private const SEVERITY_MAP = [
        self::DEP_HUMAN             => self::SEV_CRITICAL,
        self::DEP_OPERATOR          => self::SEV_HIGH,
        self::DEP_CLAUDE_CODEX      => self::SEV_MEDIUM,
        self::DEP_EXTERNAL_PROVIDER => self::SEV_MEDIUM,
    ];

    // Replacement strategies per dependency type.
    private const REPLACEMENT_TEMPLATES = [
        self::DEP_HUMAN             => 'atlas_native_autonomous_decision_organ_for_%stage%',
        self::DEP_OPERATOR          => 'atlas_native_operator_free_%stage%_governor',
        self::DEP_CLAUDE_CODEX      => 'atlas_native_minimax_or_local_llm_adapter_for_%stage%',
        self::DEP_EXTERNAL_PROVIDER => 'atlas_native_%stage%_capability_organ',
    ];

    // Proposed task family names per dependency type.
    private const TASK_FAMILY_TEMPLATES = [
        self::DEP_HUMAN             => 'autonomy:remove_human_gate_from_%stage%',
        self::DEP_OPERATOR          => 'autonomy:operator_free_%stage%',
        self::DEP_CLAUDE_CODEX      => 'provider:replace_claude_codex_%stage%_with_atlas_native',
        self::DEP_EXTERNAL_PROVIDER => 'provider:replace_external_%stage%_dependency',
    ];

    /**
     * @param  array{dependencies?: list<array<string,mixed>>}  $input
     * @return array{
     *   schema:string,
     *   inversions:list<array<string,mixed>>,
     *   skipped_bootstrap_only:int,
     *   inversion_count:int,
     * }
     */
    public function invert(array $input): array
    {
        $dependencies     = is_array($input['dependencies'] ?? null) ? $input['dependencies'] : [];
        $inversions       = [];
        $skippedBootstrap = 0;

        foreach ($dependencies as $dep) {
            $stage       = (string) ($dep['stage']           ?? 'unknown');
            $depType     = (string) ($dep['dependency_type'] ?? '');
            $owner       = (string) ($dep['owner']           ?? '');
            $description = (string) ($dep['description']     ?? '');
            $isBootstrap = (bool)   ($dep['is_bootstrap_only']        ?? false);
            $nativeProven = (bool)  ($dep['atlas_native_path_proven'] ?? false);

            // Native dependency — not an external blocker.
            if (! in_array($depType, [self::DEP_HUMAN, self::DEP_OPERATOR, self::DEP_CLAUDE_CODEX, self::DEP_EXTERNAL_PROVIDER], true)) {
                continue;
            }

            // AC2: bootstrap-only with a proven Atlas-native path → skip.
            if ($isBootstrap && $nativeProven) {
                $skippedBootstrap++;

                continue;
            }

            $severity    = self::SEVERITY_MAP[$depType] ?? self::SEV_LOW;
            $replacement = str_replace('%stage%', $stage, self::REPLACEMENT_TEMPLATES[$depType] ?? 'atlas_native_%stage%_organ');
            $taskFamily  = str_replace('%stage%', $stage, self::TASK_FAMILY_TEMPLATES[$depType] ?? 'autonomy:replace_%stage%');

            $inversions[] = [
                'stage'                    => $stage,
                'dependency_type'          => $depType,
                'owner'                    => $owner,
                'severity'                 => $severity,
                'atlas_native_replacement' => $replacement,
                'proposed_task_family'     => $taskFamily,
                'evidence_floor'           => $this->evidenceFloor($depType, $stage),
                'description'              => $description,
                'is_bootstrap_only'        => $isBootstrap,
            ];
        }

        // Sort: critical → high → medium → low, then stage asc.
        $severityOrder = [self::SEV_CRITICAL => 0, self::SEV_HIGH => 1, self::SEV_MEDIUM => 2, self::SEV_LOW => 3];
        usort($inversions, static function (array $a, array $b) use ($severityOrder): int {
            $so = ($severityOrder[$a['severity']] ?? 4) <=> ($severityOrder[$b['severity']] ?? 4);

            return $so !== 0 ? $so : strcmp($a['stage'], $b['stage']);
        });

        return [
            'schema'                 => self::SCHEMA,
            'inversions'             => $inversions,
            'skipped_bootstrap_only' => $skippedBootstrap,
            'inversion_count'        => count($inversions),
        ];
    }

    private function evidenceFloor(string $depType, string $stage): string
    {
        return match ($depType) {
            self::DEP_HUMAN             => 'atlas_native_'.$stage.'_runs_24h_without_human_input',
            self::DEP_OPERATOR          => 'atlas_native_'.$stage.'_approved_without_operator_prompt',
            self::DEP_CLAUDE_CODEX      => 'atlas_native_'.$stage.'_delivers_equivalent_output_to_codex',
            self::DEP_EXTERNAL_PROVIDER => 'atlas_native_'.$stage.'_replicates_external_provider_output',
            default                     => 'atlas_native_'.$stage.'_proven_end_to_end',
        };
    }
}
