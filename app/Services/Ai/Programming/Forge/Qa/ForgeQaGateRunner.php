<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\Forge\Qa;

use App\Models\AiForgeIntake;
use App\Models\AiForgeMilestone;
use App\Models\AiForgeWorkPacket;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\Forge\ForgeIntakeCanon;

/**
 * Runs the 6 canonical Atlas Forge QA gates for a single Obra intake:
 *
 *   1. spec_complete                (delegated to {@see ForgeSddSpecGate})
 *   2. acceptance_criteria_defined  (sdd_spec.acceptance_criteria + per-packet)
 *   3. verification_plan_defined    (sdd_spec.verification_plan non-empty)
 *   4. evidence_ready               (required_evidence + DoD carry certification anchor)
 *   5. tests_declared_or_blocked    (every packet has tests OR carries an explicit blocker)
 *   6. certification_ready          (composite: all above OK, intake not status=blocked,
 *                                    no open milestone blockers)
 *
 * Each gate emits `passed | warn | failed` with reasons + remediation; the
 * runner aggregates a single `gate_run` payload + a flat blocker list that the
 * certification loop consumes. Heavy Obras (escalation packet, high/critical
 * risk_band, or sdd_intake / architecture_review / long_run mode) get failures
 * for missing fields; light Obras get warnings for the same.
 */
final class ForgeQaGateRunner
{
    public const STATUS_PASSED = 'passed';

    public const STATUS_WARN = 'warn';

    public const STATUS_FAILED = 'failed';

