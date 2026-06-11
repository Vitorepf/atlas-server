<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\ContinuationPack;

use App\Models\AtlasDevRunIndex;
use App\Models\AtlasLongHorizonContinuationPack;
use App\Models\AtlasProgrammingStageReceipt;
use App\Services\Ai\LongHorizon\AtlasLongHorizonCanon;
use App\Services\Ai\Programming\AtlasDev\Support\AtlasDevStringListNormalizer;
use App\Services\Ai\Programming\AtlasDev\Support\AtlasDevValueNormalizer;
use App\Services\Ai\Support\DatabaseTableAvailability;
use InvalidArgumentException;

/**
 * Atlas Dev Continuation Pack builder.
 *
 * Composes `atlas.long_horizon.continuation_pack.v2` payloads for an Atlas
 * Dev run/session/workstream so the work can be picked up days/weeks later
 * without depending on raw chat history. The builder is reuse-first: it
 * reads from `AtlasDevRunIndex` (routing/risk/completion shape) and
 * `AtlasProgrammingStageReceipt` (stage timeline + evidence refs) — both
 * already canonical. NO call to provider, NO mutation of upstream tables,
 * NO replacement of `ProgrammingResumeService`.
 *
 * The pack is persisted via {@see AtlasLongHorizonContinuationPack} with a
 * deterministic `pack_hash`. Operators / Desktop / CLI consume the row to
 * resume the run; that consumption surface lives in later TEOS-I1 missions.
 */
final class DevContinuationPackBuilder
{
    public function __construct() {}

    /**
     * @param  array<string,mixed>  $options
     */
    public function build(string $runId, array $options = []): AtlasLongHorizonContinuationPack
    {
        $runId = trim($runId);
        if ($runId === '') {
            throw new InvalidArgumentException('DevContinuationPackBuilder: run_id must not be empty.');
        }

        $runIndex = $this->loadRunIndex($runId);
        $receipts = $this->loadReceipts($runId);

        $scopeType = $this->resolveScopeType((string) ($options['scope_type'] ?? AtlasLongHorizonCanon::SCOPE_TYPE_DEV_SESSION));
        $objective = $this->stringOrFallback($options['objective'] ?? null, $this->fallbackObjective($runIndex, $receipts));
        $stateSummary = $this->stringOrFallback($options['state_summary'] ?? null, $this->fallbackStateSummary($runIndex, $receipts));

        $stageRollup = $this->rollupStages($receipts);
        $evidenceRefs = $this->collectEvidenceRefs($runIndex, $receipts, (array) ($options['evidence_refs'] ?? []));
        $contextManifest = $this->buildContextManifest($receipts);
        $sourceReceipts = array_map(static fn (AtlasProgrammingStageReceipt $r): string => 'stage_receipt:'.$r->receipt_id, $receipts);

        $decisions = array_values(array_filter(
            (array) ($options['decisions'] ?? []),
            static fn ($v): bool => is_string($v) && trim($v) !== '',
        ));
        $supersededDecisions = array_values(array_filter(
            (array) ($options['superseded_decisions'] ?? []),
            static fn ($v): bool => is_string($v) && trim($v) !== '',
        ));
        $blockers = array_values(array_filter(
            array_merge($stageRollup['blockers'], (array) ($options['blockers'] ?? [])),
            static fn ($v): bool => is_array($v) || (is_string($v) && trim($v) !== ''),
        ));
        $risks = array_values(array_filter(
            (array) ($options['risks'] ?? []),
            static fn ($v): bool => is_string($v) && trim($v) !== '',
        ));
        $humanDecisions = array_values((array) ($options['human_decisions_required'] ?? []));

        $contextPackHash = AtlasDevValueNormalizer::stringOrNull($options['context_pack_hash'] ?? null);
        $confidence = $this->floatInRangeOrNull($options['confidence'] ?? null);
        $staleAfter = $options['stale_after'] ?? now()->addDays(7);

        $safeResumeMode = $this->resolveSafeResumeMode(
            runIndex: $runIndex,
            stageRollup: $stageRollup,
            receiptCount: count($receipts),
            humanDecisions: $humanDecisions,
            blockers: $blockers,
            options: $options,
        );

        $nextSafeAction = $this->resolveNextSafeAction($safeResumeMode, $stageRollup);

        $summary = $stateSummary;
        $summaryHash = hash('sha256', $summary);

        $payload = [
            'uuid' => 'devpack-'.bin2hex(random_bytes(8)),
            'schema_version' => AtlasLongHorizonCanon::CONTINUATION_PACK_SCHEMA_VERSION,
            'scope_type' => $scopeType,
            'scope_id' => $runId,
            'objective' => $objective,
            'current_phase' => $stageRollup['current_phase'],
            'state_summary' => $summary,
            'decisions' => $decisions,
            'superseded_decisions' => $supersededDecisions,
            'open_tasks' => $stageRollup['open_tasks'],
            'completed_tasks' => $stageRollup['completed_tasks'],
            'blockers' => $blockers,
            'risks' => $risks,
            'evidence_refs' => $evidenceRefs,
            'context_manifest' => $contextManifest,
            'context_pack_hash' => $contextPackHash,
            'summary_hash' => $summaryHash,
            'source_receipts' => $sourceReceipts,
            'stale_after' => $staleAfter,
            'safe_resume_mode' => $safeResumeMode,
            'next_safe_action' => $nextSafeAction,
            'human_decisions_required' => $humanDecisions,
            'confidence' => $confidence,
        ];

        $payload['pack_hash'] = AtlasLongHorizonContinuationPack::canonicalPackHash($payload);

        return AtlasLongHorizonContinuationPack::query()->create($payload);
    }

