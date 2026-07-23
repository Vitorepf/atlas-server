<?php

declare(strict_types=1);

namespace App\Services\Ai\Compaction;

use App\Models\AiCompaction;
use App\Models\AtlasL2HierarchicalSummary;
use App\Models\AtlasLongHorizonCompactionReceipt;
use App\Services\Ai\AgenticEngineeringOs\Scoring\SummaryFidelityCoverageScorer;
use App\Services\Ai\LongHorizon\AtlasLongHorizonCanon;
use App\Services\Ai\Support\AppendOnlyJsonlStore;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Facades\DB;

final class VerifiedL2HierarchicalSummaryService
{
    public function __construct(
        private readonly ?SummaryFidelityCoverageScorer $scorer = null,
    ) {}

    public function generateForCompaction(string $compactionId): ?AtlasL2HierarchicalSummary
    {
        if (! DatabaseTableAvailability::has('atlas_l2_hierarchical_summaries')) {
            return null;
        }

        /** @var AiCompaction|null $compaction */
        $compaction = AiCompaction::query()->find($compactionId);
        if (! $compaction instanceof AiCompaction) {
            return null;
        }

        return DB::transaction(function () use ($compaction): AtlasL2HierarchicalSummary {
            /** @var AiCompaction $locked */
            $locked = AiCompaction::query()
                ->whereKey($compaction->id)
                ->lockForUpdate()
                ->firstOrFail();

            $receipt = $this->l1ReceiptFor($locked);
            $requiredItems = $this->requiredItemsFrom($receipt);
            $l1Summary = (string) $locked->summary;
            $l1Hash = hash('sha256', $l1Summary);
            $l1Retention = $receipt?->context_retention_score;
            if ($l1Retention === null) {
                $l1Retention = (float) $this->scorer()->score($requiredItems, $l1Summary)['context_retention_score'];
            }

            $rewrite = $this->rewriter()->rewrite($l1Summary, [
                'compaction_id' => (string) $locked->id,
                'thread_id' => (string) $locked->thread_id,
                'required_items' => $requiredItems,
                'l1_summary_hash' => $l1Hash,
                'l1_context_retention_score' => $l1Retention,
            ]);
            $candidate = trim((string) ($rewrite['summary'] ?? ''));
            $runtime = trim((string) ($rewrite['runtime'] ?? 'local-l2-rewriter'));
            if ($runtime === '') {
                $runtime = 'local-l2-rewriter';
            }

            $score = $this->scorer()->score($requiredItems, $candidate);
            $coverage = (float) $score['context_retention_score'];
            $retention = (float) $score['context_retention_score'];
            $ratio = $l1Summary === ''
                ? null
                : round(mb_strlen($candidate) / max(1, mb_strlen($l1Summary)), 4);
            $rejectionReasons = $this->rejectionReasons($coverage, $retention, (float) $l1Retention, $receipt, $candidate);
            $status = $rejectionReasons === [] ? 'accepted' : 'rejected';
            $version = $this->nextVersion((string) $locked->id);

            $row = AtlasL2HierarchicalSummary::query()->create([
                'compaction_id' => (string) $locked->id,
                'thread_id' => (string) $locked->thread_id,
                'version' => $version,
                'status' => $status,
                'local_runtime' => $runtime,
                'l1_summary_hash' => $l1Hash,
                'l2_summary_hash' => $candidate === '' ? null : hash('sha256', $candidate),
                'l2_summary' => $candidate === '' ? null : $candidate,
                'coverage_score' => $coverage,
                'l1_context_retention_score' => (float) $l1Retention,
                'l2_context_retention_score' => $retention,
                'compression_ratio' => $ratio,
                'required_items' => $requiredItems,
                'scorer_report' => $score,
                'rejection_reasons' => $rejectionReasons,
                'metadata' => [
                    'schema_version' => 'atlas.compaction.l2_hierarchical_summary.v1',
                    'local_only' => true,
                    'provider_invoked' => false,
                    'source_compaction_receipt_id' => $receipt?->id,
                    'source_compaction_receipt_hash' => $receipt?->receipt_hash,
                    'canonical_l1_summary_hash' => $l1Hash,
                    'rewriter_metadata' => is_array($rewrite['metadata'] ?? null) ? $rewrite['metadata'] : [],
                ],
            ]);

            $this->recordEvidence($row, $locked);

            return $row;
        });
    }

