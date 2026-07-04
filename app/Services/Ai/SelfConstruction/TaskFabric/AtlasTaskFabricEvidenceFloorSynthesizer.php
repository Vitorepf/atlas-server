<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskFabric;

/**
 * Pure synthesizer. Generates a minimal, strong acceptance + required_evidence contract
 * for a macro task given its capability goal and risk profile.
 *
 * Every contract includes:
 *   - Runnable proof criterion  (artisan test green, no regressions)
 *   - Capability delta claim    (goal stated as falsifiable outcome)
 *   - Falsifiable gate ref      (a concrete test/gate trace is required)
 *   - Base evidence floor       (tests_or_gates_result + implementation_notes)
 *
 * High-guard tasks (high-risk OR goal/family touches queue/verification/merge/gate/certif):
 *   - anti_false_green_receipt added to required_evidence
 *
 * High-risk tasks additionally:
 *   - anti_stale_timestamp_receipt added to required_evidence
 *
 * RISK-SCALED EVIDENCE FLOOR (task_family or goal text, additive on top of the base floor):
 *   bug fix         (family/goal mentions bug/fix/regression) → reproduction_evidence:
 *                     a green unit test alone never proves a bug is fixed without first
 *                     reproducing the failure.
 *   simplification  (family/goal mentions simplif/refactor/delet/consolidat/merge_organ) →
 *                     behavior_equivalence_or_deletion_safety_evidence: code removal/merge
 *                     must prove the old and new paths behave the same, or that the deleted
 *                     path was provably dead.
 *   autonomy/runtime (family/goal mentions autonom/runtime/loop/self_construction) →
 *                     liveness_or_decision_impact_evidence: a passing unit test does not
 *                     prove the autonomous loop actually ran or that its decision changed.
 *
 * NO process execution, NO filesystem, NO providers. DETERMINISTIC.
 */
final class AtlasTaskFabricEvidenceFloorSynthesizer
{
    public const SCHEMA = 'atlas.task_fabric.evidence_floor_synthesizer.v1';

    private const BASE_EVIDENCE = ['tests_or_gates_result', 'implementation_notes'];

    private const HIGH_GUARD_KEYWORDS = ['queue', 'verification', 'merge', 'gate', 'certif'];

    private const BUG_FIX_KEYWORDS = ['bug', 'fix', 'regression'];

    private const SIMPLIFICATION_KEYWORDS = ['simplif', 'refactor', 'delet', 'consolidat', 'merge_organ'];

    private const AUTONOMY_KEYWORDS = ['autonom', 'runtime', 'loop', 'self_construction'];

    /**
     * @param  array{risk_level?:string, task_family?:string}  $riskProfile
     * @return array<string,mixed>
     */
    public function synthesize(string $capabilityGoal, array $riskProfile = []): array
    {
        $riskLevel  = strtolower(trim((string) ($riskProfile['risk_level'] ?? 'low')));
        $taskFamily = strtolower(trim((string) ($riskProfile['task_family'] ?? '')));
        $highGuard  = $this->requiresHighGuard($riskLevel, $taskFamily, $capabilityGoal);

        $acceptance = [
            'Runnable proof: /opt/homebrew/bin/php artisan test passes green with no regressions.',
            'Capability delta: ' . rtrim($capabilityGoal, '.') . ' is delivered and verifiably observable.',
            'Falsifiable gate: at least one test or gate result references a specific class/line demonstrating the behavior.',
        ];

        $searchTarget = strtolower($capabilityGoal) . ' ' . $taskFamily;

        $evidence = self::BASE_EVIDENCE;
        if ($highGuard) {
            $evidence[] = 'anti_false_green_receipt';
        }
        if ($riskLevel === 'high') {
            $evidence[] = 'anti_stale_timestamp_receipt';
            $evidence[] = 'risk_reduction_delta';
        }
        if ($this->matchesAny($searchTarget, self::BUG_FIX_KEYWORDS)) {
            $evidence[] = 'reproduction_evidence';
        }
        $isSimplification = $this->matchesAny($searchTarget, self::SIMPLIFICATION_KEYWORDS);
        if ($isSimplification) {
            $evidence[] = 'behavior_equivalence_or_deletion_safety_evidence';
            $evidence[] = 'behavior_delta';
            $evidence[] = 'simplification_delta';
        }
        $isAutonomy = $this->matchesAny($searchTarget, self::AUTONOMY_KEYWORDS);
        if ($isAutonomy) {
            $evidence[] = 'liveness_or_decision_impact_evidence';
            $evidence[] = 'autonomy_delta';
        }

        // AC4: explain why tests_or_gates_result alone is insufficient for high-value claims.
        $insufficiencyReasons = [];
        if ($riskLevel === 'high' || $highGuard || $isSimplification || $isAutonomy) {
            $insufficiencyReasons[] = 'A green test run alone does not prove high-value claims:';
            if ($riskLevel === 'high') {
                $insufficiencyReasons[] = '  - risk_reduction_delta requires measurable before/after risk evidence beyond green tests';
            }
            if ($isSimplification) {
                $insufficiencyReasons[] = '  - behavior_delta/simplification_delta require behavior equivalence proof, not just test green';
            }
            if ($isAutonomy) {
                $insufficiencyReasons[] = '  - autonomy_delta requires liveness or decision-impact evidence, not just unit test pass';
            }
        }

        return [
            'schema_version'     => self::SCHEMA,
            'capability_goal'    => $capabilityGoal,
            'risk_level'         => $riskLevel,
            'high_guard_active'  => $highGuard,
            'acceptance_criteria' => $acceptance,
            'required_evidence'  => array_values(array_unique($evidence)),
            'tests_or_gates_insufficiency_explanation' => $insufficiencyReasons,
        ];
    }

