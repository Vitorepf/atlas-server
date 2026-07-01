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
 * NO process execution, NO filesystem, NO providers. DETERMINISTIC.
 */
final class AtlasTaskFabricEvidenceFloorSynthesizer
{
    public const SCHEMA = 'atlas.task_fabric.evidence_floor_synthesizer.v1';

    private const BASE_EVIDENCE = ['tests_or_gates_result', 'implementation_notes'];

    private const HIGH_GUARD_KEYWORDS = ['queue', 'verification', 'merge', 'gate', 'certif'];

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

        $evidence = self::BASE_EVIDENCE;
        if ($highGuard) {
            $evidence[] = 'anti_false_green_receipt';
        }
        if ($riskLevel === 'high') {
            $evidence[] = 'anti_stale_timestamp_receipt';
        }

        return [
            'schema_version'     => self::SCHEMA,
            'capability_goal'    => $capabilityGoal,
            'risk_level'         => $riskLevel,
            'high_guard_active'  => $highGuard,
            'acceptance_criteria' => $acceptance,
            'required_evidence'  => array_values(array_unique($evidence)),
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
        foreach (self::HIGH_GUARD_KEYWORDS as $keyword) {
            if (str_contains($searchTarget, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
