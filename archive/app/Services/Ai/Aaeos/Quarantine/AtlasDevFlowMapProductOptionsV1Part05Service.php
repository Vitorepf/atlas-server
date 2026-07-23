<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas Dev Flow Map And Product Options v1 · Parte 5 — pure, deterministic
 * decider for the "Conteudo Extraido" slice (§Open Brain E Contexto through
 * §Casos De Uso · Review).
 *
 * This service does NOT execute anything and touches no I/O. The richer runtime
 * that this recorte *describes* lives elsewhere and is intentionally NOT
 * duplicated here:
 *   - App\Services\Ai\Programming\AtlasProgrammingOrchestrator (sessionPlan / executor)
 *   - App\Services\AtlasCode\DevToForgePromotionService + PromotionSignalDetector
 *   - App\Services\Ai\Kernel\Pipeline\KernelPipelineDevPlanBuilder
 *
 * Here we encode the doc-as-law contract of this split-doc as a closed set of
 * typed decisions so an agent (or any caller) can ask, without re-reading prose:
 *   - which executor does §Programming Orchestrator prescribe for a given
 *     programming profile / flow / repair+harness need?
 *   - is the dev-quality-gate attached, and what is its required_final_status,
 *     per §Quality Gate E Repair?
 *   - what is the normalized repair contract (cap, stop conditions, evidence),
 *     and the debug-specific max_iterations=3, per §Repair / §Casos De Uso · Debug?
 *   - which Dev->Forge promotion target does §Dev -> Forge Promotion prescribe,
 *     honouring the `thin_small_bug` veto and the "never auto-create Obra" rule?
 *   - which canonical flow + posture does each §Casos De Uso map to?
 *
 * Every threshold below is traceable one-to-one to the doc; the class is the
 * machine-readable contract for this recorte, not a second engine.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1-part-05.md
 */
class AtlasDevFlowMapProductOptionsV1Part05Service
{
    // §Programming Orchestrator — executors.
    public const EXECUTOR_SIMPLE = 'simple_provider_execution';

    public const EXECUTOR_DEV_REPAIR = 'dev_repair_executor';

    public const EXECUTOR_ENGINEERING_HARNESS = 'engineering_harness';

    // §Programming Orchestrator — programming profile.
    public const PROFILE_DEV = 'dev';

    public const PROFILE_FORGE = 'forge';

    // §Dev -> Forge Promotion — targets.
    public const TARGET_NONE = 'none';

    public const TARGET_QUICK_INTERVENTION = 'quick_intervention';

    public const TARGET_OBRA_CANDIDATE = 'obra_candidate';

    public const TARGET_FORGE_OBRA = 'forge_obra';

    // §Repair / §Casos De Uso · Debug — repair iteration cap for the debug case.
    public const DEBUG_MAX_ITERATIONS = 3;

    /**
     * §Programming Orchestrator — executor selection.
     *
     * Rule (doc table):
     *   simple_provider_execution -> "fluxo leve sem repair/harness";
     *   dev_repair_executor       -> "Dev completo com repair/quality loop";
     *   engineering_harness       -> "Forge ou intencao/risco que exige harness".
     *
     * Precedence: harness need (forge profile OR explicit harness requirement)
     * outranks repair, which outranks the simple light path.
     *
     * @return array{
     *     programming_profile: string,
     *     executor_decision: string,
     *     requires_harness: bool,
     *     has_repair_or_quality_loop: bool,
     *     reason: string
     * }
     */
    public function decideExecutor(
        string $programmingProfile,
        bool $requiresHarness = false,
        bool $hasRepairOrQualityLoop = false
    ): array {
        $profile = $programmingProfile === self::PROFILE_FORGE
            ? self::PROFILE_FORGE
            : self::PROFILE_DEV;

        if ($profile === self::PROFILE_FORGE || $requiresHarness) {
            return [
                'programming_profile' => $profile,
                'executor_decision' => self::EXECUTOR_ENGINEERING_HARNESS,
                'requires_harness' => true,
                'has_repair_or_quality_loop' => $hasRepairOrQualityLoop,
                'reason' => 'Forge or intent/risk that requires the harness.',
            ];
        }

        if ($hasRepairOrQualityLoop) {
            return [
                'programming_profile' => $profile,
                'executor_decision' => self::EXECUTOR_DEV_REPAIR,
                'requires_harness' => false,
                'has_repair_or_quality_loop' => true,
                'reason' => 'Full Dev with repair/quality loop.',
            ];
        }

        return [
            'programming_profile' => $profile,
            'executor_decision' => self::EXECUTOR_SIMPLE,
            'requires_harness' => false,
            'has_repair_or_quality_loop' => false,
            'reason' => 'Light flow without repair/harness.',
        ];
    }