    public function __construct(
        private readonly ForgeSddSpecGate $specGate,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function run(AiForgeIntake $intake): array
    {
        $isHeavy = ForgeIntakeCanon::isHeavyObra(
            (string) $intake->origin,
            (string) $intake->risk_band,
            (string) $intake->recommended_forge_mode,
        );

        $workPackets = $intake->workPackets()->orderBy('packet_position')->get();
        $milestones = $intake->milestones()->orderBy('position')->get();
        $sdd = is_array($intake->sdd_spec) ? $intake->sdd_spec : null;

        $specReport = $this->specGate->evaluate($intake);

        $gateRuns = [
            'spec_complete' => $specReport,
            'acceptance_criteria_defined' => $this->evaluateAcceptanceCriteria($sdd, $workPackets, $isHeavy),
            'verification_plan_defined' => $this->evaluateVerificationPlan($sdd, $isHeavy),
            'evidence_ready' => $this->evaluateEvidenceReady($intake, $isHeavy),
            'tests_declared_or_blocked' => $this->evaluateTestsDeclaredOrBlocked($workPackets, $isHeavy),
        ];

        $gateRuns['certification_ready'] = $this->evaluateCertificationReady($intake, $gateRuns, $milestones);

        $blockers = $this->collectBlockers($gateRuns);
        $overallStatus = $this->resolveOverallStatus($gateRuns);

        $gateRunHashPayload = [
            'schema_version' => ForgeIntakeCanon::QA_GATE_RUN_SCHEMA_VERSION,
            'intake_id' => $intake->id,
            'intake_hash' => $intake->intake_hash,
            'is_heavy_obra' => $isHeavy,
            'gate_runs' => $this->serializeForHash($gateRuns),
            'overall_status' => $overallStatus,
        ];

        return [
            'schema_version' => ForgeIntakeCanon::QA_GATE_RUN_SCHEMA_VERSION,
            'intake_id' => $intake->id,
            'intake_hash' => $intake->intake_hash,
            'is_heavy_obra' => $isHeavy,
            'gate_runs' => $gateRuns,
            'overall_status' => $overallStatus,
            'blockers' => $blockers,
            'gate_run_hash' => MissionCanonicalHash::sha256($gateRunHashPayload),
        ];
    }

    /**
     * @param  array<string,mixed>|null  $sdd
     * @param  iterable<AiForgeWorkPacket>  $workPackets
     * @return array<string,mixed>
     */
    private function evaluateAcceptanceCriteria(?array $sdd, iterable $workPackets, bool $isHeavy): array
    {
        $specAcceptance = is_array($sdd['acceptance_criteria'] ?? null)
            ? array_values(array_filter($sdd['acceptance_criteria'], static fn ($v): bool => is_string($v) && trim($v) !== ''))
            : [];

        $packetsWithoutAcceptance = [];
        $packetCount = 0;
        foreach ($workPackets as $packet) {
            $packetCount++;
            $criteria = (array) $packet->acceptance_criteria;
            $nonEmpty = array_filter($criteria, static fn ($v): bool => is_string($v) && trim($v) !== '');
            if ($nonEmpty === []) {
                $packetsWithoutAcceptance[] = $packet->packet_id;
            }
        }

        if ($specAcceptance !== [] && $packetsWithoutAcceptance === []) {
            return [
                'gate_id' => 'acceptance_criteria_defined',
                'status' => self::STATUS_PASSED,
                'spec_acceptance_count' => count($specAcceptance),
                'work_packets_evaluated' => $packetCount,
                'packets_missing_acceptance' => [],
                'reasons' => [],
                'remediation' => null,
            ];
        }

        $reasons = [];
        if ($specAcceptance === []) {
            $reasons[] = 'sdd_spec_acceptance_criteria_empty';
        }
        if ($packetsWithoutAcceptance !== []) {
            $reasons[] = 'work_packets_missing_acceptance:'.implode(',', $packetsWithoutAcceptance);
        }

        return [
            'gate_id' => 'acceptance_criteria_defined',
            'status' => $isHeavy ? self::STATUS_FAILED : self::STATUS_WARN,
            'spec_acceptance_count' => count($specAcceptance),
            'work_packets_evaluated' => $packetCount,
            'packets_missing_acceptance' => $packetsWithoutAcceptance,
            'reasons' => $reasons,
            'remediation' => 'Declare at least one acceptance criterion in `sdd_spec.acceptance_criteria` and on every work packet.',
        ];
    }

    /**
     * @param  array<string,mixed>|null  $sdd
     * @return array<string,mixed>
     */
    private function evaluateVerificationPlan(?array $sdd, bool $isHeavy): array
    {
        $plan = is_array($sdd['verification_plan'] ?? null)
            ? array_values(array_filter($sdd['verification_plan'], static fn ($v): bool => (is_string($v) && trim($v) !== '') || (is_array($v) && $v !== [])))
            : [];

        if ($plan !== []) {
            return [
                'gate_id' => 'verification_plan_defined',
                'status' => self::STATUS_PASSED,
                'verification_plan_count' => count($plan),
                'reasons' => [],
                'remediation' => null,
            ];
        }

        return [
            'gate_id' => 'verification_plan_defined',
            'status' => $isHeavy ? self::STATUS_FAILED : self::STATUS_WARN,
            'verification_plan_count' => 0,
            'reasons' => ['sdd_spec_verification_plan_empty'],
            'remediation' => 'Declare at least one verification step in `sdd_spec.verification_plan` (test, command, gate run, manual review).',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function evaluateEvidenceReady(AiForgeIntake $intake, bool $isHeavy): array
    {
        $required = array_values(array_filter((array) $intake->required_evidence, static fn ($v): bool => is_string($v) && trim($v) !== ''));
        $dod = array_values(array_filter((array) $intake->definition_of_done, static fn ($v): bool => is_string($v) && trim($v) !== ''));

        $hasCertificationAnchor = $this->listContainsAny(
            $dod,
            ['certification_passed', 'certification_passed_or_blocked'],
        ) || $this->listContainsAny($required, ['certification']);

        $reasons = [];
        if ($required === []) {
            $reasons[] = 'required_evidence_empty';
        }
        if (! $hasCertificationAnchor) {
            $reasons[] = 'no_certification_anchor_in_dod_or_required_evidence';
        }

        if ($reasons === []) {
            return [
                'gate_id' => 'evidence_ready',
                'status' => self::STATUS_PASSED,
                'required_evidence_count' => count($required),
                'definition_of_done_count' => count($dod),
                'reasons' => [],
                'remediation' => null,
            ];
        }

        return [
            'gate_id' => 'evidence_ready',
            'status' => $isHeavy ? self::STATUS_FAILED : self::STATUS_WARN,
            'required_evidence_count' => count($required),
            'definition_of_done_count' => count($dod),
            'reasons' => $reasons,
            'remediation' => 'Populate `required_evidence` and ensure `definition_of_done` includes a certification anchor (e.g. `certification_passed`).',
        ];
    }

    /**
     * @param  iterable<AiForgeWorkPacket>  $workPackets
     * @return array<string,mixed>
     */
    private function evaluateTestsDeclaredOrBlocked(iterable $workPackets, bool $isHeavy): array
    {
        $packetsWithoutTests = [];
        $packetsWithReason = [];
        $packetsWithTests = [];
        $packetCount = 0;

        foreach ($workPackets as $packet) {
            $packetCount++;
            $tests = (array) ($packet->suggested_tests ?? []);
            $nonEmpty = array_filter($tests, static fn ($v): bool => is_string($v) && trim($v) !== '');
            if ($nonEmpty !== []) {
                $packetsWithTests[] = $packet->packet_id;

                continue;
            }
            $required = (array) ($packet->required_evidence ?? []);
            if ($this->listContainsAny($required, ['no_test_required', 'tests_explicitly_waived'])) {
                $packetsWithReason[] = $packet->packet_id;

                continue;
            }
            $packetsWithoutTests[] = $packet->packet_id;
        }

        if ($packetCount === 0) {
            return [
                'gate_id' => 'tests_declared_or_blocked',
                'status' => $isHeavy ? self::STATUS_FAILED : self::STATUS_WARN,
                'work_packets_evaluated' => 0,
                'packets_with_tests' => [],
                'packets_with_waiver' => [],
                'packets_without_tests' => [],
                'reasons' => ['no_work_packets_available'],
                'remediation' => 'Heavy Obras require at least one ready work packet with declared tests or an explicit waiver.',
            ];
        }

        if ($packetsWithoutTests === []) {
            return [
                'gate_id' => 'tests_declared_or_blocked',
                'status' => self::STATUS_PASSED,
                'work_packets_evaluated' => $packetCount,
                'packets_with_tests' => $packetsWithTests,
                'packets_with_waiver' => $packetsWithReason,
                'packets_without_tests' => [],
                'reasons' => [],
                'remediation' => null,
            ];
        }

        return [
            'gate_id' => 'tests_declared_or_blocked',
            'status' => $isHeavy ? self::STATUS_FAILED : self::STATUS_WARN,
            'work_packets_evaluated' => $packetCount,
            'packets_with_tests' => $packetsWithTests,
            'packets_with_waiver' => $packetsWithReason,
            'packets_without_tests' => $packetsWithoutTests,
            'reasons' => ['packets_without_tests:'.implode(',', $packetsWithoutTests)],
            'remediation' => 'Add `suggested_tests` to every work packet or mark `required_evidence` with `no_test_required` after operator approval.',
        ];
    }

    /**
     * @param  array<string,array<string,mixed>>  $gateRuns
     * @param  iterable<AiForgeMilestone>  $milestones
     * @return array<string,mixed>
     */
    private function evaluateCertificationReady(AiForgeIntake $intake, array $gateRuns, iterable $milestones): array
    {
        $reasons = [];

        if ((string) $intake->status === ForgeIntakeCanon::STATUS_BLOCKED) {
            $reasons[] = 'intake_status_blocked:'.($intake->blocker_reason ?? 'unknown');
        }

        $failedGates = [];
        foreach ($gateRuns as $gateId => $report) {
            if ($gateId === 'certification_ready') {
                continue;
            }
            if (($report['status'] ?? self::STATUS_PASSED) === self::STATUS_FAILED) {
                $failedGates[] = $gateId;
            }
        }
        if ($failedGates !== []) {
            $reasons[] = 'failed_gates:'.implode(',', $failedGates);
        }

        $blockedMilestones = [];
        foreach ($milestones as $milestone) {
            if (! empty($milestone->blocker_reason)) {
                $blockedMilestones[] = $milestone->milestone_id;
            }
        }
        if ($blockedMilestones !== []) {
            $reasons[] = 'milestones_with_blocker:'.implode(',', $blockedMilestones);
        }

        if ($reasons === []) {
            return [
                'gate_id' => 'certification_ready',
                'status' => self::STATUS_PASSED,
                'reasons' => [],
                'remediation' => null,
            ];
        }

        return [
            'gate_id' => 'certification_ready',
            'status' => self::STATUS_FAILED,
            'reasons' => $reasons,
            'remediation' => 'Resolve every failed gate, milestone blocker, and ensure the intake itself is not blocked before requesting certification.',
        ];
    }

    /**
     * @param  array<string,array<string,mixed>>  $gateRuns
     * @return array<int,array<string,mixed>>
     */
    private function collectBlockers(array $gateRuns): array
    {
        $blockers = [];
        foreach ($gateRuns as $gateId => $report) {
            $status = (string) ($report['status'] ?? self::STATUS_PASSED);
            if ($status !== self::STATUS_FAILED) {
                continue;
            }
            $blockers[] = [
                'schema_version' => 'atlas.forge.qa_blocker.v1',
                'gate_id' => $gateId,
                'kind' => $this->classifyBlocker($gateId),
                'severity' => $gateId === 'certification_ready' ? 'critical' : 'high',
                'reasons' => array_values((array) ($report['reasons'] ?? [])),
                'remediation' => $report['remediation'] ?? 'Address the reasons emitted by this gate before re-running QA.',
                'next_action' => $this->nextActionFor($gateId),
            ];
        }

        return $blockers;
    }

    private function classifyBlocker(string $gateId): string
    {
        return match ($gateId) {
            'spec_complete' => 'missing_evidence',
            'acceptance_criteria_defined' => 'missing_evidence',
            'verification_plan_defined' => 'missing_evidence',
            'evidence_ready' => 'missing_evidence',
            'tests_declared_or_blocked' => 'safety_gate',
            'certification_ready' => 'safety_gate',
            default => 'inconclusive_result',
        };
    }

    private function nextActionFor(string $gateId): string
    {
        return match ($gateId) {
            'spec_complete' => 'attach_sdd_spec_then_rerun_qa',
            'acceptance_criteria_defined' => 'declare_acceptance_criteria_then_rerun_qa',
            'verification_plan_defined' => 'declare_verification_plan_then_rerun_qa',
            'evidence_ready' => 'declare_required_evidence_and_certification_anchor_then_rerun_qa',
            'tests_declared_or_blocked' => 'declare_tests_or_explicit_waiver_then_rerun_qa',
            'certification_ready' => 'resolve_dependent_gates_then_rerun_qa',
            default => 'open_operator_review',
        };
    }

    /**
     * @param  array<string,array<string,mixed>>  $gateRuns
     */
    private function resolveOverallStatus(array $gateRuns): string
    {
        $statuses = array_map(
            static fn (array $report): string => (string) ($report['status'] ?? self::STATUS_PASSED),
            $gateRuns,
        );

        if (in_array(self::STATUS_FAILED, $statuses, true)) {
            return self::STATUS_FAILED;
        }
        if (in_array(self::STATUS_WARN, $statuses, true)) {
            return self::STATUS_WARN;
        }

        return self::STATUS_PASSED;
    }

    /**
     * Strip per-gate fields that are not stable for hashing (e.g. arrays of
     * dynamic strings still hash deterministically because MissionCanonicalHash
     * sorts keys recursively; we only normalize that gate_id is present).
     *
     * @param  array<string,array<string,mixed>>  $gateRuns
     * @return array<string,array<string,mixed>>
     */
    private function serializeForHash(array $gateRuns): array
    {
        $clean = [];
        foreach ($gateRuns as $key => $report) {
            $clean[$key] = $report;
        }

        return $clean;
    }

    /**
     * @param  array<int,mixed>  $haystack
     * @param  array<int,string>  $needles
     */
    private function listContainsAny(array $haystack, array $needles): bool
    {
        foreach ($haystack as $entry) {
            if (! is_string($entry)) {
                continue;
            }
            $entry = strtolower(trim($entry));
            foreach ($needles as $needle) {
                if ($entry === strtolower($needle)) {
                    return true;
                }
            }
        }

        return false;
    }
}