    private function loadRunIndex(string $runId): ?AtlasDevRunIndex
    {
        if (! DatabaseTableAvailability::has('atlas_dev_run_index')) {
            return null;
        }

        return AtlasDevRunIndex::query()->whereKey($runId)->first();
    }

    /**
     * @return array<int,AtlasProgrammingStageReceipt>
     */
    private function loadReceipts(string $runId): array
    {
        if (! DatabaseTableAvailability::has('atlas_programming_stage_receipts')) {
            return [];
        }

        return AtlasProgrammingStageReceipt::query()
            ->where(function ($q) use ($runId): void {
                $q->where('plan_id', $runId)->orWhere('parent_plan_id', $runId);
            })
            ->orderBy('created_at')
            ->orderBy('attempt')
            ->get()
            ->all();
    }

    /**
     * @param  array<int,AtlasProgrammingStageReceipt>  $receipts
     * @return array<string,mixed>
     */
    private function rollupStages(array $receipts): array
    {
        $stageOrder = ['plan', 'review', 'patch', 'test', 'repair', 'finalize'];
        $statusByStage = [];
        foreach ($receipts as $r) {
            $statusByStage[(string) $r->stage] = (string) $r->status;
        }

        $completed = [];
        $open = [];
        $blockers = [];
        $currentPhase = null;

        foreach ($stageOrder as $stage) {
            $status = $statusByStage[$stage] ?? null;
            if ($status === null) {
                $open[] = $stage;

                continue;
            }
            if (in_array($status, ['passed', 'completed', 'ok'], true)) {
                $completed[] = $stage;
                $currentPhase = $stage;

                continue;
            }
            if (in_array($status, ['failed', 'blocked'], true)) {
                $blockers[] = [
                    'kind' => 'stage_'.$status,
                    'stage' => $stage,
                    'description' => "Stage {$stage} reported status={$status}; review the latest stage receipt before resuming.",
                ];
                $currentPhase = $stage;
            }
            if (in_array($status, ['pending', 'running'], true)) {
                $open[] = $stage;
                $currentPhase = $stage;
            }
        }

        return [
            'completed_tasks' => $completed,
            'open_tasks' => $open,
            'blockers' => $blockers,
            'current_phase' => $currentPhase,
            'status_by_stage' => $statusByStage,
        ];
    }