    /**
     * §Quality Gate E Repair · Quality gate.
     *
     * The dev-quality-gate is attached when:
     *   - complete mode is active; OR
     *   - max_iterations > 1.
     *
     * Policy (when attached):
     *   required_final_status = passed     in complete/fair mode;
     *   required_final_status = not_failed in light/single-shot mode.
     *
     * @return array{
     *     quality_gate_attached: bool,
     *     procedure: ?string,
     *     required_final_status: ?string,
     *     reason: string
     * }
     */
    public function decideQualityGate(
        bool $completeMode,
        int $maxIterations = 1,
        bool $fairMode = false
    ): array {
        $attached = $completeMode || $maxIterations > 1;

        if (! $attached) {
            return [
                'quality_gate_attached' => false,
                'procedure' => null,
                'required_final_status' => null,
                'reason' => 'Neither complete mode nor max_iterations > 1.',
            ];
        }

        $requiredFinalStatus = ($completeMode || $fairMode) ? 'passed' : 'not_failed';

        return [
            'quality_gate_attached' => true,
            'procedure' => 'plan_validate_execute',
            'required_final_status' => $requiredFinalStatus,
            'reason' => $completeMode
                ? 'Complete mode active.'
                : 'max_iterations > 1.',
        ];
    }

    /**
     * §Quality Gate E Repair · Repair — normalized repair contract.
     *
     * Documented invariants:
     *   - max_iterations normalized (floored at 1; the debug case caps at 3);
     *   - stop when passed;
     *   - stop if quality worsens;
     *   - heavy repair requires evidence;
     *   - fallback NOT permitted inside the repair capsule;
     *   - Kernel repair decision is mandatory before enqueuing structural repair.
     *
     * @return array{
     *     max_iterations: int,
     *     stop_when_passed: bool,
     *     stop_if_quality_worsens: bool,
     *     fallback_allowed_in_capsule: bool,
     *     evidence_required: bool,
     *     kernel_repair_decision_required: bool
     * }
     */
    public function repairContract(
        int $requestedMaxIterations,
        bool $heavyRepair = false,
        bool $structuralRepair = false
    ): array {
        // Normalize: never below 1, never above the documented debug ceiling.
        $normalized = max(1, $requestedMaxIterations);
        if ($normalized > self::DEBUG_MAX_ITERATIONS) {
            $normalized = self::DEBUG_MAX_ITERATIONS;
        }

        return [
            'max_iterations' => $normalized,
            'stop_when_passed' => true,
            'stop_if_quality_worsens' => true,
            'fallback_allowed_in_capsule' => false,
            'evidence_required' => $heavyRepair,
            'kernel_repair_decision_required' => $structuralRepair,
        ];
    }

