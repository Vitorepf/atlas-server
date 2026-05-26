<?php

declare(strict_types=1);

namespace App\Http\Controllers;

// Gap5.F2 wiring marker — Programming-adjacent controller.
// Canonical route_decision schema: atlas.dual_core.route_decision.v1

use App\Models\AtlasEngineeringEvidence;
use App\Models\AtlasProject;
use App\Services\Ai\Programming\AtlasForgeGovernedPromotionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Human review gate for the latest Atlas Code Forge run.
 *
 * Runtime, scope and tests can say a run is eligible; product completion still
 * needs an explicit operator review artifact bound to a concrete history entry.
 */
final class AtlasCodeForgeReviewController extends Controller
{
    public function store(Request $request, AtlasProject $project): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'string', 'in:approved,rejected'],
            'comment' => ['nullable', 'string', 'max:500'],
            'history_id' => ['nullable', 'string', 'max:120'],
        ]);

        $history = $this->historyEntry($project, (string) ($data['history_id'] ?? ''));
        $review = $this->reviewFor(
            $project,
            (string) $data['decision'],
            (string) ($data['comment'] ?? ''),
            $history,
        );
        $snapshot = $this->snapshotForHistoryEntry($project, $history);
        if ($review['approval_effective'] === true) {
            /** @var AtlasForgeGovernedPromotionService $promotionService */
            $promotionService = app(AtlasForgeGovernedPromotionService::class);
            $promotion = $promotionService->promote($project, $history, $snapshot ?? [], $review);
            $review['promotion'] = $promotion;

            if ($this->promotionRequired($snapshot) && ! $this->promotionSucceeded($promotion)) {
                $review = $this->blockReviewForPromotion($review, $promotion);
            }
        } elseif ((string) $data['decision'] === 'rejected') {
            $review['promotion'] = [
                'schema_version' => AtlasForgeGovernedPromotionService::SCHEMA_VERSION,
                'status' => 'not_requested',
                'promotion_status' => 'not_requested',
                'live_workspace_mutated' => false,
                'remaining_blockers' => [],
            ];
        }

        $evidence = $this->persistEvidence($project, $review);
        $review['evidence_id'] = $evidence?->id;
        $review['review_evidence_id'] = $evidence?->id;

        $this->rememberReview($project, $review);

        return response()->json([
            'schema_version' => 'atlas.code.forge_review_response.v1',
            'work_id' => (string) $project->getKey(),
            'review' => $review,
            'persistence' => [
                'project_metadata_persisted' => true,
                'engineering_evidence_id' => $evidence?->id,
                'engineering_evidence_persisted' => $evidence !== null,
            ],
        ], $review['status'] === 'blocked' ? 409 : 201);
    }

    public function rollback(Request $request, AtlasProject $project, string $promotionId): JsonResponse
    {
        $request->validate([
            'comment' => ['nullable', 'string', 'max:500'],
        ]);

        $review = $this->reviewForPromotion($project, $promotionId);
        if ($review === null) {
            return response()->json([
                'schema_version' => 'atlas.code.forge_promotion_rollback_response.v1',
                'work_id' => (string) $project->getKey(),
                'promotion_id' => $promotionId,
                'status' => 'missing',
                'error' => 'forge_promotion_not_found',
            ], 404);
        }

        $promotion = data_get($review, 'promotion');
        if (! is_array($promotion)) {
            return response()->json([
                'schema_version' => 'atlas.code.forge_promotion_rollback_response.v1',
                'work_id' => (string) $project->getKey(),
                'promotion_id' => $promotionId,
                'status' => 'blocked',
                'rollback' => [
                    'schema_version' => 'atlas.forge_governed_rollback.v1',
                    'status' => 'blocked',
                    'remaining_blockers' => ['promotion_artifact_missing_on_review'],
                ],
            ], 409);
        }

        /** @var AtlasForgeGovernedPromotionService $promotionService */
        $promotionService = app(AtlasForgeGovernedPromotionService::class);
        $rollback = $promotionService->rollback($project, $promotion, $review);

        if ((string) ($rollback['promotion_status'] ?? '') !== 'rolled_back') {
            return response()->json([
                'schema_version' => 'atlas.code.forge_promotion_rollback_response.v1',
                'work_id' => (string) $project->getKey(),
                'promotion_id' => $promotionId,
                'status' => 'blocked',
                'rollback' => $rollback,
            ], 409);
        }

        $review = $this->reviewRolledBack($review, $rollback);
        $this->rememberReview($project, $review);

        return response()->json([
            'schema_version' => 'atlas.code.forge_promotion_rollback_response.v1',
            'work_id' => (string) $project->getKey(),
            'promotion_id' => $promotionId,
            'status' => 'rolled_back',
            'review' => $review,
            'rollback' => $rollback,
            'persistence' => [
                'project_metadata_persisted' => true,
                'programming_evidence_persisted' => (bool) data_get($rollback, 'evidence.persisted', false),
            ],
        'route_decision' => \App\Services\Ai\DualCore\CanonicalRouteDecisionEnvelope::emit(route: 'programming', reason: 'http_atlas_code_forge_review_controller'),
    ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function reviewFor(AtlasProject $project, string $decision, string $comment, array $latest): array
    {
        $blockers = $latest === []
            ? ['forge_live_execution_history_missing']
            : array_values((array) ($latest['remaining_blockers'] ?? []));
        $completionAllowed = (bool) ($latest['completion_claim_allowed'] ?? false);
        $runPassed = (string) ($latest['status'] ?? 'missing') === 'passed';
        $digest = is_array(data_get($latest, 'evidence_pack_digest'))
            ? (array) data_get($latest, 'evidence_pack_digest')
            : [];

        if (! $runPassed) {
            $blockers[] = 'forge_run_not_passed';
        }
        if (! $completionAllowed) {
            $blockers[] = 'completion_gate_not_allowed';
        }

        $approvalEffective = $decision === 'approved' && $blockers === [];
        $status = match (true) {
            $approvalEffective => 'approved',
            $decision === 'rejected' => 'rejected',
            default => 'blocked',
        };

        return [
            'schema_version' => 'atlas.code.forge_review_artifact.v1',
            'review_id' => (string) Str::ulid(),
            'obra_id' => (string) $project->getKey(),
            'history_id' => (string) ($latest['history_id'] ?? ''),
            'run_id' => $latest['run_id'] ?? null,
            'run_evidence_id' => $latest['evidence_id'] ?? null,
            'decision' => $decision,
            'status' => $status,
            'reviewer_id' => 'local_operator',
            'reviewed_at' => now()->toJSON(),
            'comment' => $comment,
            'approval_effective' => $approvalEffective,
            'live_execution_status' => (string) ($latest['status'] ?? 'missing'),
            'completion_claim_allowed' => $completionAllowed,
            'final_completion_allowed' => $approvalEffective && $completionAllowed,
            'stage_receipt_count' => (int) data_get($digest, 'stage_receipt_count', 0),
            'ledger_event_count' => (int) data_get($digest, 'ledger_event_count', 0),
            'report_hash' => data_get($digest, 'report_hash'),
            'stage_timeline_hash' => data_get($digest, 'stage_timeline_hash'),
            'evidence_pack_hash' => data_get($digest, 'evidence_pack_hash'),
            'source_authority' => 'AtlasProject.metadata.atlas_code_forge_live_execution_history',
            'review_gate' => [
                'completion_claim_allowed' => $completionAllowed,
                'human_approved' => $approvalEffective,
                'final_completion_allowed' => $approvalEffective && $completionAllowed,
                'blockers' => array_values(array_unique($blockers)),
            ],
            'summary' => sprintf(
                'Forge review %s · run=%s · completion=%s',
                $status,
                (string) ($latest['history_id'] ?? 'missing'),
                ($approvalEffective && $completionAllowed) ? 'allowed' : 'blocked',
            ),
        ];
    }

    /**
     * @param  array<string,mixed>|null  $snapshot
     */
    private function promotionRequired(?array $snapshot): bool
    {
        return is_array(data_get($snapshot, 'governed_execution'))
            && (string) data_get($snapshot, 'governed_execution.execution_mode') === 'governed_shadow_patch';
    }

    /**
     * @param  array<string,mixed>  $promotion
     */
    private function promotionSucceeded(array $promotion): bool
    {
        return in_array((string) ($promotion['status'] ?? 'blocked'), ['promoted', 'already_promoted'], true)
            || (string) ($promotion['promotion_status'] ?? '') === 'promoted_to_workspace';
    }

    /**
     * @param  array<string,mixed>  $review
     * @param  array<string,mixed>  $promotion
     * @return array<string,mixed>
     */
    private function blockReviewForPromotion(array $review, array $promotion): array
    {
        $blockers = array_values(array_unique(array_merge(
            (array) data_get($review, 'review_gate.blockers', []),
            (array) ($promotion['remaining_blockers'] ?? ['forge_promotion_failed']),
        )));

        $review['status'] = 'blocked';
        $review['approval_effective'] = false;
        $review['final_completion_allowed'] = false;
        $review['review_gate']['human_approved'] = false;
        $review['review_gate']['final_completion_allowed'] = false;
        $review['review_gate']['blockers'] = $blockers;
        $review['summary'] = sprintf(
            'Forge review blocked · promotion=%s · blockers=%d',
            (string) ($promotion['status'] ?? 'blocked'),
            count($blockers),
        );

        return $review;
    }

    /**
     * @return array<string,mixed>
     */
    private function historyEntry(AtlasProject $project, string $historyId): array
    {
        $history = collect((array) data_get($project->metadata, 'atlas_code_forge_live_execution_history', []))
            ->filter(fn (mixed $entry): bool => is_array($entry));

        if ($historyId !== '') {
            $entry = $history->first(fn (array $entry): bool => (string) ($entry['history_id'] ?? '') === $historyId);

            return is_array($entry) ? $entry : [];
        }

        $entry = $history->first();

        return is_array($entry) ? $entry : [];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function reviewForPromotion(AtlasProject $project, string $promotionId): ?array
    {
        $latest = data_get($project->metadata, 'latest_atlas_code_forge_review');
        if (is_array($latest) && (string) data_get($latest, 'promotion.promotion_id') === $promotionId) {
            return $latest;
        }

        $history = collect((array) data_get($project->metadata, 'atlas_code_forge_review_history', []))
            ->filter(fn (mixed $entry): bool => is_array($entry));
        $review = $history->first(fn (array $entry): bool => (string) data_get($entry, 'promotion.promotion_id') === $promotionId);

        return is_array($review) ? $review : null;
    }

    /**
     * @param  array<string,mixed>  $review
     * @param  array<string,mixed>  $rollback
     * @return array<string,mixed>
     */
    private function reviewRolledBack(array $review, array $rollback): array
    {
        $review['status'] = 'rolled_back';
        $review['approval_effective'] = false;
        $review['final_completion_allowed'] = false;
        $review['reviewed_at'] = now()->toJSON();
        $review['promotion']['status'] = 'rolled_back';
        $review['promotion']['promotion_status'] = 'rolled_back';
        $review['promotion']['live_workspace_mutated'] = false;
        $review['promotion']['rollback_execution'] = $rollback;
        $review['rollback'] = $rollback;
        $review['review_gate']['final_completion_allowed'] = false;
        $review['review_gate']['blockers'] = array_values(array_unique(array_merge(
            (array) data_get($review, 'review_gate.blockers', []),
            ['promotion_rolled_back'],
        )));
        $review['summary'] = sprintf(
            'Forge review rolled back · promotion=%s · rollback=%s',
            (string) data_get($rollback, 'promotion_id', data_get($review, 'promotion.promotion_id', 'unknown')),
            (string) ($rollback['rollback_id'] ?? 'unknown'),
        );

        return $review;
    }

    /**
     * @param  array<string,mixed>  $review
     */
    private function rememberReview(AtlasProject $project, array $review): void
    {
        $metadata = is_array($project->metadata) ? $project->metadata : [];
        $history = array_values((array) ($metadata['atlas_code_forge_review_history'] ?? []));
        array_unshift($history, $review);
        $metadata['latest_atlas_code_forge_review'] = $review;
        $metadata['atlas_code_forge_review_history'] = array_slice($history, 0, 20);
        $this->mergePromotionIntoExecutionMetadata($metadata, $review);

        $project->forceFill([
            'metadata' => $metadata,
            'last_touched_at' => now(),
        ])->save();
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @param  array<string,mixed>  $review
     */
    private function mergePromotionIntoExecutionMetadata(array &$metadata, array $review): void
    {
        $promotion = data_get($review, 'promotion');
        if (! is_array($promotion)) {
            return;
        }

        $promotionDigest = [
            'schema_version' => $promotion['schema_version'] ?? AtlasForgeGovernedPromotionService::SCHEMA_VERSION,
            'promotion_id' => $promotion['promotion_id'] ?? null,
            'status' => $promotion['status'] ?? null,
            'promotion_status' => $promotion['promotion_status'] ?? null,
            'live_workspace_mutated' => (bool) ($promotion['live_workspace_mutated'] ?? false),
            'changed_files' => array_values((array) ($promotion['changed_files'] ?? [])),
            'rollback_available' => (bool) data_get($promotion, 'rollback.available', false),
            'evidence_id' => data_get($promotion, 'evidence.engineering_evidence_id'),
            'receipt_id' => data_get($promotion, 'evidence.receipt_id'),
            'rollback_id' => data_get($promotion, 'rollback_execution.rollback_id'),
            'rollback_evidence_id' => data_get($promotion, 'rollback_execution.evidence.engineering_evidence_id'),
        ];

        $historyId = (string) ($review['history_id'] ?? '');
        if (is_array($metadata['latest_forge_live_execution'] ?? null)
            && is_array(data_get($metadata, 'latest_forge_live_execution.governed_execution'))
        ) {
            $latest = $metadata['latest_forge_live_execution'];
            $latest['governed_execution']['promotion_status'] = $promotionDigest['promotion_status'];
            $latest['governed_execution']['live_workspace_mutated'] = $promotionDigest['live_workspace_mutated'];
            $latest['governed_execution']['promotion'] = $promotionDigest;
            $metadata['latest_forge_live_execution'] = $latest;
        }

        $runHistory = array_values((array) ($metadata['atlas_code_forge_live_execution_history'] ?? []));
        foreach ($runHistory as $index => $entry) {
            if (! is_array($entry) || (string) ($entry['history_id'] ?? '') !== $historyId) {
                continue;
            }
            $entry['promotion_status'] = $promotionDigest['promotion_status'];
            $entry['live_workspace_mutated'] = $promotionDigest['live_workspace_mutated'];
            $entry['promotion_id'] = $promotionDigest['promotion_id'];
            $entry['promotion_evidence_id'] = $promotionDigest['evidence_id'];
            $entry['promotion_receipt_id'] = $promotionDigest['receipt_id'];
            $entry['rollback_id'] = $promotionDigest['rollback_id'];
            $entry['rollback_evidence_id'] = $promotionDigest['rollback_evidence_id'];
            $runHistory[$index] = $entry;
        }
        $metadata['atlas_code_forge_live_execution_history'] = $runHistory;
    }

    /**
     * @param  array<string,mixed>  $historyEntry
     * @return array<string,mixed>|null
     */
    private function snapshotForHistoryEntry(AtlasProject $project, array $historyEntry): ?array
    {
        if ($historyEntry === []) {
            return null;
        }

        $latest = data_get($project->metadata, 'latest_forge_live_execution');
        if (is_array($latest)) {
            $latestHash = (string) data_get($latest, 'evidence_pack.integrity.evidence_pack_hash', '');
            $historyHash = (string) data_get($historyEntry, 'evidence_pack_digest.evidence_pack_hash', '');
            if ($latestHash !== '' && $latestHash === $historyHash) {
                return $latest;
            }
        }

        $evidenceId = $historyEntry['evidence_id'] ?? null;
        if (! $evidenceId
            || ! Schema::hasTable('atlas_engineering_evidence')
            || ! Schema::hasColumn('atlas_engineering_evidence', 'metadata')
        ) {
            return null;
        }

        $evidence = AtlasEngineeringEvidence::query()
            ->where('id', (string) $evidenceId)
            ->where('project_id', (string) $project->getKey())
            ->first();
        $snapshot = data_get($evidence?->metadata, 'snapshot');

        return is_array($snapshot) ? $snapshot : null;
    }

    /**
     * @param  array<string,mixed>  $review
     */
    private function persistEvidence(AtlasProject $project, array $review): ?AtlasEngineeringEvidence
    {
        if (! Schema::hasTable('atlas_engineering_evidence')) {
            return null;
        }

        $fields = $this->onlyExistingColumns('atlas_engineering_evidence', [
            'project_id' => (string) $project->getKey(),
            'task_id' => (string) $project->getKey(),
            'evidence_type' => 'atlas_code_forge_review',
            'target_id' => (string) ($review['review_id'] ?? $project->getKey()),
            'status' => (string) ($review['status'] ?? 'blocked'),
            'confidence' => (bool) ($review['approval_effective'] ?? false) ? 1.0 : 0.7,
            'summary' => (string) ($review['summary'] ?? 'Atlas Code Forge review'),
            'command' => 'POST /atlas-code/works/{obra}/forge/reviews',
            'output_excerpt' => (string) data_get($review, 'review_gate.final_completion_allowed', false),
            'files' => array_values((array) data_get($review, 'promotion.changed_files', [])),
            'metadata' => [
                'schema_version' => 'atlas.code.forge_review_evidence.v1',
                'review' => $review,
            ],
            'source' => 'atlas_code_forge_review',
            'recorded_at' => now(),
        ]);

        return AtlasEngineeringEvidence::query()->create($fields);
    }

    /**
     * @param  array<string,mixed>  $values
     * @return array<string,mixed>
     */
    private function onlyExistingColumns(string $table, array $values): array
    {
        return collect($values)
            ->filter(fn (mixed $_, string $column): bool => Schema::hasColumn($table, $column))
            ->all();
    }
}