    /**
     * Validates a SUBMITTED evidence bundle against the minimum proof floor: it must carry
     * BOTH tests_or_gates_result AND implementation_notes (implementation_notes alone is
     * narrative, not proof), and the runnable command must actually cover the task's
     * allowed_files — a command unrelated to the touched files proves nothing about them.
     *
     * @param  array<string,mixed>  $submission  { required_evidence?: list<string>,
     *   runnable_command?: string, allowed_files?: list<string> }
     * @return array{admitted:bool, blockers:list<string>}
     */
    public function admitEvidenceFloor(array $submission): array
    {
        $providedEvidence = array_values(array_map('strval', (array) ($submission['required_evidence'] ?? [])));
        $runnableCommand = strtolower(trim((string) ($submission['runnable_command'] ?? '')));
        $allowedFiles = array_values(array_map('strval', (array) ($submission['allowed_files'] ?? [])));

        $hasTestsOrGatesResult = in_array('tests_or_gates_result', $providedEvidence, true);
        $hasImplementationNotes = in_array('implementation_notes', $providedEvidence, true);

        $blockers = [];

        if (! $hasTestsOrGatesResult) {
            $blockers[] = 'missing_tests_or_gates_result';
            if ($providedEvidence !== [] && $hasImplementationNotes) {
                $blockers[] = 'implementation_notes_alone_is_not_proof';
            }
        }
        if (! $hasImplementationNotes) {
            $blockers[] = 'missing_implementation_notes';
        }

        if ($allowedFiles !== [] && $runnableCommand !== '') {
            $commandCoversScope = false;
            foreach ($allowedFiles as $file) {
                $needle = strtolower(basename($file, '.php'));
                if ($needle !== '' && str_contains($runnableCommand, $needle)) {
                    $commandCoversScope = true;

                    break;
                }
            }
            if (! $commandCoversScope) {
                $blockers[] = 'runnable_command_does_not_cover_allowed_files';
            }
        } elseif ($allowedFiles !== [] && $runnableCommand === '') {
            $blockers[] = 'runnable_command_does_not_cover_allowed_files';
        }

        return [
            'admitted' => array_values(array_unique($blockers)) === [],
            'blockers' => array_values(array_unique($blockers)),
        ];
    }

    private function requiresHighGuard(string $riskLevel, string $taskFamily, string $goal): bool
    {
        if ($riskLevel === 'high') {
            return true;
        }
        $searchTarget = strtolower($goal) . ' ' . $taskFamily;

        return $this->matchesAny($searchTarget, self::HIGH_GUARD_KEYWORDS);
    }

    /** @param  list<string>  $keywords */
    private function matchesAny(string $searchTarget, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (str_contains($searchTarget, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