    /**
     * §Dev -> Forge Promotion — promotion target.
     *
     * Targets:
     *   none              -> continue in Atlas Dev;
     *   quick_intervention-> small, clear, reversible;
     *   obra_candidate    -> needs discovery/human decision;
     *   forge_obra        -> heavy/governed work.
     *
     * Hard rules enforced here:
     *   - `thin_small_bug` veto: a short chat with <= 1 file and NO risk forces
     *     `none` regardless of other signals;
     *   - the service NEVER auto-creates an Obra: this is a read-only PREVIEW,
     *     so `auto_create_obra` is always false and humans decide.
     *
     * @param array{
     *     message_density?: int,
     *     file_count?: int,
     *     subsystem_count?: int,
     *     architecture_or_refactor?: bool,
     *     risk_or_production?: bool,
     *     recurrent_failure?: bool,
     *     operator_requested?: bool
     * } $signals
     * @return array{
     *     target: string,
     *     auto_create_obra: bool,
     *     preview_only: bool,
     *     thin_small_bug_veto: bool,
     *     reason: string
     * }
     */
    public function decidePromotionTarget(array $signals): array
    {
        $fileCount = (int) ($signals['file_count'] ?? 0);
        $subsystemCount = (int) ($signals['subsystem_count'] ?? 0);
        $messageDensity = (int) ($signals['message_density'] ?? 0);
        $architecture = (bool) ($signals['architecture_or_refactor'] ?? false);
        $risk = (bool) ($signals['risk_or_production'] ?? false);
        $recurrentFailure = (bool) ($signals['recurrent_failure'] ?? false);
        $operatorRequested = (bool) ($signals['operator_requested'] ?? false);

        // §Dev -> Forge Promotion — `thin_small_bug` veto: short chat, <= 1 file,
        // no risk. Highest precedence; pins the target to `none`.
        $thinSmallBug = $fileCount <= 1 && ! $risk && ! $architecture && ! $recurrentFailure;
        if ($thinSmallBug && ! $operatorRequested) {
            return $this->promotion(
                self::TARGET_NONE,
                true,
                'thin_small_bug veto: <= 1 file, no risk — stay in Atlas Dev.'
            );
        }

        // forge_obra — heavy/governed work: real risk/production, or wide
        // multi-subsystem architecture/refactor.
        if (($risk && ($architecture || $subsystemCount >= 2))
            || ($architecture && $subsystemCount >= 2)) {
            return $this->promotion(
                self::TARGET_FORGE_OBRA,
                false,
                'Heavy/governed work: risk or wide multi-subsystem architecture.'
            );
        }

        // obra_candidate — needs structured discovery / human decision: an
        // architecture/refactor signal, a recurrent failure, or a dense/large
        // multi-subsystem context that is not yet a clear quick fix.
        if ($architecture || $recurrentFailure || $subsystemCount >= 2 || $messageDensity >= 20) {
            return $this->promotion(
                self::TARGET_OBRA_CANDIDATE,
                false,
                'Needs structured discovery / human decision before becoming an Obra.'
            );
        }

        // quick_intervention — small, clear, reversible single-subsystem change
        // that nonetheless crossed the thin-bug veto (e.g. >1 file or explicit
        // operator request) but carries no governance weight.
        return $this->promotion(
            self::TARGET_QUICK_INTERVENTION,
            false,
            'Small, clear, reversible change — quick intervention.'
        );
    }

