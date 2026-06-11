<?php

namespace App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence;

use App\Models\AtlasDevFailureCapsule;
use App\Models\AtlasDevOutcomeMemory;
use App\Models\AtlasDevTaskPacket;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\AtlasDev\Support\AtlasDevStringListNormalizer;

class DevOutcomeMemoryService
{
    public const SCHEMA_VERSION = 'atlas.dev.outcome_memory.v1';

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function build(array $input, array $packet, ?array $failureCapsule = null): array
    {
        $status = $this->normalizeStatus((string) ($input['outcome_status'] ?? $input['status'] ?? ($failureCapsule ? 'failed' : 'success')));
        $evidence = AtlasDevStringListNormalizer::uniqueTrimmedScalarValues($input['evidence_kinds'] ?? $input['evidence'] ?? []);
        $selectedTests = AtlasDevStringListNormalizer::uniqueTrimmedScalarValues($input['selected_tests'] ?? $packet['suggested_tests'] ?? []);
        $changedFiles = AtlasDevStringListNormalizer::uniqueTrimmedScalarValues($input['changed_files'] ?? $failureCapsule['changed_files'] ?? []);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'run_id' => (string) ($packet['run_id'] ?? $input['run_id'] ?? 'unknown'),
            'task_id' => (string) ($packet['task_id'] ?? $input['task_id'] ?? 'unknown'),
            'outcome_status' => $status,
            'evidence_kinds' => $evidence,
            'selected_tests' => $selectedTests,
            'changed_files' => $changedFiles,
            'learning_candidates' => $this->learningCandidates($status, $packet, $failureCapsule, $evidence),
            'should_promote_to_aemor' => (bool) ($input['should_promote_to_aemor'] ?? $status !== 'success' || $evidence !== []),
            'human_review_required' => (bool) ($input['human_review_required'] ?? in_array($status, ['failed', 'blocked', 'needs_review'], true)),
        ];
        $payload['outcome_memory_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public function persist(array $input, AtlasDevTaskPacket $taskPacket, ?AtlasDevFailureCapsule $failureCapsule = null): AtlasDevOutcomeMemory
    {
        $payload = $this->build($input, $taskPacket->toArray(), $failureCapsule?->toArray());

        return AtlasDevOutcomeMemory::query()->updateOrCreate(
            ['outcome_memory_hash' => $payload['outcome_memory_hash']],
            [
                'schema_version' => $payload['schema_version'],
                'uuid' => $payload['outcome_memory_hash'],
                'run_id' => $payload['run_id'],
                'task_id' => $payload['task_id'],
                'task_packet_id' => $taskPacket->id,
                'failure_capsule_id' => $failureCapsule?->id,
                'outcome_status' => $payload['outcome_status'],
                'evidence_kinds' => $payload['evidence_kinds'],
                'selected_tests' => $payload['selected_tests'],
                'changed_files' => $payload['changed_files'],
                'learning_candidates' => $payload['learning_candidates'],
                'should_promote_to_aemor' => $payload['should_promote_to_aemor'],
                'human_review_required' => $payload['human_review_required'],
            ],
        );
    }

    private function normalizeStatus(string $status): string
    {
        return match ($status) {
            'success', 'succeeded', 'passed' => 'success',
            'failed', 'failure' => 'failed',
            'blocked' => 'blocked',
            'needs_review', 'review' => 'needs_review',
            default => 'needs_review',
        };
    }

    /**
     * @param  array<string,mixed>  $packet
     * @param  null|array<string,mixed>  $failureCapsule
     * @param  list<string>  $evidence
     * @return list<string>
     */
    private function learningCandidates(string $status, array $packet, ?array $failureCapsule, array $evidence): array
    {
        $items = [];
        if ($status !== 'success') {
            $items[] = 'dev_outcome:'.$status;
        }
        if (($packet['risk_band'] ?? null) === 'high') {
            $items[] = 'high_risk_dev_requires_context_gate';
        }
        if ($failureCapsule !== null && ($failureCapsule['failure_class'] ?? null)) {
            $items[] = 'failure_class:'.$failureCapsule['failure_class'];
        }
        if ($evidence === []) {
            $items[] = 'missing_evidence_on_outcome';
        }

        return AtlasDevStringListNormalizer::uniqueMergedStrings($items);
    }

}
