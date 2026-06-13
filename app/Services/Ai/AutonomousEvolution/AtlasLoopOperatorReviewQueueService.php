<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Models\AtlasLoopProposal;
use App\Models\AtlasLoopTarget;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Facades\DB;

/**
 * L4-7 · Operator review queue for parked Loop proposals.
 *
 * The auto-merger parks forbidden self-target proposals instead of silently dropping
 * them. This service exposes that queue and records explicit approve/reject decisions.
 */
final class AtlasLoopOperatorReviewQueueService
{
    public const SCHEMA_VERSION = 'atlas.loop.operator_review_queue.v1';

    public function __construct(
        private readonly AtlasLoopHarnessGuard $guard,
        private readonly AtlasLoopAutoMergeService $autoMerge,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function queue(int $limit = 10): array
    {
        if (! DatabaseTableAvailability::has('atlas_loop_proposals')) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'table_missing',
                'items' => [],
                'count' => 0,
            ];
        }

        $items = [];
        $rows = AtlasLoopProposal::query()
            ->where('merged_to_main', false)
            ->whereNotNull('reviewed_at')
            ->orderByDesc('reviewed_at')
            ->limit(max(25, $limit * 5))
            ->get();
        foreach ($rows as $proposal) {
            if (! $this->isQueued($proposal)) {
                continue;
            }
            $items[] = $this->present($proposal);
            if (count($items) >= max(1, $limit)) {
                break;
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'ok',
            'items' => $items,
            'count' => count($items),
        ];
    }

    /**
     * @param  array{operator_id?:string,reason?:string,base_path?:string,approved?:bool}  $options
     * @return array<string,mixed>
     */
    public function approve(string $proposalRef, array $options): array
    {
        $proposal = $this->find($proposalRef);
        if (! $proposal instanceof AtlasLoopProposal) {
            return $this->decision('not_found', null, 'proposal_not_found');
        }
        if (! $this->isQueued($proposal)) {
            return $this->decision('blocked', $proposal, 'proposal_not_in_operator_review_queue');
        }

        $result = $this->autoMerge->mergeOperatorApproved($proposal, (string) ($options['base_path'] ?? base_path()), [
            'operator_id' => (string) ($options['operator_id'] ?? 'operator'),
            'approved' => (bool) ($options['approved'] ?? false),
            'reason' => (string) ($options['reason'] ?? 'operator approved parked proposal'),
        ]);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => ($result['status'] ?? null) === 'merged' ? 'merged' : 'blocked',
            'proposal' => $this->present($proposal->fresh() ?? $proposal),
            'merge' => $result,
        ];
    }

    /**
     * @param  array{operator_id?:string,reason?:string}  $options
     * @return array<string,mixed>
     */
    public function reject(string $proposalRef, array $options): array
    {
        $proposal = $this->find($proposalRef);
        if (! $proposal instanceof AtlasLoopProposal) {
            return $this->decision('not_found', null, 'proposal_not_found');
        }
        if (! $this->isQueued($proposal)) {
            return $this->decision('blocked', $proposal, 'proposal_not_in_operator_review_queue');
        }

        $operator = trim((string) ($options['operator_id'] ?? 'operator')) ?: 'operator';
        $reason = trim((string) ($options['reason'] ?? 'operator rejected parked proposal')) ?: 'operator rejected parked proposal';
        $quality = is_array($proposal->quality) ? $proposal->quality : [];
        $quality['_operator_review'] = [
            'schema_version' => 'atlas.loop.operator_review.v1',
            'status' => 'rejected',
            'reason' => $reason,
            'operator_id' => $operator,
            'reviewed_at' => now()->toIso8601String(),
            'decision' => 'reject_and_retire',
        ];
        $quality['_ranking_feedback'] = [
            'schema_version' => 'atlas.loop.operator_review_ranking_feedback.v1',
            'action' => 'downrank_or_quarantine',
            'reason' => $reason,
            'target_path' => (string) $proposal->target_path,
            'recorded_at' => now()->toIso8601String(),
        ];
        $proposal->forceFill(['reviewed_at' => now(), 'quality' => $quality])->save();
        $this->quarantineTarget($proposal, $reason);

        return $this->decision('rejected', $proposal->fresh() ?? $proposal, $reason);
    }

    /**
     * @return array<string,mixed>
     */
    private function decision(string $status, ?AtlasLoopProposal $proposal, ?string $reason): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'reason' => $reason,
            'proposal' => $proposal instanceof AtlasLoopProposal ? $this->present($proposal) : null,
        ];
    }

    private function isQueued(AtlasLoopProposal $proposal): bool
    {
        $review = is_array($proposal->quality) ? ($proposal->quality['_operator_review'] ?? []) : [];
        $status = is_array($review) ? (string) ($review['status'] ?? '') : '';
        if (in_array($status, ['rejected', 'merged'], true)) {
            return false;
        }

        return in_array($status, ['parked_for_operator_review', 'approval_failed'], true)
            || ($proposal->reviewed_at !== null && $this->guard->isForbiddenSelfTarget((string) $proposal->target_path));
    }

    /**
     * @return array<string,mixed>
     */
    private function present(AtlasLoopProposal $proposal): array
    {
        $review = is_array($proposal->quality) ? (array) ($proposal->quality['_operator_review'] ?? []) : [];
        $reason = (string) ($review['reason'] ?? ($this->guard->isForbiddenSelfTarget((string) $proposal->target_path) ? 'forbidden_self_target' : 'operator_review'));

        return [
            'id' => (string) $proposal->getKey(),
            'proposal_hash' => (string) $proposal->proposal_hash,
            'campaign_id' => (string) $proposal->campaign_id,
            'target_path' => (string) $proposal->target_path,
            'objective' => (string) $proposal->objective,
            'reason' => $reason,
            'review_status' => (string) ($review['status'] ?? 'parked_for_operator_review'),
            'merged_to_main' => (bool) $proposal->merged_to_main,
            'reviewed_at' => $proposal->reviewed_at?->toIso8601String(),
            'diff_excerpt' => $this->diffExcerpt((string) $proposal->diff_text),
        ];
    }

    private function find(string $proposalRef): ?AtlasLoopProposal
    {
        $proposalRef = trim($proposalRef);
        if ($proposalRef === '') {
            return null;
        }

        return AtlasLoopProposal::query()
            ->whereKey($proposalRef)
            ->orWhere('proposal_hash', $proposalRef)
            ->orWhere('proposal_hash', 'like', $proposalRef.'%')
            ->first();
    }

    private function quarantineTarget(AtlasLoopProposal $proposal, string $reason): void
    {
        if (! DatabaseTableAvailability::has('atlas_loop_targets')) {
            return;
        }

        DB::table('atlas_loop_targets')
            ->where('campaign_id', (string) $proposal->campaign_id)
            ->where('target_path', (string) $proposal->target_path)
            ->update([
                'status' => AtlasLoopTarget::STATUS_QUARANTINED,
                'reason' => mb_substr('operator_review_rejected:'.$reason, 0, 160),
                'updated_at' => now(),
            ]);
    }

    private function diffExcerpt(string $diff): string
    {
        $lines = array_slice(preg_split('/\R/', trim($diff)) ?: [], 0, 80);

        return mb_substr(implode("\n", $lines), 0, 8000);
    }
}
