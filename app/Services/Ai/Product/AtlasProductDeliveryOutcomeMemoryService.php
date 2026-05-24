<?php

namespace App\Services\Ai\Product;

use App\Models\AtlasProductDeliveryOutcomeMemory;
use App\Services\Ai\Aemor\AtlasAemorJudgmentService;
use App\Services\Ai\Aemor\AtlasAemorRuntimeService;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Facades\Schema;

class AtlasProductDeliveryOutcomeMemoryService
{
    public const SCHEMA_VERSION = 'atlas.product_delivery.outcome_memory.v1';

    /**
     * @param  array<string,mixed>  $delivery
     * @param  array<string,mixed>  $proof
     * @param  array<string,mixed>  $evidence
     * @return array<string,mixed>
     */
    public function build(array $delivery, array $proof, array $evidence = []): array
    {
        $status = $this->normalizeStatus((string) ($proof['status'] ?? $delivery['status'] ?? 'needs_review'));
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'delivery_hash' => (string) ($delivery['delivery_hash'] ?? ''),
            'truth_hash' => (string) data_get($delivery, 'product_truth.truth_hash', ''),
            'proof_hash' => (string) ($proof['proof_hash'] ?? ''),
            'route' => (string) ($delivery['route'] ?? data_get($proof, 'target.route', 'unknown')),
            'outcome_status' => $status,
            'evidence_kinds' => $this->evidenceKinds($evidence),
            'required_repairs' => $this->list($proof['required_repairs'] ?? []),
            'learning_candidates' => $this->learningCandidates($delivery, $proof, $status, $evidence),
            'delivery_summary' => [
                'schema_version' => $delivery['schema_version'] ?? null,
                'status' => $delivery['status'] ?? null,
                'route' => $delivery['route'] ?? null,
                'execution_unit' => data_get($delivery, 'delivery_plan.execution_unit'),
                'required_lenses' => $this->list(data_get($delivery, 'delivery_plan.required_lenses', [])),
                'risk_band' => data_get($delivery, 'assisted_execution.execution_contract.risk_band'),
                'apfpr_required' => data_get($delivery, 'proof_requirements.apfpr_required') === true,
                'aedpds_gate_status' => data_get($delivery, 'aedpds.gate.status'),
                'aedpds_gate_hash' => data_get($delivery, 'aedpds.gate.hash'),
                'aedpds_selected_drivers' => $this->list(data_get($delivery, 'aedpds.doctrine.selected_primary_drivers', [])),
                'aedpds_required_gates' => $this->list(data_get($delivery, 'aedpds.gate.required_gates', [])),
                'aedpds_warnings' => $this->list(data_get($delivery, 'aedpds.gate.warnings', [])),
                'aedpds_blockers' => $this->list(data_get($delivery, 'aedpds.gate.blockers', [])),
            ],
            'proof_summary' => [
                'schema_version' => $proof['schema_version'] ?? null,
                'status' => $proof['status'] ?? null,
                'falsification_score' => $proof['falsification_score'] ?? null,
                'critical_blocker_count' => count((array) ($proof['critical_blockers'] ?? [])),
                'counterexample_count' => count((array) ($proof['counterexamples'] ?? [])),
            ],
            'should_promote_to_aemor' => $status !== 'ready' || $this->evidenceKinds($evidence) !== [],
            'human_review_required' => $status !== 'ready' || $this->highRisk($delivery),
        ];
        $payload['outcome_memory_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $delivery
     * @param  array<string,mixed>  $proof
     * @param  array<string,mixed>  $evidence
     */
    public function persist(array $delivery, array $proof, array $evidence = []): ?AtlasProductDeliveryOutcomeMemory
    {
        if (! Schema::hasTable('atlas_product_delivery_outcome_memories')) {
            return null;
        }

        $payload = $this->build($delivery, $proof, $evidence);

        return AtlasProductDeliveryOutcomeMemory::query()->updateOrCreate(
            ['outcome_memory_hash' => $payload['outcome_memory_hash']],
            [
                'schema_version' => $payload['schema_version'],
                'uuid' => $payload['outcome_memory_hash'],
                'delivery_hash' => $payload['delivery_hash'],
                'truth_hash' => $payload['truth_hash'] ?: null,
                'proof_hash' => $payload['proof_hash'] ?: null,
                'route' => $payload['route'],
                'outcome_status' => $payload['outcome_status'],
                'evidence_kinds' => $payload['evidence_kinds'],
                'required_repairs' => $payload['required_repairs'],
                'learning_candidates' => $payload['learning_candidates'],
                'delivery_summary' => $payload['delivery_summary'],
                'proof_summary' => $payload['proof_summary'],
                'should_promote_to_aemor' => $payload['should_promote_to_aemor'],
                'human_review_required' => $payload['human_review_required'],
            ],
        );
    }

    /**
     * @param  array<string,mixed>  $outcomeMemory
     * @return array<string,mixed>
     */
    public function bridgeToAemor(array $outcomeMemory, ?AtlasProductDeliveryOutcomeMemory $record = null): array
    {
        if (! $this->aemorTablesReady()) {
            return [
                'schema_version' => 'atlas.product_delivery.aemor_bridge.v1',
                'status' => 'skipped',
                'reason' => 'aemor_tables_missing',
                'writes' => false,
            ];
        }

        $evidenceRefs = $this->aemorEvidenceRefs($outcomeMemory, $record);
        $runtime = app(AtlasAemorRuntimeService::class);
        $episode = $runtime->openEpisode([
            'objective' => 'AEDPDS product delivery outcome: '.($outcomeMemory['route'] ?? 'unknown').' '.($outcomeMemory['outcome_status'] ?? 'unknown'),
            'workspace' => base_path(),
            'domain' => 'programming',
            'flow_id' => (string) ($outcomeMemory['route'] ?? 'product_delivery'),
            'scope_type' => 'product_delivery',
            'scope_id' => (string) ($outcomeMemory['delivery_hash'] ?? $outcomeMemory['outcome_memory_hash'] ?? 'unknown'),
            'evidence_refs' => $evidenceRefs,
            'source' => 'atlas_product_delivery_outcome_memory',
        ]);

        if (($episode['status'] ?? null) !== 'open' || empty($episode['episode_id'])) {
            return [
                'schema_version' => 'atlas.product_delivery.aemor_bridge.v1',
                'status' => 'blocked',
                'reason' => 'aemor_episode_not_open',
                'writes' => false,
                'episode' => $episode,
            ];
        }

        $event = $runtime->observe([
            'episode_id' => $episode['episode_id'],
            'event_type' => 'product_delivery_outcome_memory',
            'stage' => 'aedpds_outcome',
            'status' => 'observed',
            'payload' => [
                'schema_version' => $outcomeMemory['schema_version'] ?? self::SCHEMA_VERSION,
                'delivery_hash' => $outcomeMemory['delivery_hash'] ?? null,
                'truth_hash' => $outcomeMemory['truth_hash'] ?? null,
                'proof_hash' => $outcomeMemory['proof_hash'] ?? null,
                'route' => $outcomeMemory['route'] ?? null,
                'outcome_status' => $outcomeMemory['outcome_status'] ?? null,
                'evidence_kinds' => $outcomeMemory['evidence_kinds'] ?? [],
                'required_repairs' => $outcomeMemory['required_repairs'] ?? [],
                'learning_candidates' => $outcomeMemory['learning_candidates'] ?? [],
                'delivery_summary' => $outcomeMemory['delivery_summary'] ?? [],
                'proof_summary' => $outcomeMemory['proof_summary'] ?? [],
                'outcome_memory_hash' => $outcomeMemory['outcome_memory_hash'] ?? null,
                'record_id' => $record?->id,
            ],
            'evidence_refs' => $evidenceRefs,
        ]);

        $status = $this->aemorOutcomeStatus((string) ($outcomeMemory['outcome_status'] ?? 'needs_review'));
        $outcome = $runtime->closeOutcome([
            'episode_id' => $episode['episode_id'],
            'status' => $status,
            'outcome_type' => $status === 'succeeded' ? 'product_delivery_success' : 'product_delivery_failure',
            'summary' => 'AEDPDS outcome memory recorded for '.($outcomeMemory['route'] ?? 'unknown').' delivery.',
            'metrics' => [
                'evidence_kinds_count' => count((array) ($outcomeMemory['evidence_kinds'] ?? [])),
                'required_repairs_count' => count((array) ($outcomeMemory['required_repairs'] ?? [])),
                'human_review_required' => (bool) ($outcomeMemory['human_review_required'] ?? false),
                'tests_passed' => in_array('tests', $this->list($outcomeMemory['evidence_kinds'] ?? []), true),
                'attribution_reviewed' => $this->list($outcomeMemory['evidence_kinds'] ?? []) !== [],
            ],
            'blockers' => $status === 'succeeded' ? [] : array_map(
                static fn (string $repair): array => ['id' => $repair, 'reason' => 'AEDPDS required repair.'],
                $this->list($outcomeMemory['required_repairs'] ?? []),
            ),
            'evidence_refs' => $evidenceRefs,
        ]);

        $judgment = null;
        if (! empty($outcome['outcome_id'])) {
            $judgment = app(AtlasAemorJudgmentService::class)->judge((string) $episode['episode_id']);
        }

        $distill = null;
        if (($outcome['status'] ?? null) !== 'blocked'
            && ! empty($outcome['outcome_id'])
            && data_get($judgment, 'false_learning_gate.learning_allowed') === true
        ) {
            $distill = $runtime->distill([
                'episode_id' => $episode['episode_id'],
                'outcome_id' => $outcome['outcome_id'],
                'claim' => $this->aemorClaim($outcomeMemory),
                'evidence_refs' => $evidenceRefs,
            ]);
        }

        return [
            'schema_version' => 'atlas.product_delivery.aemor_bridge.v1',
            'status' => 'recorded',
            'writes' => true,
            'episode_id' => $episode['episode_id'],
            'event_id' => $event['event_id'] ?? null,
            'outcome_id' => $outcome['outcome_id'] ?? null,
            'judgment_report_id' => $judgment['judgment_report_id'] ?? null,
            'judgment_status' => $judgment['status'] ?? null,
            'learning_allowed' => data_get($judgment, 'false_learning_gate.learning_allowed') === true,
            'learning_signal_id' => $distill['learning_signal_id'] ?? null,
            'memory_candidate_id' => $distill['memory_candidate_id'] ?? null,
            'claim_policy' => app(AtlasAemorRuntimeService::class)->claimPolicy(),
        ];
    }

    private function normalizeStatus(string $status): string
    {
        return match ($status) {
            'ready', 'success', 'succeeded', 'passed' => 'ready',
            'blocked', 'failed', 'failure' => 'blocked',
            'needs_repair', 'needs_context', 'needs_product_truth' => 'needs_repair',
            default => 'needs_review',
        };
    }

    /**
     * @param  array<string,mixed>  $evidence
     * @return list<string>
     */
    private function evidenceKinds(array $evidence): array
    {
        return array_values(array_filter(array_keys($evidence), static fn (string $key): bool => $key !== ''));
    }

    /**
     * @param  array<string,mixed>  $delivery
     * @param  array<string,mixed>  $proof
     * @param  array<string,mixed>  $evidence
     * @return list<string>
     */
    private function learningCandidates(array $delivery, array $proof, string $status, array $evidence): array
    {
        $items = [
            'product_delivery_outcome:'.$status,
            'route:'.(string) ($delivery['route'] ?? 'unknown'),
        ];
        if ($this->highRisk($delivery)) {
            $items[] = 'high_risk_product_delivery_requires_apfpr';
        }
        foreach ($this->list($proof['required_repairs'] ?? []) as $repair) {
            $items[] = 'repair:'.$repair;
        }
        if ($this->evidenceKinds($evidence) === []) {
            $items[] = 'missing_product_delivery_evidence';
        }

        return array_values(array_unique($items));
    }

    /**
     * @param  array<string,mixed>  $delivery
     */
    private function highRisk(array $delivery): bool
    {
        $required = $this->list(data_get($delivery, 'product_truth.execution_lenses.required', []));

        return data_get($delivery, 'proof_requirements.apfpr_required') === true
            || array_intersect($required, ['security_driven', 'performance_driven', 'add']) !== [];
    }

    private function aemorTablesReady(): bool
    {
        foreach ([
            'atlas_aemor_execution_episodes',
            'atlas_aemor_execution_events',
            'atlas_aemor_outcomes',
            'atlas_aemor_learning_signals',
            'atlas_aemor_memory_candidates',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $outcomeMemory
     * @return list<string>
     */
    private function aemorEvidenceRefs(array $outcomeMemory, ?AtlasProductDeliveryOutcomeMemory $record): array
    {
        return array_values(array_filter(array_unique(array_merge([
            $record?->id ? 'atlas_product_delivery_outcome_memory:'.$record->id : null,
            ! empty($outcomeMemory['outcome_memory_hash']) ? 'outcome_memory_hash:'.$outcomeMemory['outcome_memory_hash'] : null,
            ! empty($outcomeMemory['delivery_hash']) ? 'delivery_hash:'.$outcomeMemory['delivery_hash'] : null,
            ! empty($outcomeMemory['proof_hash']) ? 'proof_hash:'.$outcomeMemory['proof_hash'] : null,
        ], array_map(
            static fn (string $kind): string => 'evidence_kind:'.$kind,
            $this->list($outcomeMemory['evidence_kinds'] ?? []),
        )))));
    }

    private function aemorOutcomeStatus(string $status): string
    {
        return match ($this->normalizeStatus($status)) {
            'ready' => 'succeeded',
            'blocked' => 'blocked',
            default => 'failed',
        };
    }

    /**
     * @param  array<string,mixed>  $outcomeMemory
     */
    private function aemorClaim(array $outcomeMemory): string
    {
        $route = (string) ($outcomeMemory['route'] ?? 'unknown');
        $status = (string) ($outcomeMemory['outcome_status'] ?? 'needs_review');

        return "AEDPDS {$route} delivery closed with {$status} outcome; reuse evidence kinds, repairs, and risk policy before similar product delivery.";
    }

    /**
     * @return list<string>
     */
    private function list(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): ?string => is_scalar($item) && trim((string) $item) !== '' ? trim((string) $item) : null,
            $value,
        )));
    }
}
