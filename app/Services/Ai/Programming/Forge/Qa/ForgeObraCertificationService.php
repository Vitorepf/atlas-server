<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\Forge\Qa;

use App\Models\AiForgeIntake;
use App\Models\AiForgeMilestone;
use App\Services\Ai\EngineeringKernel\Spec\AtlasSpecGateAdapter;
use App\Services\Ai\EngineeringKernel\Spec\IntentEnvelope;
use App\Services\Ai\EngineeringKernel\Spec\SpecAdversary;
use App\Services\Ai\EngineeringKernel\Spec\SpecDraft;
use App\Services\Ai\EngineeringKernel\TrustLevel;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\AtlasDev\Pipeline\IntentActionExtractor;
use App\Services\Ai\Programming\Forge\ForgeIntakeCanon;

/**
 * Per-Obra Forge certification loop. NOT to be confused with
 * `AtlasForgeContinuumCertificationService` (provider topology / fallback /
 * cockpit invariants) or `AtlasForgeRuntimeCertificationService`
 * (surface/domain/governance route). This loop answers a different question:
 *
 *   For THIS Obra intake, are the SDD + QA + evidence minimums met so that
 *   the Obra can transition to a terminal positive state?
 *
 * The loop never certifies an Obra whose QA run reports `failed`. Heavy Obras
 * (Dev → Forge escalation, high/critical risk, or sdd_intake / architecture
 * review / long_run mode) MUST carry a complete SDD spec, declared acceptance
 * criteria, a verification plan, an evidence anchor, and tests-or-waivers on
 * every work packet. A failure on any of those becomes a structured Blocker
 * with a remediation candidate that the caller can persist or surface.
 *
 * Schema: `atlas.forge.obra_certification.v1`.
 */
final class ForgeObraCertificationService
{
    public const STATUS_PASSED = 'passed';

    public const STATUS_WARN = 'warn';

    public const STATUS_FAILED = 'failed';

    public const STATUS_BLOCKED = 'blocked';

    private readonly SpecAdversary $specGate;

    private readonly IntentActionExtractor $verbExtractor;

    public function __construct(
        private readonly ForgeQaGateRunner $qaRunner,
        ?SpecAdversary $specGate = null,
        ?IntentActionExtractor $verbExtractor = null,
    ) {
        // Default to the real fail-closed sovereign spec floor so live runs enforce without a binding;
        // tests may inject a fake SpecAdversary.
        $this->specGate = $specGate ?? new AtlasSpecGateAdapter;
        $this->verbExtractor = $verbExtractor ?? new IntentActionExtractor;
    }

    /**
     * @return array<string,mixed>
     */
    public function certify(AiForgeIntake $intake): array
    {
        $isHeavy = ForgeIntakeCanon::isHeavyObra(
            (string) $intake->origin,
            (string) $intake->risk_band,
            (string) $intake->recommended_forge_mode,
        );

        $qa = $this->qaRunner->run($intake);
        $overall = (string) $qa['overall_status'];
        $blockers = array_values((array) $qa['blockers']);

        // Surface intake-level blocker explicitly so the audit shows it even
        // before the QA layer reaches certification_ready.
        if ((string) $intake->status === ForgeIntakeCanon::STATUS_BLOCKED) {
            $blockers = $this->mergeBlocker($blockers, [
                'schema_version' => 'atlas.forge.qa_blocker.v1',
                'gate_id' => 'intake_status',
                'kind' => 'missing_evidence',
                'severity' => 'critical',
                'reasons' => ['intake_blocked:'.($intake->blocker_reason ?? 'unknown')],
                'remediation' => 'Re-run intake with a valid prompt or escalation packet before requesting certification.',
                'next_action' => 'reopen_intake_with_valid_signal',
            ]);
        }

        $milestoneBlockers = $this->collectMilestoneBlockers($intake);
        foreach ($milestoneBlockers as $milestoneBlocker) {
            $blockers = $this->mergeBlocker($blockers, $milestoneBlocker);
        }

        // Obra #2 enforcement vivo — the sovereign spec-adversary contests the heavy Obra's SDD.
        if (($specBlocker = $this->contestSddSpec($intake, $isHeavy)) !== null) {
            $blockers = $this->mergeBlocker($blockers, $specBlocker);
        }

        $checks = $this->summarizeChecks($qa['gate_runs']);

        $status = $this->resolveStatus($intake, $isHeavy, $overall, $blockers);
        $remediation = $this->buildRemediation($blockers);
        $evidenceRefs = $this->collectEvidenceRefs($intake);

        $payload = [
            'schema_version' => ForgeIntakeCanon::OBRA_CERTIFICATION_SCHEMA_VERSION,
            'intake_id' => $intake->id,
            'intake_hash' => $intake->intake_hash,
            'is_heavy_obra' => $isHeavy,
            'status' => $status,
            'generated_at' => now()->toIso8601String(),
            'qa_gate_run' => $qa,
            'checks' => $checks,
            'blockers' => $blockers,
            'remediation' => $remediation,
            'evidence_refs' => $evidenceRefs,
        ];

        $hashPayload = $payload;
        // Drop volatile timestamp before hashing so the same intake/QA state
        // produces a deterministic cert hash on every replay.
        unset($hashPayload['generated_at']);
        $payload['certification_hash'] = MissionCanonicalHash::sha256($hashPayload);

        return $payload;
    }

