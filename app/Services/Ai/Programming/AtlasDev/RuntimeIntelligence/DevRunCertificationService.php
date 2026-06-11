<?php

namespace App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence;

use App\Models\AtlasDevContextGate;
use App\Models\AtlasDevDecisionMaterialization;
use App\Models\AtlasDevFailureCapsule;
use App\Models\AtlasDevOutcomeMemory;
use App\Models\AtlasDevRunCertification;
use App\Models\AtlasDevTaskPacket;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Product\AtlasExecutionDoctrineGateService;
use App\Services\Ai\Product\AtlasExecutionDoctrineRuntimeService;
use App\Services\Ai\Programming\AtlasDev\Support\AtlasDevStringListNormalizer;

class DevRunCertificationService
{
    public const SCHEMA_VERSION = 'atlas.dev.run_certification.v1';

    public const STATUS_READY = 'ready';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_NEEDS_REVIEW = 'needs_review';

    public function __construct(
        private readonly AtlasExecutionDoctrineRuntimeService $aedpds = new AtlasExecutionDoctrineRuntimeService,
        private readonly AtlasExecutionDoctrineGateService $aedpdsGate = new AtlasExecutionDoctrineGateService,
    ) {}

    public function certify(
        AtlasDevTaskPacket $taskPacket,
        AtlasDevContextGate $contextGate,
        AtlasDevOutcomeMemory $outcomeMemory,
        ?AtlasDevFailureCapsule $failureCapsule = null,
        array $decisionMaterializations = [],
    ): array {
        $decisionKinds = $this->decisionKinds($decisionMaterializations);
        $realAcceptanceCriteria = AtlasDevStringListNormalizer::realStringsWithoutGeneratedPrefix(
            $taskPacket->acceptance_criteria,
            'aedpds_'
        );
        $realContextRefs = AtlasDevStringListNormalizer::realStringsWithoutGeneratedPrefix(
            $taskPacket->context_refs,
            'aedpds_context:'
        );
        $realTests = AtlasDevStringListNormalizer::realStringsWithoutGeneratedPrefix(
            $taskPacket->suggested_tests,
            'aedpds_test:'
        );
        $realEvidence = AtlasDevStringListNormalizer::realStringsWithoutGeneratedPrefix(
            $taskPacket->required_evidence,
            'aedpds_evidence:'
        );
        $doctrine = $this->aedpds->select([
            'task' => $taskPacket->objective,
            'surface' => 'atlas_dev',
            'workspace' => $taskPacket->workspace_slug,
            'task_type' => $taskPacket->task_class,
            'risk_level' => $taskPacket->risk_band,
            'code_changes_requested' => ! in_array($taskPacket->task_class, ['trivial', 'read_only', 'review'], true),
            'missing_context' => ! $this->hasAny($realContextRefs)
                && ! $this->hasAny($taskPacket->expected_files)
                && ! $this->hasAny($taskPacket->allowed_files),
        ]);
        $aedpdsGate = $this->aedpdsGate->evaluate([
            'doctrine' => $doctrine,
            'acceptance_criteria' => $realAcceptanceCriteria,
            'context_refs' => $realContextRefs,
            'tests' => $realTests,
            'evidence' => $realEvidence,
        ]);
        $aedpdsChecks = [
            $this->check('aedpds_doctrine_selected', $doctrine['selected_primary_drivers'] !== [], 'AEDPDS selected delivery drivers'),
            $this->check('aedpds_gate_passed', ($aedpdsGate['status'] ?? null) === 'passed', 'AEDPDS gate passed before Dev run certification'),
        ];
        $checks = [
            $this->check('task_packet_present', true, 'Dev task packet persisted'),
            $this->check('context_gate_passed', $contextGate->status === DevContextGateService::STATUS_PASSED, 'Context gate passed before provider-safe execution'),
            $this->check('scope_declared', $this->hasAny($taskPacket->allowed_files) || $this->hasAny($taskPacket->expected_files), 'Allowed/expected files declared'),
            $this->check('verification_declared', $this->hasAny($taskPacket->suggested_tests) || $this->hasAny($taskPacket->required_evidence), 'Tests or evidence declared'),
            $this->check('outcome_memory_persisted', (bool) $outcomeMemory->exists, 'Outcome memory persisted'),
            $this->check('evidence_present', $this->hasAny($outcomeMemory->evidence_kinds), 'Outcome evidence is present'),
            $this->check(
                'failed_run_has_failure_capsule',
                $outcomeMemory->outcome_status === 'success' || $failureCapsule !== null,
                'Failed/blocked Dev run requires a failure capsule',
            ),
            $this->check('test_impact_materialized', in_array('test_impact', $decisionKinds, true), 'Focused/fallback test impact decision materialized'),
            $this->check('senior_review_materialized', in_array('senior_review', $decisionKinds, true), 'Risk-proportional senior review decision materialized'),
            $this->check('delegation_route_materialized', in_array('delegation_route', $decisionKinds, true), 'Local/subagent/Forge route decision materialized'),
            $this->check('prompt_projection_guard_materialized', in_array('prompt_projection_guard', $decisionKinds, true), 'Provider/subagent prompt projection guard materialized'),
            $this->check('scope_guard_materialized', in_array('scope_guard', $decisionKinds, true), 'Task-bound scope guard decision materialized'),
            $this->check('simulation_materialized', in_array('simulation', $decisionKinds, true), 'Pre-execution simulation decision materialized'),
            $this->check('completion_gate_materialized', in_array('completion_gate', $decisionKinds, true), 'Completion gate decision materialized'),
            $this->check('provider_capacity_memory_materialized', in_array('provider_capacity_memory', $decisionKinds, true), 'Provider capacity/failure memory observation materialized'),
            $this->check('forge_escalation_policy_materialized', in_array('forge_escalation_policy', $decisionKinds, true), 'Dev-to-Forge escalation policy decision materialized'),
            $this->check('decision_materialization_present', in_array('decision_materialization', $decisionKinds, true), 'Important Dev decisions persisted for replay'),
        ];

        $blockers = array_values(array_filter($checks, static fn (array $check): bool => $check['status'] === 'fail'));
        $aedpdsBlockers = array_values(array_filter($aedpdsChecks, static fn (array $check): bool => $check['status'] === 'fail'));
        $certificationBlockers = array_values(array_merge($blockers, $aedpdsBlockers));
        $status = $certificationBlockers === []
            ? self::STATUS_READY
            : ($contextGate->status === DevContextGateService::STATUS_BLOCKED ? self::STATUS_BLOCKED : self::STATUS_NEEDS_REVIEW);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'run_id' => $taskPacket->run_id,
            'task_id' => $taskPacket->task_id,
            'status' => $status,
            'summary' => [
                'total' => count($checks),
                'pass' => count(array_filter($checks, static fn (array $check): bool => $check['status'] === 'pass')),
                'fail' => count($blockers),
                'aedpds_fail' => count($aedpdsBlockers),
                'provider_safe' => $status === self::STATUS_READY,
                'outcome_status' => $outcomeMemory->outcome_status,
                'aedpds_gate_status' => $aedpdsGate['status'],
                'aedpds_selected_drivers' => $doctrine['selected_primary_drivers'],
                'aedpds_checks' => $aedpdsChecks,
            ],
            'checks' => $checks,
            'blockers' => $certificationBlockers,
            'artifact_refs' => [
                'task_packet_hash' => $taskPacket->task_packet_hash,
                'context_gate_hash' => $contextGate->context_gate_hash,
                'failure_hash' => $failureCapsule?->failure_hash,
                'outcome_memory_hash' => $outcomeMemory->outcome_memory_hash,
                'decision_hashes' => $this->decisionHashes($decisionMaterializations),
            ],
            'aedpds' => [
                'selected_drivers' => $doctrine['selected_primary_drivers'],
                'required_gates' => $doctrine['required_gates'],
                'gate_status' => $aedpdsGate['status'],
                'gate_hash' => $aedpdsGate['hash'],
                'checks' => $aedpdsChecks,
                'blockers' => $aedpdsGate['blockers'],
                'warnings' => $aedpdsGate['warnings'],
                'outcome' => $outcomeMemory->outcome_status,
            ],
        ];
        $payload['certification_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    public function persist(
        AtlasDevTaskPacket $taskPacket,
        AtlasDevContextGate $contextGate,
        AtlasDevOutcomeMemory $outcomeMemory,
        ?AtlasDevFailureCapsule $failureCapsule = null,
        array $decisionMaterializations = [],
    ): AtlasDevRunCertification {
        $payload = $this->certify($taskPacket, $contextGate, $outcomeMemory, $failureCapsule, $decisionMaterializations);

        return AtlasDevRunCertification::query()->updateOrCreate(
            ['certification_hash' => $payload['certification_hash']],
            [
                'schema_version' => $payload['schema_version'],
                'uuid' => $payload['certification_hash'],
                'run_id' => $payload['run_id'],
                'task_id' => $payload['task_id'],
                'task_packet_id' => $taskPacket->id,
                'context_gate_id' => $contextGate->id,
                'failure_capsule_id' => $failureCapsule?->id,
                'outcome_memory_id' => $outcomeMemory->id,
                'status' => $payload['status'],
                'summary' => $payload['summary'],
                'checks' => $payload['checks'],
                'blockers' => $payload['blockers'],
            ],
        );
    }

    /**
     * @return array<string,string>
     */
    private function check(string $id, bool $passed, string $reason): array
    {
        return [
            'id' => $id,
            'status' => $passed ? 'pass' : 'fail',
            'reason' => $reason,
        ];
    }

    private function hasAny(mixed $value): bool
    {
        return is_array($value) && array_values($value) !== [];
    }

    /**
     * @param  array<int,AtlasDevDecisionMaterialization|array<string,mixed>>  $decisions
     * @return list<string>
     */
    private function decisionKinds(array $decisions): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $decision): ?string => $decision instanceof AtlasDevDecisionMaterialization
                ? $decision->decision_kind
                : (is_array($decision) && is_scalar($decision['decision_kind'] ?? null) ? (string) $decision['decision_kind'] : null),
            $decisions,
        ))));
    }

    /**
     * @param  array<int,AtlasDevDecisionMaterialization|array<string,mixed>>  $decisions
     * @return list<string>
     */
    private function decisionHashes(array $decisions): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $decision): ?string => $decision instanceof AtlasDevDecisionMaterialization
                ? $decision->decision_hash
                : (is_array($decision) && is_scalar($decision['decision_hash'] ?? null) ? (string) $decision['decision_hash'] : null),
            $decisions,
        )));
    }
}
