<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\MultiProject;

/**
 * Project-specific stewardship gate. Combines three independent FACT sources — admitted lane manifest,
 * context-freshness verdict, and candidate task-evidence facts — into ONE deterministic verification
 * policy verdict.
 *
 * INVARIANTS:
 *   - allowed=true ONLY when admission.admitted===true AND freshness.conformant===true AND every
 *     declared verification_command has a matching task_evidence.evidence row reporting passed===true
 *     AND at least one verification_command is declared AND no declared scope_path escapes project_root.
 *   - allowed=false ⇒ blockers[] enumerates the EXACT missing/failing facts (no scalar score).
 *   - DETERMINISTIC: identical (admission, freshness, evidence) ⇒ byte-identical envelope.
 *   - project_root/scope_paths are OPTIONAL inputs: the boundary check only fires when project_root
 *     is declared, so lanes that don't supply boundary facts are unaffected (backward compatible).
 */
final class AtlasProjectLaneVerificationPolicy
{
    public const SCHEMA = 'atlas.multiproject.lane_verification_policy.v1';

    /**
     * @param  array<string,mixed>  $admission      output of AtlasProjectLaneAdmissionPolicy::admit()
     * @param  array<string,mixed>  $freshness      output of AtlasProjectLaneContextFreshnessGate::evaluate()
     * @param  array{evidence?:array<string,array{passed?:bool, gate?:string}>}  $taskEvidence  candidate task FACT bundle
     * @return array{schema:string, allowed:bool, project_id:string, blockers:list<string>, sources:array{admission_admitted:bool, freshness_conformant:bool, missing_evidence_for:list<string>, failing_evidence_for:list<string>}}
     */
    public function decide(array $admission, array $freshness, array $taskEvidence): array
    {
        $blockers = [];
        $admitted = (bool) ($admission['admitted'] ?? false);
        if (! $admitted) {
            foreach ((array) ($admission['blocking_reasons'] ?? []) as $r) {
                $blockers[] = 'admission:'.(string) $r;
            }
            if ($admission === [] || ! isset($admission['admitted'])) {
                $blockers[] = 'admission:envelope_missing';
            }
        }

        $freshConformant = (bool) ($freshness['conformant'] ?? false);
        if (! $freshConformant) {
            foreach ((array) ($freshness['blockers'] ?? []) as $b) {
                $blockers[] = 'freshness:'.(string) $b;
            }
            if ($freshness === [] || ! isset($freshness['conformant'])) {
                $blockers[] = 'freshness:envelope_missing';
            }
        }

        // Lane-local test command must be declared.
        $laneTestCmd = (string) ($admission['lane_local_test_command'] ?? '');
        if ($laneTestCmd === '') {
            $blockers[] = 'lane_local_test_command_missing';
        }

        // Evidence ledger must be isolated to this lane.
        if (! (bool) ($admission['evidence_ledger_isolated'] ?? false)) {
            $blockers[] = 'evidence_ledger_not_isolated';
        }

        // Rollback proof must be present in task evidence.
        if (! (bool) ($taskEvidence['rollback_proof'] ?? false)) {
            $blockers[] = 'rollback_proof_missing';
        }

        $verificationCmds = is_array($admission['verification_commands'] ?? null)
            ? array_values(array_map('strval', $admission['verification_commands']))
            : [];
        if ($verificationCmds === []) {
            $blockers[] = 'verification_commands_missing';
        }

        // Project boundary check — a lane must never reach outside its own project root. Optional
        // input: fires only when project_root is declared, so lanes without boundary facts yet are
        // unaffected.
        $projectRoot = (string) ($admission['project_root'] ?? '');
        $scopePaths = is_array($admission['scope_paths'] ?? null)
            ? array_values(array_map('strval', $admission['scope_paths']))
            : [];
        $boundaryViolations = [];
        if ($projectRoot !== '') {
            foreach ($scopePaths as $p) {
                if (! str_starts_with($p, $projectRoot)) {
                    $boundaryViolations[] = $p;
                    $blockers[] = 'project_boundary_violation:'.$p;
                }
            }
        }

        $evidenceByGate = is_array($taskEvidence['evidence'] ?? null) ? $taskEvidence['evidence'] : [];
        $missing = [];
        $failing = [];
        foreach ($verificationCmds as $cmd) {
            $row = is_array($evidenceByGate[$cmd] ?? null) ? $evidenceByGate[$cmd] : null;
            if ($row === null) {
                $missing[] = $cmd;
                $blockers[] = 'evidence_missing_for:'.$cmd;

                continue;
            }
            if (($row['passed'] ?? null) !== true) {
                $failing[] = $cmd;
                $blockers[] = 'evidence_failing_for:'.$cmd;
            }
        }

        // Sort blockers for determinism.
        $blockers = array_values(array_unique($blockers));
        sort($blockers, SORT_STRING);
        sort($missing, SORT_STRING);
        sort($failing, SORT_STRING);
        sort($boundaryViolations, SORT_STRING);

        $missingArtifacts = array_values(array_filter(
            $blockers,
            static fn (string $b): bool => ! str_starts_with($b, 'evidence_failing_for:'),
        ));

        $requiredNextChecks = array_values(array_unique(array_map(
            fn (string $b): string => $this->nextCheckFor($b),
            $blockers,
        )));
        sort($requiredNextChecks, SORT_STRING);

        return [
            'schema' => self::SCHEMA,
            'allowed' => $blockers === [],
            'project_id' => (string) ($admission['project_id'] ?? ''),
            'blockers' => $blockers,
            'sources' => [
                'admission_admitted' => $admitted,
                'freshness_conformant' => $freshConformant,
                'missing_evidence_for' => $missing,
                'failing_evidence_for' => $failing,
                'boundary_violations' => $boundaryViolations,
            ],
            'lane_decision' => $blockers === [] ? 'admit' : 'block',
            'missing_artifacts' => $missingArtifacts,
            'risk_band' => $this->riskBand($blockers, $admitted, $freshConformant),
            'required_next_checks' => $requiredNextChecks,
        ];
    }

    /** @param  list<string>  $blockers */
    private function riskBand(array $blockers, bool $admitted, bool $freshConformant): string
    {
        if ($blockers === []) {
            return 'none';
        }
        foreach ($blockers as $b) {
            if (str_starts_with($b, 'project_boundary_violation:') || $b === 'evidence_ledger_not_isolated') {
                return 'critical';
            }
        }
        if (! $admitted || ! $freshConformant) {
            return 'high';
        }

        return 'medium';
    }

    private function nextCheckFor(string $blocker): string
    {
        return match (true) {
            str_starts_with($blocker, 'admission:') => 'resolve admission blocking_reasons before retrying',
            str_starts_with($blocker, 'freshness:') => 're-run context freshness check for this lane',
            $blocker === 'lane_local_test_command_missing' => 'declare a lane_local_test_command in the admission manifest',
            $blocker === 'evidence_ledger_not_isolated' => 'isolate the evidence ledger to this lane before activation',
            $blocker === 'rollback_proof_missing' => 'attach rollback_proof to the task evidence bundle',
            $blocker === 'verification_commands_missing' => 'declare at least one verification_command for this lane',
            str_starts_with($blocker, 'evidence_missing_for:') => 'attach evidence for '.substr($blocker, strlen('evidence_missing_for:')),
            str_starts_with($blocker, 'evidence_failing_for:') => 'fix the failing gate for '.substr($blocker, strlen('evidence_failing_for:')),
            str_starts_with($blocker, 'project_boundary_violation:') => 'restrict scope_paths to within project_root',
            default => 'investigate blocker: '.$blocker,
        };
    }
}