    /**
     * @param  array<int,AtlasProgrammingStageReceipt>  $receipts
     * @param  array<int,string>  $additional
     * @return list<string>
     */
    private function collectEvidenceRefs(?AtlasDevRunIndex $runIndex, array $receipts, array $additional): array
    {
        $refs = [];
        if ($runIndex !== null) {
            $refs[] = 'atlas_dev_run_index:'.$runIndex->run_id;
            if (! empty($runIndex->last_receipt_hash)) {
                $refs[] = 'last_receipt_hash:'.$runIndex->last_receipt_hash;
            }
        }
        foreach ($receipts as $r) {
            $refs[] = 'stage_receipt:'.$r->receipt_id;
            foreach ((array) ($r->evidence_refs_json ?? []) as $extra) {
                if (is_string($extra) && trim($extra) !== '') {
                    $refs[] = $extra;
                }
            }
        }
        foreach ($additional as $extra) {
            if (is_string($extra) && trim($extra) !== '') {
                $refs[] = $extra;
            }
        }

        return AtlasDevStringListNormalizer::uniqueStrings($refs);
    }

    /**
     * @param  array<int,AtlasProgrammingStageReceipt>  $receipts
     * @return list<array<string,mixed>>
     */
    private function buildContextManifest(array $receipts): array
    {
        $manifest = [];
        foreach ($receipts as $r) {
            $manifest[] = [
                'ref' => 'stage_receipt:'.$r->receipt_id,
                'stage' => (string) $r->stage,
                'status' => (string) $r->status,
                'attempt' => (int) $r->attempt,
            ];
        }

        return $manifest;
    }

    /**
     * @param  array<string,mixed>  $stageRollup
     * @param  list<array<string,mixed>>  $humanDecisions
     * @param  list<array<string,mixed>|string>  $blockers
     * @param  array<string,mixed>  $options
     */
    private function resolveSafeResumeMode(
        ?AtlasDevRunIndex $runIndex,
        array $stageRollup,
        int $receiptCount,
        array $humanDecisions,
        array $blockers,
        array $options,
    ): string {
        if ($runIndex === null) {
            return AtlasLongHorizonCanon::SAFE_RESUME_BLOCKED;
        }

        $riskLevel = strtoupper((string) ($runIndex->risk_level ?? ''));
        if (in_array($riskLevel, ['R4', 'R5'], true)) {
            return AtlasLongHorizonCanon::SAFE_RESUME_ESCALATE_TO_FORGE;
        }

        $completionState = (string) ($runIndex->completion_state ?? '');
        if ($completionState === 'blocked' || $completionState === 'failed') {
            return $completionState === 'failed'
                ? AtlasLongHorizonCanon::SAFE_RESUME_REPAIR
                : AtlasLongHorizonCanon::SAFE_RESUME_BLOCKED;
        }

        if ($humanDecisions !== []) {
            return AtlasLongHorizonCanon::SAFE_RESUME_ASK_HUMAN;
        }

        foreach ($blockers as $blocker) {
            $kind = is_array($blocker) ? (string) ($blocker['kind'] ?? '') : '';
            if (str_starts_with($kind, 'stage_failed')) {
                return AtlasLongHorizonCanon::SAFE_RESUME_REPAIR;
            }
            if (str_starts_with($kind, 'stage_blocked')) {
                return AtlasLongHorizonCanon::SAFE_RESUME_BLOCKED;
            }
        }

        // No stage receipts means no first-party operational evidence has been
        // captured for this run yet — the run_index pointer alone is metadata,
        // not evidence. Caller can only consume the pack read-only until at
        // least one stage receipt lands.
        if ($receiptCount === 0) {
            return AtlasLongHorizonCanon::SAFE_RESUME_READ_ONLY;
        }

        $needsReview = (bool) ($options['needs_review'] ?? false);
        if ($needsReview) {
            return AtlasLongHorizonCanon::SAFE_RESUME_REVIEW;
        }

        return AtlasLongHorizonCanon::SAFE_RESUME_EXECUTE;
    }

