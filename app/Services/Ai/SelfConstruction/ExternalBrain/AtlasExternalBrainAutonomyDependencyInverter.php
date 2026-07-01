<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure inverter. Finds places where the external brain still relies on human,
 * operator, Claude/Codex, or external-provider behavior in STEADY STATE and
 * proposes Atlas-native replacement task families with evidence floors.
 *
 * DEPENDENCY CLASSIFICATIONS:
 *   steady_state_blocker      — human/operator/claude_codex/external_provider, active in steady state
 *   bootstrap_only_superseded — bootstrap-only AND atlas_native_path_proven → SKIPPED
 *   optional_accelerator      — dependency_type = 'optional_accelerator' (low-severity, nice-to-have)
 *   atlas_native              — dependency_type = 'atlas_native' → SKIPPED (already native)
 *
 * INVARIANT: is_bootstrap_only=true AND atlas_native_path_proven=true → excluded from inversions.
 *
 * INVERSION FIELDS per entry:
 *   stage, dependency_type, owner, severity, atlas_native_replacement, proposed_task_family,
 *   evidence_floor, description, is_bootstrap_only, classification, removal_order, autonomy_gain_score
 *
 * SEVERITY / REMOVAL ORDER / AUTONOMY GAIN:
 *   human             → critical  (removal_order=1, autonomy_gain=1.0)
 *   operator          → high      (removal_order=2, autonomy_gain=0.8)
 *   claude_codex      → medium    (removal_order=3, autonomy_gain=0.5)
 *   external_provider → medium    (removal_order=3, autonomy_gain=0.5)
 *   optional_accelerator → low   (removal_order=4, autonomy_gain=0.2)
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainAutonomyDependencyInverter
{
    public const SCHEMA = 'atlas.external_brain.autonomy_dependency_inverter.v1';

    public const DEP_HUMAN                = 'human';
    public const DEP_OPERATOR             = 'operator';
    public const DEP_CLAUDE_CODEX         = 'claude_codex';
    public const DEP_EXTERNAL_PROVIDER    = 'external_provider';
    public const DEP_OPTIONAL_ACCELERATOR = 'optional_accelerator';
    public const DEP_ATLAS_NATIVE         = 'atlas_native';
    public const DEP_MUSCLE               = 'muscle';
    public const DEP_MANUAL_WORKFLOW      = 'manual_workflow';

    public const SEV_CRITICAL = 'critical';
    public const SEV_HIGH     = 'high';
    public const SEV_MEDIUM   = 'medium';
    public const SEV_LOW      = 'low';

    public const REMOVABILITY_REMOVABLE              = 'removable';
    public const REMOVABILITY_FALLBACK_REQUIRED       = 'fallback_required';
    public const REMOVABILITY_CERTIFICATION_REQUIRED  = 'certification_required';
    public const REMOVABILITY_UNAVOIDABLE_EXCEPTION   = 'unavoidable_exception';

    private const KNOWN_EXTERNAL_DEP_TYPES = [
        self::DEP_HUMAN,
        self::DEP_OPERATOR,
        self::DEP_CLAUDE_CODEX,
        self::DEP_EXTERNAL_PROVIDER,
        self::DEP_OPTIONAL_ACCELERATOR,
        self::DEP_MUSCLE,
        self::DEP_MANUAL_WORKFLOW,
    ];

    private const SEVERITY_MAP = [
        self::DEP_HUMAN                => self::SEV_CRITICAL,
        self::DEP_MANUAL_WORKFLOW      => self::SEV_CRITICAL,
        self::DEP_OPERATOR             => self::SEV_HIGH,
        self::DEP_MUSCLE               => self::SEV_HIGH,
        self::DEP_CLAUDE_CODEX         => self::SEV_MEDIUM,
        self::DEP_EXTERNAL_PROVIDER    => self::SEV_MEDIUM,
        self::DEP_OPTIONAL_ACCELERATOR => self::SEV_LOW,
    ];

    private const REMOVAL_ORDER_MAP = [
        self::SEV_CRITICAL => 1,
        self::SEV_HIGH     => 2,
        self::SEV_MEDIUM   => 3,
        self::SEV_LOW      => 4,
    ];

    private const AUTONOMY_GAIN_SCORE = [
        self::DEP_HUMAN                => 1.0,
        self::DEP_MANUAL_WORKFLOW      => 0.9,
        self::DEP_OPERATOR             => 0.8,
        self::DEP_MUSCLE               => 0.7,
        self::DEP_CLAUDE_CODEX         => 0.5,
        self::DEP_EXTERNAL_PROVIDER    => 0.5,
        self::DEP_OPTIONAL_ACCELERATOR => 0.2,
    ];

    private const REPLACEMENT_TEMPLATES = [
        self::DEP_HUMAN                => 'atlas_native_autonomous_decision_organ_for_%stage%',
        self::DEP_MANUAL_WORKFLOW      => 'atlas_native_automated_workflow_for_%stage%',
        self::DEP_OPERATOR             => 'atlas_native_operator_free_%stage%_governor',
        self::DEP_MUSCLE               => 'atlas_native_muscle_worker_for_%stage%',
        self::DEP_CLAUDE_CODEX         => 'atlas_native_minimax_or_local_llm_adapter_for_%stage%',
        self::DEP_EXTERNAL_PROVIDER    => 'atlas_native_%stage%_capability_organ',
        self::DEP_OPTIONAL_ACCELERATOR => 'atlas_native_optional_%stage%_organ',
    ];

    private const TASK_FAMILY_TEMPLATES = [
        self::DEP_HUMAN                => 'autonomy:remove_human_gate_from_%stage%',
        self::DEP_MANUAL_WORKFLOW      => 'autonomy:automate_manual_workflow_%stage%',
        self::DEP_OPERATOR             => 'autonomy:operator_free_%stage%',
        self::DEP_MUSCLE               => 'autonomy:replace_external_muscle_%stage%_with_atlas_native',
        self::DEP_CLAUDE_CODEX         => 'provider:replace_claude_codex_%stage%_with_atlas_native',
        self::DEP_EXTERNAL_PROVIDER    => 'provider:replace_external_%stage%_dependency',
        self::DEP_OPTIONAL_ACCELERATOR => 'autonomy:internalize_optional_%stage%_accelerator',
    ];

    private const FEASIBILITY_SCORE = [
        self::REMOVABILITY_REMOVABLE             => 3,
        self::REMOVABILITY_FALLBACK_REQUIRED     => 2,
        self::REMOVABILITY_CERTIFICATION_REQUIRED => 1,
        self::REMOVABILITY_UNAVOIDABLE_EXCEPTION => 0,
    ];

    private const RISK_LEVEL = [
        self::REMOVABILITY_REMOVABLE             => 'low',
        self::REMOVABILITY_FALLBACK_REQUIRED     => 'medium',
        self::REMOVABILITY_CERTIFICATION_REQUIRED => 'medium',
        self::REMOVABILITY_UNAVOIDABLE_EXCEPTION => 'high',
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
        $supersededAccelerators = [];

        foreach ($dependencies as $dep) {
            $stage        = (string) ($dep['stage']                    ?? 'unknown');
            $depType      = (string) ($dep['dependency_type']          ?? '');
            $owner        = (string) ($dep['owner']                    ?? '');
            $description  = (string) ($dep['description']              ?? '');
            $isBootstrap  = (bool)   ($dep['is_bootstrap_only']        ?? false);
            $nativeProven = (bool)   ($dep['atlas_native_path_proven'] ?? false);
            $removableNow = (bool)   ($dep['removable_now']            ?? false);
            $fallbackAvailable      = (bool) ($dep['fallback_available']            ?? false);
            $certificationAvailable = (bool) ($dep['certification_path_available']  ?? false);

            // atlas_native: already native, nothing to invert.
            if ($depType === self::DEP_ATLAS_NATIVE) {
                continue;
            }

            // Unknown external type: skip.
            if (! in_array($depType, self::KNOWN_EXTERNAL_DEP_TYPES, true)) {
                continue;
            }

            // bootstrap_only_superseded: seam already proven native → skip, but still report it.
            if ($isBootstrap && $nativeProven) {
                $skippedBootstrap++;
                $supersededAccelerators[] = [
                    'stage'                    => $stage,
                    'dependency_type'          => $depType,
                    'owner'                    => $owner,
                    'description'              => $description,
                    'atlas_native_replacement' => str_replace('%stage%', $stage, self::REPLACEMENT_TEMPLATES[$depType] ?? 'atlas_native_%stage%_organ'),
                ];
                continue;
            }

            $severity       = self::SEVERITY_MAP[$depType]       ?? self::SEV_LOW;
            $removalOrder   = self::REMOVAL_ORDER_MAP[$severity]  ?? 4;
            $gainScore      = self::AUTONOMY_GAIN_SCORE[$depType] ?? 0.0;
            $replacement    = str_replace('%stage%', $stage, self::REPLACEMENT_TEMPLATES[$depType] ?? 'atlas_native_%stage%_organ');
            $taskFamily     = str_replace('%stage%', $stage, self::TASK_FAMILY_TEMPLATES[$depType] ?? 'autonomy:replace_%stage%');
            $classification = $depType === self::DEP_OPTIONAL_ACCELERATOR
                ? 'optional_accelerator'
                : 'steady_state_blocker';

            // AC2/AC3: removability is never asserted blind — a dependency is only "removable"
            // when the caller explicitly proved it (removable_now). Absent fallback AND
            // certification evidence, it is refused as unavoidable_exception rather than
            // silently labeled autonomous.
            $removabilityClassification = match (true) {
                $removableNow            => self::REMOVABILITY_REMOVABLE,
                $fallbackAvailable       => self::REMOVABILITY_FALLBACK_REQUIRED,
                $certificationAvailable  => self::REMOVABILITY_CERTIFICATION_REQUIRED,
                default                  => self::REMOVABILITY_UNAVOIDABLE_EXCEPTION,
            };

            $feasibilityScore = self::FEASIBILITY_SCORE[$removabilityClassification] ?? 0;
            $riskLevel        = self::RISK_LEVEL[$removabilityClassification] ?? 'high';
            $proofPath        = [$this->evidenceFloor($depType, $stage)];
            // AC2 composite ranking: autonomy lift dominates (x10 so it always outranks the
            // 0-3 feasibility spread), feasibility breaks ties among equally-impactful
            // dependencies. Never asserts a dependency is easy to remove merely because it is
            // impactful — feasibility is a strictly additive tie-breaker, not an override.
            $rankingScore = round($gainScore * 10 + $feasibilityScore, 4);

            // AC3: any dependency not yet classified removable is preserved only as a temporary
            // acceleration surface — it must carry an explicit sunset criterion, never become
            // silent permanent architecture.
            $sunsetCriteria = $removabilityClassification === self::REMOVABILITY_REMOVABLE
                ? 'already_removable_no_sunset_wait_required'
                : 'sunset_when: '.$this->evidenceFloor($depType, $stage);

            $inversions[] = [
                'stage'                        => $stage,
                'dependency_type'              => $depType,
                'owner'                        => $owner,
                'severity'                     => $severity,
                'atlas_native_replacement'     => $replacement,
                'proposed_task_family'         => $taskFamily,
                'task_hint'                    => $taskFamily,
                'evidence_floor'               => $this->evidenceFloor($depType, $stage),
                'description'                  => $description,
                'is_bootstrap_only'            => $isBootstrap,
                'classification'               => $classification,
                'removability_classification'  => $removabilityClassification,
                'removal_order'                => $removalOrder,
                'autonomy_gain_score'          => $gainScore,
                // AC1 vocabulary: ordered replacement chain (single-stage today, ordered for
                // future multi-stage templates), removal readiness, missing evidence floors,
                // and the first concrete task that starts removing this dependency.
                'native_replacement_chain'        => [$replacement],
                'removal_readiness'               => $removabilityClassification,
                'missing_evidence_floors'         => [$this->evidenceFloor($depType, $stage)],
                'first_task_to_remove_dependency' => $taskFamily,
                // AC2: ranking dimensions — autonomy lift, feasibility, risk, proof path.
                'feasibility_score'  => $feasibilityScore,
                'risk_level'         => $riskLevel,
                'proof_path'         => $proofPath,
                'ranking_score'      => $rankingScore,
                // AC3: external tools are never permanent architecture.
                'temporary_acceleration_surface' => $removabilityClassification !== self::REMOVABILITY_REMOVABLE,
                'sunset_criteria'                => $sunsetCriteria,
            ];
        }

        // Rank by composite score (autonomy lift + feasibility) descending, then stage asc.
        usort($inversions, static function (array $a, array $b): int {
            $so = $b['ranking_score'] <=> $a['ranking_score'];

            return $so !== 0 ? $so : strcmp($a['stage'], $b['stage']);
        });

        return [
            'schema'                  => self::SCHEMA,
            'inversions'              => $inversions,
            'skipped_bootstrap_only'  => $skippedBootstrap,
            'inversion_count'         => count($inversions),
            'superseded_accelerators' => $supersededAccelerators,
        ];
    }

    private function evidenceFloor(string $depType, string $stage): string
    {
        return match ($depType) {
            self::DEP_HUMAN                => 'atlas_native_'.$stage.'_runs_24h_without_human_input',
            self::DEP_MANUAL_WORKFLOW      => 'atlas_native_'.$stage.'_completes_without_manual_workflow_step',
            self::DEP_OPERATOR             => 'atlas_native_'.$stage.'_approved_without_operator_prompt',
            self::DEP_MUSCLE               => 'atlas_native_'.$stage.'_delivers_without_external_muscle_worker',
            self::DEP_CLAUDE_CODEX         => 'atlas_native_'.$stage.'_delivers_equivalent_output_to_codex',
            self::DEP_EXTERNAL_PROVIDER    => 'atlas_native_'.$stage.'_replicates_external_provider_output',
            self::DEP_OPTIONAL_ACCELERATOR => 'atlas_native_'.$stage.'_runs_without_optional_accelerator',
            default                        => 'atlas_native_'.$stage.'_proven_end_to_end',
        };
    }
}