    /**
     * §Casos De Uso — map a use case to its ideal canonical flow + posture.
     *
     * technical_question -> programming.dev,    read-only, Open Brain auto, no patch,
     *                       quality gate NOT mandatory;
     * small_bug          -> programming.dev,    small patch, simple quality gate,
     *                       do not promote to Obra;
     * debug              -> programming.repair,  repair capsule, minimal patch,
     *                       max_iterations 3;
     * review             -> programming.review,  read-only preferred, no patch by
     *                       default, findings first.
     *
     * @return array{
     *     use_case: string,
     *     flow: string,
     *     posture: string,
     *     produces_patch: bool,
     *     quality_gate_mandatory: bool,
     *     max_iterations: ?int,
     *     promote_to_obra: bool
     * }
     */
    public function mapUseCaseFlow(string $useCase): array
    {
        return match ($useCase) {
            'technical_question' => [
                'use_case' => 'technical_question',
                'flow' => 'programming.dev',
                'posture' => 'read_context_only',
                'produces_patch' => false,
                'quality_gate_mandatory' => false,
                'max_iterations' => null,
                'promote_to_obra' => false,
            ],
            'small_bug' => [
                'use_case' => 'small_bug',
                'flow' => 'programming.dev',
                'posture' => 'small_patch',
                'produces_patch' => true,
                'quality_gate_mandatory' => false,
                'max_iterations' => null,
                'promote_to_obra' => false,
            ],
            'debug' => [
                'use_case' => 'debug',
                'flow' => 'programming.repair',
                'posture' => 'repair_capsule',
                'produces_patch' => true,
                'quality_gate_mandatory' => true,
                'max_iterations' => self::DEBUG_MAX_ITERATIONS,
                'promote_to_obra' => false,
            ],
            'review' => [
                'use_case' => 'review',
                'flow' => 'programming.review',
                'posture' => 'read_only_findings_first',
                'produces_patch' => false,
                'quality_gate_mandatory' => false,
                'max_iterations' => null,
                'promote_to_obra' => false,
            ],
            default => [
                'use_case' => 'unknown',
                'flow' => 'programming.dev',
                'posture' => 'read_context_only',
                'produces_patch' => false,
                'quality_gate_mandatory' => false,
                'max_iterations' => null,
                'promote_to_obra' => false,
            ],
        };
    }

    /**
     * §Open Brain E Contexto — resolve the Open Brain injection mode from flags.
     *
     * normal mode          -> auto;
     * --forge              -> required;
     * --no-open-brain      -> off;
     * --require-open-brain -> fail-closed if no context.
     *
     * Precedence: an explicit off flag wins; otherwise forge/require raise the
     * floor to required; default is auto.
     *
     * @return array{
     *     mode: string,
     *     fail_closed: bool
     * }
     */
    public function resolveOpenBrainMode(
        bool $forge = false,
        bool $noOpenBrain = false,
        bool $requireOpenBrain = false
    ): array {
        if ($noOpenBrain) {
            return ['mode' => 'off', 'fail_closed' => false];
        }

        if ($forge || $requireOpenBrain) {
            return ['mode' => 'required', 'fail_closed' => $requireOpenBrain];
        }

        return ['mode' => 'auto', 'fail_closed' => false];
    }

    /**
     * Machine-readable manifest of this recorte's documented contract.
     *
     * @return array<string, mixed>
     */
    public function manifest(): array
    {
        return [
            'doc' => 'atlas-dev-flow-map-and-product-options-v1-part-05',
            'executors' => [
                self::EXECUTOR_SIMPLE,
                self::EXECUTOR_DEV_REPAIR,
                self::EXECUTOR_ENGINEERING_HARNESS,
            ],
            'promotion_targets' => [
                self::TARGET_NONE,
                self::TARGET_QUICK_INTERVENTION,
                self::TARGET_OBRA_CANDIDATE,
                self::TARGET_FORGE_OBRA,
            ],
            'debug_max_iterations' => self::DEBUG_MAX_ITERATIONS,
            'use_cases' => ['technical_question', 'small_bug', 'debug', 'review'],
            'open_brain_modes' => ['auto', 'required', 'off'],
            'preview_only_promotion' => true,
            'auto_create_obra' => false,
        ];
    }

    /**
     * @return array{
     *     target: string,
     *     auto_create_obra: bool,
     *     preview_only: bool,
     *     thin_small_bug_veto: bool,
     *     reason: string
     * }
     */
    private function promotion(string $target, bool $thinSmallBugVeto, string $reason): array
    {
        return [
            'target' => $target,
            // The service NEVER auto-creates an Obra; promotion is a preview and
            // humans decide via Attention/operator.
            'auto_create_obra' => false,
            'preview_only' => true,
            'thin_small_bug_veto' => $thinSmallBugVeto,
            'reason' => $reason,
        ];
    }
}
