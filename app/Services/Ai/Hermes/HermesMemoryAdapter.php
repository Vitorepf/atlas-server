<?php

namespace App\Services\Ai\Hermes;

use App\Models\AiJob;
use App\Models\AiMemoryDelta;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Str;

class HermesMemoryAdapter
{
    use HermesAdapterReceipt;

    /**
     * @param  array<string,mixed>  $resultPacket
     * @param  array<string,mixed>  $mission
     * @param  array<string,mixed>  $invocation
     * @return array<string,mixed>
     */
    public function persistCandidates(AiJob $job, array $resultPacket, array $mission, array $invocation, string $memoryPolicy): array
    {
        $candidates = data_get($resultPacket, 'memory_gate.candidates', []);
        $candidates = is_array($candidates) ? array_values($candidates) : [];
        $receipt = [
            'schema_version' => 'atlas.hermes.memory_adapter_receipt.v1',
            'adapter' => 'hermes_memory_adapter',
            'memory_policy' => $memoryPolicy,
            'canonical_memory' => 'atlas',
            'atlas_memory_gate' => 'ai_memory_deltas',
            'promotion_allowed_now' => false,
            'candidate_count' => count($candidates),
            'eligible_candidate_count' => 0,
            'persisted_count' => 0,
            'duplicate_count' => 0,
            'skipped_count' => 0,
            'persisted_delta_ids' => [],
            'duplicate_delta_ids' => [],
            'skipped_candidates' => [],
            'status' => 'no_candidates',
        ];

        if ($candidates === []) {
            return $this->withReceiptHash($receipt);
        }

        if ($memoryPolicy !== 'atlas_adapter') {
            $receipt['status'] = 'skipped_by_policy';
            $receipt['skipped_count'] = count($candidates);
            $receipt['skipped_candidates'] = $this->skippedCandidates($candidates, 'memory_policy_not_atlas_adapter');

            return $this->withReceiptHash($receipt);
        }

        if (! DatabaseTableAvailability::has('ai_memory_deltas')) {
            $receipt['status'] = 'memory_gate_unavailable';
            $receipt['skipped_count'] = count($candidates);
            $receipt['skipped_candidates'] = $this->skippedCandidates($candidates, 'ai_memory_deltas_table_missing');

            return $this->withReceiptHash($receipt);
        }

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $claim = $this->string($candidate['claim'] ?? null, 700);
            $candidateId = $this->string($candidate['candidate_id'] ?? null, 120) ?: 'hermes_memory_candidate_unknown';
            if ($claim === null) {
                $receipt['skipped_count']++;
                $receipt['skipped_candidates'][] = [
                    'candidate_id' => $candidateId,
                    'reason' => 'missing_claim',
                ];

                continue;
            }

            if ($this->shouldSkip($candidate)) {
                $receipt['skipped_count']++;
                $receipt['skipped_candidates'][] = [
                    'candidate_id' => $candidateId,
                    'reason' => 'candidate_marked_discard_or_noise',
                ];

                continue;
            }

            $receipt['eligible_candidate_count']++;
            $scope = $this->scope($job);
            $type = $this->deltaType($candidate);
            $existing = AiMemoryDelta::query()
                ->where('claim', $claim)
                ->where('scope', $scope)
                ->first();

            if ($existing instanceof AiMemoryDelta) {
                $receipt['duplicate_count']++;
                $receipt['duplicate_delta_ids'][] = $existing->id;

                continue;
            }

            $delta = AiMemoryDelta::query()->create([
                'source_trace_id' => $this->uuidOrNull($job->trace_id),
                'source_session_id' => $this->uuidOrNull(data_get($job->metadata, 'session_id')),
                'source_workspace' => $this->workspace($job),
                'type' => $type,
                'claim' => $claim,
                'evidence' => $this->evidence($candidate, $resultPacket, $mission, $invocation),
                'scope' => $scope,
                'confidence' => $this->confidence($candidate),
                'valid_from' => now(),
                'valid_until' => now()->addDays(90),
                'use_when' => [
                    'operator or Atlas Memory Gate approves this Hermes runtime learning',
                    'future ATLS mission matches the same workspace, runtime or procedure context',
                ],
                'do_not_use_when' => [
                    'candidate remains pending or rejected',
                    'claim conflicts with canonical Atlas docs, evidence ledger or operator review',
                    'Hermes suggested action was discard or the candidate is classified as noise',
                ],
                'requires_confirmation' => true,
                'status' => 'pending',
            ]);

            $receipt['persisted_count']++;
            $receipt['persisted_delta_ids'][] = $delta->id;
        }