    /**
     * @return array{source:string,summary:string,l2_summary_id:?string}
     */
    public function summaryForReading(AiCompaction $compaction): array
    {
        if (! (bool) config('atlas.compaction.l2_hierarchical_summary_consumption_enabled', false)) {
            return [
                'source' => 'l1_canonical',
                'summary' => (string) $compaction->summary,
                'l2_summary_id' => null,
            ];
        }

        $row = AtlasL2HierarchicalSummary::query()
            ->where('compaction_id', $compaction->id)
            ->where('status', 'accepted')
            ->latest('version')
            ->first();

        if (! $row instanceof AtlasL2HierarchicalSummary || trim((string) $row->l2_summary) === '') {
            return [
                'source' => 'l1_canonical',
                'summary' => (string) $compaction->summary,
                'l2_summary_id' => null,
            ];
        }

        return [
            'source' => 'l2_hierarchical_verified',
            'summary' => (string) $row->l2_summary,
            'l2_summary_id' => (string) $row->id,
        ];
    }

    private function l1ReceiptFor(AiCompaction $compaction): ?AtlasLongHorizonCompactionReceipt
    {
        return AtlasLongHorizonCompactionReceipt::query()
            ->where('scope_type', AtlasLongHorizonCanon::SCOPE_TYPE_CONVERSATION)
            ->where('scope_id', (string) $compaction->thread_id)
            ->where('summary_hash', hash('sha256', (string) $compaction->summary))
            ->latest('created_at')
            ->first();
    }

    /**
     * @return list<array{id:string,kind:string,digest:?string}>
     */
    private function requiredItemsFrom(?AtlasLongHorizonCompactionReceipt $receipt): array
    {
        if (! $receipt instanceof AtlasLongHorizonCompactionReceipt) {
            return [];
        }

        $items = [];
        foreach ((array) $receipt->must_keep_items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $id = trim((string) ($item['id'] ?? ''));
            $kind = trim((string) ($item['kind'] ?? 'fact'));
            if ($id === '') {
                continue;
            }

            $digest = $item['digest'] ?? null;
            $items[] = [
                'id' => $id,
                'kind' => $kind === '' ? 'fact' : $kind,
                'digest' => is_string($digest) && trim($digest) !== '' ? $digest : null,
            ];
        }

        return $items;
    }

    /**
     * @return list<string>
     */
    private function rejectionReasons(
        float $coverage,
        float $retention,
        float $l1Retention,
        ?AtlasLongHorizonCompactionReceipt $receipt,
        string $candidate,
    ): array {
        $reasons = [];
        if (! $receipt instanceof AtlasLongHorizonCompactionReceipt) {
            $reasons[] = 'missing_l1_receipt';
        }
        if (trim($candidate) === '') {
            $reasons[] = 'empty_l2_candidate';
        }
        if ($coverage < 1.0) {
            $reasons[] = 'coverage_below_1';
        }
        if ($retention < $l1Retention) {
            $reasons[] = 'retention_below_l1';
        }

        return array_values(array_unique($reasons));
    }

    private function nextVersion(string $compactionId): int
    {
        return ((int) AtlasL2HierarchicalSummary::query()
            ->where('compaction_id', $compactionId)
            ->max('version')) + 1;
    }

    private function recordEvidence(AtlasL2HierarchicalSummary $row, AiCompaction $compaction): void
    {
        AppendOnlyJsonlStore::append($this->evidencePath(), [
            'schema_version' => 'atlas.compaction.l2_hierarchical_summary.evidence.v1',
            'status' => $row->status,
            'compaction_id' => (string) $compaction->id,
            'thread_id' => (string) $compaction->thread_id,
            'version' => $row->version,
            'coverage' => $row->coverage_score,
            'retention' => $row->l2_context_retention_score,
            'l1_retention' => $row->l1_context_retention_score,
            'compression_ratio' => $row->compression_ratio,
            'rejection_reasons' => $row->rejection_reasons,
            'canonical_l1_changed' => false,
            'local_runtime' => $row->local_runtime,
            'recorded_at' => now()->toIso8601String(),
        ]);
    }

    private function evidencePath(): string
    {
        return (string) config(
            'atlas.compaction.l2_hierarchical_summary_evidence_path',
            storage_path('app/atlas/evidence/l2-hierarchical-summaries.jsonl'),
        );
    }

    private function scorer(): SummaryFidelityCoverageScorer
    {
        return $this->scorer ?? new SummaryFidelityCoverageScorer;
    }

    private function rewriter(): LocalL2SummaryRewriter
    {
        if (app()->bound(LocalL2SummaryRewriter::class)) {
            return app(LocalL2SummaryRewriter::class);
        }

        return new DeterministicLocalL2SummaryRewriter;
    }
}
