<?php

namespace App\Services\Ai\Compounding;

use App\Models\AiLearningProposal;
use InvalidArgumentException;

/**
 * Canonical proposal-only entrypoint for compounding learning that touches
 * policy, routing, gate, benchmark, retrieval hints, memory or heuristics.
 *
 * Per atlas-compounding-engineering-intelligence.md:258-267 critical changes
 * never auto-mutate. They are always materialised here in `proposed` status
 * awaiting human review.
 */
class AtlasLearningProposalService
{
    public const SCHEMA_VERSION = 'atlas.ai.compounding.learning_proposal.v1';

    public const CRITICAL_KINDS = [
        'policy',
        'routing',
        'gate',
        'benchmark',
        'heuristic',
    ];

    public const ALLOWED_KINDS = [
        'policy',
        'routing',
        'gate',
        'benchmark',
        'heuristic',
        'retrieval_hint',
        'memory',
        'failure_pattern',
    ];

    /**
     * Always materialises a proposal with status='proposed'. Any `apply` or
     * `auto_apply` field in the input is rejected — critical learning cannot
     * auto-apply.
     *
     * @param  array<string,mixed>  $input
     */
    public function propose(array $input): AiLearningProposal
    {
        $kind = $this->string($input['kind'] ?? null);
        if ($kind === null || ! in_array($kind, self::ALLOWED_KINDS, true)) {
            throw new InvalidArgumentException('learning_proposal_requires_valid_kind');
        }

        $summary = $this->string($input['summary'] ?? null);
        if ($summary === null) {
            throw new InvalidArgumentException('learning_proposal_requires_summary');
        }

        $evidenceRefs = $this->array($input['evidence_refs'] ?? []);
        if ($evidenceRefs === []) {
            throw new InvalidArgumentException('learning_proposal_requires_evidence_refs');
        }

        if (array_key_exists('apply', $input) || array_key_exists('auto_apply', $input)) {
            $autoApply = (bool) ($input['apply'] ?? $input['auto_apply'] ?? false);
            if ($autoApply) {
                throw new InvalidArgumentException('learning_proposal_cannot_auto_apply');
            }
        }

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'kind' => $kind,
            'status' => 'proposed',
            'scope' => $this->string($input['scope'] ?? null) ?? 'global',
            'flow_id' => $this->string($input['flow_id'] ?? null),
            'summary' => $summary,
            'current_state' => $this->array($input['current_state'] ?? []),
            'proposed_state' => $this->array($input['proposed_state'] ?? []),
            'evidence_refs' => $evidenceRefs,
            'run_outcome_id' => $this->string($input['run_outcome_id'] ?? null),
            'learning_candidate_id' => $this->string($input['learning_candidate_id'] ?? null),
            'rag_feedback_id' => $this->string($input['rag_feedback_id'] ?? null),
            'requires_human_review' => true,
            'decided_by' => null,
            'decided_at' => null,
            'decision_notes' => null,
            'payload' => $this->array($input['payload'] ?? []),
        ];
        $payload['proposal_hash'] = CompoundingHash::make([
            'schema' => self::SCHEMA_VERSION,
            'kind' => $payload['kind'],
            'scope' => $payload['scope'],
            'flow_id' => $payload['flow_id'],
            'summary' => $payload['summary'],
            'current_state' => $payload['current_state'],
            'proposed_state' => $payload['proposed_state'],
            'evidence_refs' => $payload['evidence_refs'],
        ]);

        return AiLearningProposal::query()->firstOrCreate(
            ['proposal_hash' => $payload['proposal_hash']],
            $payload,
        );
    }

    public function approve(AiLearningProposal $proposal, string $by, ?string $notes = null): AiLearningProposal
    {
        if (! in_array($proposal->status, ['proposed', 'under_review'], true)) {
            throw new InvalidArgumentException('learning_proposal_only_proposed_or_under_review_can_be_approved');
        }

        $proposal->forceFill([
            'status' => 'approved',
            'decided_by' => $by,
            'decided_at' => now(),
            'decision_notes' => $notes,
        ])->save();

        return $proposal->refresh();
    }

    public function reject(AiLearningProposal $proposal, string $by, ?string $notes = null): AiLearningProposal
    {
        if (! in_array($proposal->status, ['proposed', 'under_review'], true)) {
            throw new InvalidArgumentException('learning_proposal_only_proposed_or_under_review_can_be_rejected');
        }

        $proposal->forceFill([
            'status' => 'rejected',
            'decided_by' => $by,
            'decided_at' => now(),
            'decision_notes' => $notes,
        ])->save();

        return $proposal->refresh();
    }

    public function markApplied(AiLearningProposal $proposal): AiLearningProposal
    {
        if ($proposal->status !== 'approved') {
            throw new InvalidArgumentException('learning_proposal_must_be_approved_before_applied');
        }

        $proposal->forceFill(['status' => 'applied'])->save();

        return $proposal->refresh();
    }

    public static function isCriticalKind(string $kind): bool
    {
        return in_array($kind, self::CRITICAL_KINDS, true);
    }

    /**
     * Derive the proposal kind from a heuristic key. Returns null when the
     * key does not match a critical prefix.
     */
    public static function kindForHeuristicKey(string $heuristicKey): ?string
    {
        $key = strtolower(trim($heuristicKey));

        return match (true) {
            str_starts_with($key, 'policy.') => 'policy',
            str_starts_with($key, 'router.'), str_starts_with($key, 'routing.') => 'routing',
            str_starts_with($key, 'gate.') => 'gate',
            str_starts_with($key, 'benchmark.') => 'benchmark',
            default => null,
        };
    }

    /**
     * @return array<int|string,mixed>
     */
    private function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function string(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