        $receipt['status'] = $receipt['persisted_count'] > 0
            ? 'persisted_for_review'
            : ($receipt['duplicate_count'] > 0 ? 'deduplicated' : 'skipped_by_gate');

        return $this->withReceiptHash($receipt);
    }

    /**
     * @param  array<int,mixed>  $candidates
     * @return array<int,array<string,string>>
     */
    private function skippedCandidates(array $candidates, string $reason): array
    {
        return collect($candidates)
            ->filter(fn (mixed $candidate): bool => is_array($candidate))
            ->map(fn (array $candidate): array => [
                'candidate_id' => $this->string($candidate['candidate_id'] ?? null, 120) ?: 'hermes_memory_candidate_unknown',
                'reason' => $reason,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $candidate
     */
    private function shouldSkip(array $candidate): bool
    {
        return ($candidate['suggested_action'] ?? null) === 'discard'
            || ($candidate['class'] ?? null) === 'noise';
    }

    /**
     * @param  array<string,mixed>  $candidate
     */
    private function deltaType(array $candidate): string
    {
        return match ((string) ($candidate['class'] ?? 'fact')) {
            'preference' => 'preference',
            'procedure' => 'process',
            'workaround' => 'error_pattern',
            default => 'technical_context',
        };
    }

    /**
     * @param  array<string,mixed>  $candidate
     */
    private function confidence(array $candidate): float
    {
        $confidence = is_numeric($candidate['confidence'] ?? null) ? (float) $candidate['confidence'] : 0.5;

        return min(1.0, max(0.0, $confidence));
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @param  array<string,mixed>  $resultPacket
     * @param  array<string,mixed>  $mission
     * @param  array<string,mixed>  $invocation
     * @return array<int,array<string,mixed>>
     */
    private function evidence(array $candidate, array $resultPacket, array $mission, array $invocation): array
    {
        $evidence = is_array($candidate['evidence'] ?? null) ? array_values($candidate['evidence']) : [];
        $evidence[] = [
            'kind' => 'hermes_result_packet',
            'candidate_id' => $candidate['candidate_id'] ?? null,
            'result_id' => $resultPacket['result_id'] ?? null,
            'result_hash' => $resultPacket['result_hash'] ?? null,
            'mission_id' => $mission['mission_id'] ?? null,
            'mission_hash' => $mission['mission_hash'] ?? null,
            'cli_invocation_hash' => $this->hashValue($invocation),
            'promotion_allowed_now' => false,
        ];

        return array_values(array_filter($evidence, fn (mixed $item): bool => is_array($item)));
    }

    private function scope(AiJob $job): string
    {
        $workspace = $this->workspace($job);
        if ($workspace === null) {
            return 'hermes_runtime:global';
        }

        $scope = 'workspace:'.$workspace;

        return strlen($scope) <= 255 ? $scope : 'workspace_hash:'.hash('sha256', $workspace);
    }

    private function workspace(AiJob $job): ?string
    {
        $workspace = data_get($job->payload, 'workspace')
            ?: data_get($job->payload, 'tool_permissions.workspace')
            ?: data_get($job->metadata, 'workspace');

        if (! is_string($workspace) || trim($workspace) === '') {
            return null;
        }

        $workspace = trim($workspace);

        return realpath($workspace) ?: $workspace;
    }

    private function uuidOrNull(mixed $value): ?string
    {
        return is_string($value) && Str::isUuid($value) ? $value : null;
    }

    private function string(mixed $value, int $limit): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, $limit, '');
    }
}
