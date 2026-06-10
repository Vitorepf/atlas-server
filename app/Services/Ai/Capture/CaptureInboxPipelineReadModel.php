<?php

namespace App\Services\Ai\Capture;

use App\Services\Ai\Support\DatabaseTableAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CaptureInboxPipelineReadModel
{
    /**
     * @return array<string,mixed>
     */
    public function report(int $hours): array
    {
        $since = CarbonImmutable::now()->subHours($hours);
        $requiredTables = [
            'captures',
            'semantic_curation_proposals',
            'ai_memory_deltas',
            'ai_inbox_items',
            'capture_links',
        ];
        $missingTables = DatabaseTableAvailability::missing($requiredTables);

        if (in_array('captures', $missingTables, true)) {
            return [
                'schema_version' => 'atlas.capture_inbox_pipeline_report.v1',
                'status' => 'storage_unavailable',
                'hours' => $hours,
                'since' => $since->toJSON(),
                'missing_tables' => $missingTables,
                'writes' => false,
                'review_signal' => [
                    'status' => 'blocked',
                    'severity' => 'critical',
                    'recommended_action' => 'run_migrations_before_capture_pipeline_review',
                    'reasons' => ['captures table is unavailable'],
                ],
            ];
        }

        $captures = $this->recentCaptures($since);
        $proposals = DatabaseTableAvailability::has('semantic_curation_proposals')
            ? $this->recentProposals($since)
            : collect();
        $memoryDeltas = DatabaseTableAvailability::has('ai_memory_deltas')
            ? $this->recentMemoryDeltas($since)
            : collect();
        $inboxItems = DatabaseTableAvailability::has('ai_inbox_items')
            ? $this->recentInboxItems($since)
            : collect();
        $captureLinks = DatabaseTableAvailability::has('capture_links')
            ? $this->recentCaptureLinks($since)
            : collect();

        $proposalIds = $proposals->pluck('id')->filter()->flip();
        $captureIds = $captures->pluck('id')->filter()->flip();
        $unsafeCaptures = $captures->filter(fn (array $capture): bool => $this->captureIsUnsafe($capture));
        $missingQuarantine = $captures->filter(fn (array $capture): bool => ! is_array(data_get($capture, 'metadata.cognitive_quarantine')));
        $missingContentIntelligence = $captures->filter(fn (array $capture): bool => ! is_array(data_get($capture, 'metadata.content_intelligence')));
        $semanticLinkedCaptures = $captures->filter(fn (array $capture): bool => is_array(data_get($capture, 'metadata.semantic_curation')));
        $brokenSemanticRefs = $semanticLinkedCaptures->filter(function (array $capture) use ($proposalIds): bool {
            $proposalId = data_get($capture, 'metadata.semantic_curation.proposal_id');

            return is_scalar($proposalId) && ! $proposalIds->has((string) $proposalId);
        });
        $proposalSourceGaps = $proposals->filter(function (array $proposal) use ($captureIds): bool {
            if (($proposal['source_type'] ?? null) !== 'capture') {
                return false;
            }

            $captureId = data_get($proposal, 'source_refs.capture_id');

            return is_scalar($captureId) && ! $captureIds->has((string) $captureId);
        });
        $capturesById = $captures->keyBy('id');
        $proposalBacklinkGaps = $proposals->filter(function (array $proposal) use ($capturesById): bool {
            if (($proposal['source_type'] ?? null) !== 'capture') {
                return false;
            }

            $captureId = data_get($proposal, 'source_refs.capture_id');
            if (! is_scalar($captureId) || ! $capturesById->has((string) $captureId)) {
                return false;
            }

            $linkedProposalId = data_get($capturesById->get((string) $captureId), 'metadata.semantic_curation.proposal_id');

            return ! is_scalar($linkedProposalId) || (string) $linkedProposalId !== (string) $proposal['id'];
        });
        $pendingProposals = $proposals->where('status', 'pending');
        $stalePendingProposals = $pendingProposals->filter(fn (array $proposal): bool => $this->olderThan($proposal['created_at'] ?? null, 168));
        $pendingMemoryDeltas = $memoryDeltas->where('status', 'pending');
        $activeInboxItems = $inboxItems->filter(fn (array $item): bool => in_array((string) ($item['status'] ?? ''), ['unread', 'read', 'snoozed'], true));
        $captureInboxItems = $inboxItems->filter(fn (array $item): bool => in_array((string) ($item['type'] ?? ''), ['capture', 'proposal'], true));

        $reasons = [];
        if ($missingTables !== []) {
            $reasons[] = 'optional capture pipeline tables are unavailable: '.implode(', ', $missingTables);
        }
        if ($missingQuarantine->isNotEmpty()) {
            $reasons[] = 'recent captures missing cognitive quarantine';
        }
        if ($missingContentIntelligence->isNotEmpty()) {
            $reasons[] = 'recent captures missing content intelligence contract';
        }
        if ($unsafeCaptures->isNotEmpty()) {
            $reasons[] = 'recent captures expose provider/context/memory eligibility before review';
        }
        if ($brokenSemanticRefs->isNotEmpty() || $proposalSourceGaps->isNotEmpty() || $proposalBacklinkGaps->isNotEmpty()) {
            $reasons[] = 'capture/proposal lineage references need repair';
        }
        if ($stalePendingProposals->isNotEmpty()) {
            $reasons[] = 'semantic curation proposals are pending for more than 7 days';
        }

        $status = $reasons === [] ? 'ok' : 'warning';

        return [
            'schema_version' => 'atlas.capture_inbox_pipeline_report.v1',
            'status' => $status,
            'hours' => $hours,
            'since' => $since->toJSON(),
            'missing_tables' => $missingTables,
            'writes' => false,
            'capture_count' => $captures->count(),
            'text_capture_count' => $captures->where('kind', 'text')->count(),
            'audio_capture_count' => $captures->where('kind', 'audio')->count(),
            'photo_capture_count' => $captures->where('kind', 'photo')->count(),
            'quarantined_capture_count' => $captures->count() - $missingQuarantine->count(),
            'missing_quarantine_count' => $missingQuarantine->count(),
            'content_intelligence_count' => $captures->count() - $missingContentIntelligence->count(),
            'missing_content_intelligence_count' => $missingContentIntelligence->count(),
            'unsafe_capture_count' => $unsafeCaptures->count(),
            'semantic_curation_linked_capture_count' => $semanticLinkedCaptures->count(),
            'broken_semantic_curation_ref_count' => $brokenSemanticRefs->count(),
            'proposal_count' => $proposals->count(),
            'pending_proposal_count' => $pendingProposals->count(),
            'stale_pending_proposal_count' => $stalePendingProposals->count(),
            'accepted_or_edited_proposal_count' => $proposals->filter(fn (array $proposal): bool => in_array((string) ($proposal['status'] ?? ''), ['accepted', 'edited'], true))->count(),
            'proposal_source_gap_count' => $proposalSourceGaps->count(),
            'proposal_backlink_gap_count' => $proposalBacklinkGaps->count(),
            'memory_delta_count' => $memoryDeltas->count(),
            'pending_memory_delta_count' => $pendingMemoryDeltas->count(),
            'promoted_memory_delta_count' => $memoryDeltas->where('status', 'promoted')->count(),
            'capture_link_count' => $captureLinks->count(),
            'inbox_item_count' => $inboxItems->count(),
            'capture_or_proposal_inbox_item_count' => $captureInboxItems->count(),
            'active_inbox_item_count' => $activeInboxItems->count(),
            'recent_captures' => $this->recentRows($captures, ['id', 'kind', 'domain', 'captured_at']),
            'recent_proposals' => $this->recentRows($proposals, ['id', 'status', 'source_type', 'proposed_note_type', 'created_at']),
            'promotion_gate' => $this->promotionGate($pendingProposals->count(), $pendingMemoryDeltas->count(), $unsafeCaptures->count()),
            'review_signal' => [
                'status' => $status,
                'severity' => $status === 'ok' ? 'info' : 'warning',
                'recommended_action' => $status === 'ok'
                    ? 'continue_capture_inbox_pipeline_monitoring'
                    : 'review_capture_inbox_pipeline_gaps_before_memory_promotion',
                'reasons' => $reasons,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function promotionGate(int $pendingProposalCount, int $pendingMemoryDeltaCount, int $unsafeCaptureCount): array
    {
        $gate = [
            'schema_version' => 'atlas.capture_inbox_pipeline.promotion_gate.v1',
            'status' => $unsafeCaptureCount > 0 ? 'blocked' : 'review_required',
            'memory_write_allowed_by_report' => false,
            'context_injection_allowed_by_report' => false,
            'embedding_allowed_by_report' => false,
            'provider_export_allowed_by_report' => false,
            'open_brain_context_allowed_by_report' => false,
            'auto_promotion_allowed' => false,
            'operator_review_required' => $pendingProposalCount > 0 || $pendingMemoryDeltaCount > 0,
            'pending_proposal_count' => $pendingProposalCount,
            'pending_memory_delta_count' => $pendingMemoryDeltaCount,
            'unsafe_capture_count' => $unsafeCaptureCount,
            'required_before_memory_or_context_promotion' => [
                'semantic_curation_operator_review',
                'capture_cognitive_quarantine_present',
                'content_intelligence_contract_present',
                'lineage_backlinks_verified',
                'memory_delta_review_receipt_when_delta_exists',
                'promotion_receipt_hash',
            ],
            'raw_capture_text_persisted_in_report' => false,
            'writes' => false,
        ];

        return [
            ...$gate,
            'gate_hash' => hash('sha256', json_encode($gate, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
        ];
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    private function recentCaptures(CarbonImmutable $since): Collection
    {
        return DB::table('captures')
            ->select(['id', 'kind', 'domain', 'metadata', 'captured_at', 'created_at'])
            ->whereNull('deleted_at')
            ->where('captured_at', '>=', $since)
            ->orderByDesc('captured_at')
            ->get()
            ->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'kind' => (string) $row->kind,
                'domain' => (string) $row->domain,
                'metadata' => $this->json($row->metadata ?? []),
                'captured_at' => $this->dateString($row->captured_at ?? null),
                'created_at' => $this->dateString($row->created_at ?? null),
            ]);
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    private function recentProposals(CarbonImmutable $since): Collection
    {
        return DB::table('semantic_curation_proposals')
            ->select(['id', 'source_type', 'source_refs', 'proposed_note_type', 'status', 'metadata', 'created_at', 'resolved_at'])
            ->where('created_at', '>=', $since)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'source_type' => (string) $row->source_type,
                'source_refs' => $this->json($row->source_refs ?? []),
                'proposed_note_type' => (string) $row->proposed_note_type,
                'status' => (string) $row->status,
                'metadata' => $this->json($row->metadata ?? []),
                'created_at' => $this->dateString($row->created_at ?? null),
                'resolved_at' => $this->dateString($row->resolved_at ?? null),
            ]);
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    private function recentMemoryDeltas(CarbonImmutable $since): Collection
    {
        return DB::table('ai_memory_deltas')
            ->select(['id', 'type', 'scope', 'status', 'created_at', 'updated_at'])
            ->where('created_at', '>=', $since)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'type' => (string) $row->type,
                'scope' => (string) $row->scope,
                'status' => (string) $row->status,
                'created_at' => $this->dateString($row->created_at ?? null),
                'updated_at' => $this->dateString($row->updated_at ?? null),
            ]);
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    private function recentInboxItems(CarbonImmutable $since): Collection
    {
        return DB::table('ai_inbox_items')
            ->select(['id', 'type', 'category', 'severity', 'status', 'source_type', 'source_id', 'created_at'])
            ->where('created_at', '>=', $since)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'type' => (string) $row->type,
                'category' => $row->category === null ? null : (string) $row->category,
                'severity' => (string) $row->severity,
                'status' => (string) $row->status,
                'source_type' => $row->source_type === null ? null : (string) $row->source_type,
                'source_id' => $row->source_id === null ? null : (string) $row->source_id,
                'created_at' => $this->dateString($row->created_at ?? null),
            ]);
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    private function recentCaptureLinks(CarbonImmutable $since): Collection
    {
        return DB::table('capture_links')
            ->select(['id', 'capture_id', 'target_type', 'target_id', 'relation_type', 'created_at'])
            ->where('created_at', '>=', $since)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'capture_id' => (string) $row->capture_id,
                'target_type' => (string) $row->target_type,
                'target_id' => $row->target_id === null ? null : (string) $row->target_id,
                'relation_type' => (string) $row->relation_type,
                'created_at' => $this->dateString($row->created_at ?? null),
            ]);
    }

    /**
     * @param  array<string,mixed>  $capture
     */
    private function captureIsUnsafe(array $capture): bool
    {
        $quarantine = data_get($capture, 'metadata.cognitive_quarantine');
        if (! is_array($quarantine)) {
            return false;
        }

        return (bool) ($quarantine['provider_export_allowed'] ?? false)
            || (bool) ($quarantine['open_brain_context_allowed'] ?? false)
            || (bool) ($quarantine['embedding_allowed'] ?? false)
            || (bool) ($quarantine['memory_eligible'] ?? false)
            || (bool) ($quarantine['context_eligible'] ?? false);
    }

    private function olderThan(mixed $value, int $hours): bool
    {
        $date = $this->carbon($value);

        return $date !== null && $date->lt(CarbonImmutable::now()->subHours($hours));
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $rows
     * @param  array<int,string>  $keys
     * @return array<int,array<string,mixed>>
     */
    private function recentRows(Collection $rows, array $keys): array
    {
        return $rows
            ->take(10)
            ->map(fn (array $row): array => collect($row)->only($keys)->all())
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>|array<int,mixed>
     */
    private function json(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function carbon(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        if (is_string($value) && $value !== '') {
            return CarbonImmutable::parse($value);
        }

        return null;
    }

    private function dateString(mixed $value): ?string
    {
        return $this->carbon($value)?->toJSON();
    }
}