    /**
     * @param  array<string,mixed>  $stageRollup
     */
    private function resolveNextSafeAction(string $mode, array $stageRollup): string
    {
        return match ($mode) {
            AtlasLongHorizonCanon::SAFE_RESUME_EXECUTE => 'continue_with_next_stage',
            AtlasLongHorizonCanon::SAFE_RESUME_REPAIR => 'rerun_failed_stage_with_repair_loop',
            AtlasLongHorizonCanon::SAFE_RESUME_REVIEW => 'open_review_for_latest_stage',
            AtlasLongHorizonCanon::SAFE_RESUME_ASK_HUMAN => 'await_human_decision_on_open_questions',
            AtlasLongHorizonCanon::SAFE_RESUME_BLOCKED => 'resolve_blocker_before_resume',
            AtlasLongHorizonCanon::SAFE_RESUME_ESCALATE_TO_FORGE => 'emit_dev_to_forge_escalation_packet',
            default => 'consume_pack_in_read_only_mode',
        };
    }

    /**
     * @param  array<int,AtlasProgrammingStageReceipt>  $receipts
     */
    private function fallbackObjective(?AtlasDevRunIndex $runIndex, array $receipts): string
    {
        if ($runIndex !== null && ! empty($runIndex->task_kind)) {
            return sprintf('atlas_dev:%s:%s', (string) $runIndex->task_kind, (string) $runIndex->run_id);
        }
        if ($receipts !== []) {
            return 'atlas_dev:resume:'.(string) $receipts[0]->plan_id;
        }

        return 'atlas_dev:resume:unknown';
    }

    /**
     * @param  array<int,AtlasProgrammingStageReceipt>  $receipts
     */
    private function fallbackStateSummary(?AtlasDevRunIndex $runIndex, array $receipts): string
    {
        if ($receipts === []) {
            return $runIndex !== null
                ? sprintf(
                    'Dev run %s recorded in run index (task_kind=%s, completion_state=%s) without stage receipts yet.',
                    $runIndex->run_id,
                    (string) $runIndex->task_kind,
                    (string) $runIndex->completion_state,
                )
                : 'No Dev run state available; pack issued in read-only mode for audit purposes.';
        }
        $count = count($receipts);
        $last = end($receipts);

        return sprintf(
            'Dev run carries %d stage receipt(s); latest stage=%s status=%s. Pack reconstructs state without provider chat.',
            $count,
            $last instanceof AtlasProgrammingStageReceipt ? (string) $last->stage : 'unknown',
            $last instanceof AtlasProgrammingStageReceipt ? (string) $last->status : 'unknown',
        );
    }

    private function resolveScopeType(string $candidate): string
    {
        $allowedDev = [
            AtlasLongHorizonCanon::SCOPE_TYPE_DEV_RUN,
            AtlasLongHorizonCanon::SCOPE_TYPE_DEV_SESSION,
            AtlasLongHorizonCanon::SCOPE_TYPE_DEV_WORKSTREAM,
        ];

        return in_array($candidate, $allowedDev, true)
            ? $candidate
            : AtlasLongHorizonCanon::SCOPE_TYPE_DEV_SESSION;
    }

    private function stringOrFallback(mixed $value, string $fallback): string
    {
        $string = AtlasDevValueNormalizer::stringOrNull($value);

        return $string ?? $fallback;
    }

    private function floatInRangeOrNull(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }
        $float = (float) $value;
        if ($float < 0.0 || $float > 1.0) {
            return null;
        }

        return $float;
    }
}