    /**
     * Obra #2 enforcement vivo — the sovereign spec-adversary contests a heavy Obra's SDD spec.
     * A STRUCTURAL gap (a recognized write verb with no acceptance criteria) becomes a certification
     * blocker; oracle_adequacy and ambiguity are DEFERRED at intake (no test authored yet; ambiguity
     * is the QA gate's concern), matching the AtlasDev freeze wiring. Provider-free, fail-closed.
     *
     * @return array<string,mixed>|null
     */
    private function contestSddSpec(AiForgeIntake $intake, bool $isHeavy): ?array
    {
        if (! $isHeavy) {
            return null; // only heavy Obras carry a full SDD to contest
        }
        $sdd = is_array($intake->sdd_spec) ? $intake->sdd_spec : [];
        $scope = trim((string) ($sdd['scope'] ?? $sdd['problem_statement'] ?? ''));
        if ($scope === '') {
            return null; // a missing SDD is already blocked by the QA gate; nothing to contest here
        }

        $acceptanceCriteria = [];
        foreach (array_values(array_filter(
            (array) ($sdd['acceptance_criteria'] ?? []),
            static fn ($v): bool => is_string($v) && trim($v) !== '',
        )) as $i => $text) {
            $acceptanceCriteria[] = ['id' => 'sdd_ac_'.$i, 'description' => (string) $text, 'verification' => 'test', 'is_backstop' => false];
        }

        $verdict = $this->specGate->contest(
            new SpecDraft(
                intentText: $scope,
                acceptanceCriteria: $acceptanceCriteria,
                nonGoals: array_values(array_map('strval', (array) ($sdd['non_goals'] ?? []))),
            ),
            new IntentEnvelope(
                rawGoal: (string) ($sdd['problem_statement'] ?? $scope),
                recognizedVerbs: $this->verbExtractor->extract($scope),
            ),
            TrustLevel::Forge,
        );

        $structuralGaps = array_values(array_diff($verdict->gaps, ['oracle_adequacy', 'ambiguity_resolved']));
        if ($structuralGaps === []) {
            return null;
        }

        return [
            'schema_version' => 'atlas.forge.qa_blocker.v1',
            'gate_id' => 'spec_adversary',
            'kind' => 'missing_evidence',
            'severity' => 'critical',
            'reasons' => array_map(static fn (string $gap): string => 'spec_'.$gap, $structuralGaps),
            'remediation' => 'Strengthen the SDD: a recognized write verb requires behavioral acceptance criteria before the Obra can certify.',
            'next_action' => 'revise_sdd_spec',
        ];
    }

    /**
     * @param  array<string,array<string,mixed>>  $gateRuns
     * @return array<int,array<string,mixed>>
     */
    private function summarizeChecks(array $gateRuns): array
    {
        $checks = [];
        foreach ($gateRuns as $gateId => $report) {
            $checks[] = [
                'check_id' => $gateId,
                'status' => (string) ($report['status'] ?? self::STATUS_PASSED),
                'reasons' => array_values((array) ($report['reasons'] ?? [])),
                'remediation' => $report['remediation'] ?? null,
            ];
        }

        return $checks;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function collectMilestoneBlockers(AiForgeIntake $intake): array
    {
        $out = [];
        $milestones = $intake->milestones()->orderBy('position')->get();
        foreach ($milestones as $milestone) {
            /** @var AiForgeMilestone $milestone */
            if (empty($milestone->blocker_reason)) {
                continue;
            }
            $out[] = [
                'schema_version' => 'atlas.forge.qa_blocker.v1',
                'gate_id' => 'milestone:'.$milestone->milestone_id,
                'kind' => 'safety_gate',
                'severity' => 'high',
                'reasons' => ['milestone_blocker:'.$milestone->blocker_reason],
                'remediation' => sprintf(
                    'Resolve milestone %s (%s) blocker before requesting certification.',
                    $milestone->milestone_id,
                    $milestone->title,
                ),
                'next_action' => 'resolve_milestone_blocker',
            ];
        }

        return $out;
    }

    /**
     * @param  array<int,array<string,mixed>>  $blockers
     * @return array<int,array<string,mixed>>
     */
    private function mergeBlocker(array $blockers, array $blocker): array
    {
        $key = (string) ($blocker['gate_id'] ?? '').'|'.implode(';', (array) ($blocker['reasons'] ?? []));
        foreach ($blockers as $existing) {
            $existingKey = (string) ($existing['gate_id'] ?? '').'|'.implode(';', (array) ($existing['reasons'] ?? []));
            if ($existingKey === $key) {
                return $blockers;
            }
        }
        $blockers[] = $blocker;

        return $blockers;
    }

    /**
     * @param  array<int,array<string,mixed>>  $blockers
     */
    private function resolveStatus(AiForgeIntake $intake, bool $isHeavy, string $qaStatus, array $blockers): string
    {
        if ((string) $intake->status === ForgeIntakeCanon::STATUS_BLOCKED) {
            return self::STATUS_BLOCKED;
        }
        if ($qaStatus === ForgeQaGateRunner::STATUS_FAILED) {
            return self::STATUS_FAILED;
        }
        if ($blockers !== []) {
            return self::STATUS_BLOCKED;
        }
        if ($qaStatus === ForgeQaGateRunner::STATUS_WARN) {
            return $isHeavy ? self::STATUS_FAILED : self::STATUS_WARN;
        }

        return self::STATUS_PASSED;
    }

    /**
     * @param  array<int,array<string,mixed>>  $blockers
     * @return array<int,array<string,mixed>>
     */
    private function buildRemediation(array $blockers): array
    {
        $remediation = [];
        $seen = [];
        foreach ($blockers as $blocker) {
            $action = (string) ($blocker['next_action'] ?? 'open_operator_review');
            if (isset($seen[$action])) {
                continue;
            }
            $seen[$action] = true;
            $remediation[] = [
                'action' => $action,
                'gate_id' => $blocker['gate_id'] ?? null,
                'description' => $blocker['remediation'] ?? null,
                'severity' => $blocker['severity'] ?? 'medium',
            ];
        }

        return $remediation;
    }

    /**
     * @return array<int,string>
     */
    private function collectEvidenceRefs(AiForgeIntake $intake): array
    {
        $refs = [];
        $refs[] = 'ai_forge_intakes:'.$intake->id;
        if ($intake->mission_id !== null) {
            $refs[] = 'ai_missions:'.$intake->mission_id;
        }
        if ($intake->escalation_packet_id !== null) {
            $refs[] = 'escalation_packet:'.$intake->escalation_packet_id;
        }
        if ($intake->context_pack_hash !== null) {
            $refs[] = 'context_pack_hash:'.$intake->context_pack_hash;
        }
        foreach ((array) $intake->evidence_refs as $ref) {
            if (is_string($ref) && trim($ref) !== '') {
                $refs[] = $ref;
            }
        }

        return array_values(array_unique($refs));
    }
}
