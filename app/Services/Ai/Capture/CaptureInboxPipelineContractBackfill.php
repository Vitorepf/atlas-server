<?php

namespace App\Services\Ai\Capture;

use App\Services\Ai\Support\DatabaseTableAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CaptureInboxPipelineContractBackfill
{
    /**
     * @return array<string,mixed>
     */
    public function run(int $hours, bool $write): array
    {
        $since = CarbonImmutable::now()->subHours($hours);
        if (! DatabaseTableAvailability::has('captures')) {
            return [
                'schema_version' => 'atlas.capture_inbox_pipeline_contract_backfill.v1',
                'status' => 'storage_unavailable',
                'hours' => $hours,
                'since' => $since->toJSON(),
                'write' => $write,
                'planned_count' => 0,
                'applied_count' => 0,
                'repairs' => [],
            ];
        }

        $proposalsByCaptureId = DatabaseTableAvailability::has('semantic_curation_proposals')
            ? $this->proposalsByCaptureId($since)
            : collect();
        $repairs = $this->captures($since)
            ->map(fn (array $capture): ?array => $this->repairForCapture($capture, $proposalsByCaptureId->get($capture['id'])))
            ->filter()
            ->values();

        $applied = 0;
        if ($write) {
            foreach ($repairs as $repair) {
                DB::table('captures')
                    ->where('id', $repair['capture_id'])
                    ->update([
                        'metadata' => json_encode($repair['metadata_after'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        'updated_at' => now(),
                    ]);
                $applied++;
            }
        }

        return [
            'schema_version' => 'atlas.capture_inbox_pipeline_contract_backfill.v1',
            'status' => 'ok',
            'hours' => $hours,
            'since' => $since->toJSON(),
            'write' => $write,
            'planned_count' => $repairs->count(),
            'applied_count' => $applied,
            'repairs' => $repairs
                ->map(fn (array $repair): array => collect($repair)->except('metadata_after')->all())
                ->all(),
        ];
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    private function captures(CarbonImmutable $since): Collection
    {
        return DB::table('captures')
            ->select([
                'id',
                'client_id',
                'kind',
                'domain',
                'content_text',
                'content_sha256',
                'content_mime_type',
                'content_duration_ms',
                'content_size_bytes',
                'captured_at',
                'metadata',
            ])
            ->whereNull('deleted_at')
            ->where('captured_at', '>=', $since)
            ->orderByDesc('captured_at')
            ->get()
            ->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'client_id' => (string) $row->client_id,
                'kind' => (string) $row->kind,
                'domain' => (string) $row->domain,
                'content_text' => $row->content_text === null ? null : (string) $row->content_text,
                'content_sha256' => $row->content_sha256 === null ? null : (string) $row->content_sha256,
                'content_mime_type' => $row->content_mime_type === null ? null : (string) $row->content_mime_type,
                'content_duration_ms' => $row->content_duration_ms === null ? null : (int) $row->content_duration_ms,
                'content_size_bytes' => $row->content_size_bytes === null ? null : (int) $row->content_size_bytes,
                'captured_at' => $this->dateString($row->captured_at ?? null),
                'metadata' => $this->json($row->metadata ?? []),
            ]);
    }

    /**
     * @return Collection<string,array<string,mixed>>
     */
    private function proposalsByCaptureId(CarbonImmutable $since): Collection
    {
        return DB::table('semantic_curation_proposals')
            ->select(['id', 'source_refs', 'status', 'proposed_note_type', 'created_at'])
            ->where('created_at', '>=', $since)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'source_refs' => $this->json($row->source_refs ?? []),
                'status' => (string) $row->status,
                'proposed_note_type' => (string) $row->proposed_note_type,
                'created_at' => $this->dateString($row->created_at ?? null),
            ])
            ->filter(fn (array $proposal): bool => is_scalar(data_get($proposal, 'source_refs.capture_id')))
            ->keyBy(fn (array $proposal): string => (string) data_get($proposal, 'source_refs.capture_id'));
    }

    /**
     * @param  array<string,mixed>  $capture
     * @param  array<string,mixed>|null  $proposal
     * @return array<string,mixed>|null
     */
    private function repairForCapture(array $capture, ?array $proposal): ?array
    {
        $metadata = is_array($capture['metadata'] ?? null) ? $capture['metadata'] : [];
        $changes = [];

        if (! is_array($metadata['cognitive_quarantine'] ?? null)) {
            $metadata['cognitive_quarantine'] = $this->quarantine($capture);
            $changes[] = 'cognitive_quarantine';
        }

        if (! is_array($metadata['content_intelligence'] ?? null)) {
            $metadata['content_intelligence'] = $this->contentIntelligence($capture);
            $changes[] = 'content_intelligence';
        }

        if ($proposal !== null) {
            $linkedProposalId = data_get($metadata, 'semantic_curation.proposal_id');
            if (! is_scalar($linkedProposalId) || (string) $linkedProposalId !== (string) $proposal['id']) {
                $metadata['semantic_curation'] = [
                    ...(is_array($metadata['semantic_curation'] ?? null) ? $metadata['semantic_curation'] : []),
                    'schema_version' => 'atlas.capture.semantic_curation_review.v1',
                    'status' => 'proposal_pending',
                    'proposal_id' => $proposal['id'],
                    'proposal_status' => $proposal['status'],
                    'proposed_note_type' => $proposal['proposed_note_type'],
                    'human_gate' => 'operator_review',
                    'updated_at' => now()->toJSON(),
                ];
                $changes[] = 'semantic_curation';
            }
        }

        if ($changes === []) {
            return null;
        }

        return [
            'capture_id' => $capture['id'],
            'changes' => $changes,
            'write_safe' => true,
            'raw_content_copied' => false,
            'provider_export_allowed' => false,
            'open_brain_context_allowed' => false,
            'memory_eligible' => false,
            'metadata_after' => $metadata,
        ];
    }

    /**
     * @param  array<string,mixed>  $capture
     * @return array<string,mixed>
     */
    private function quarantine(array $capture): array
    {
        $now = now()->toJSON();
        $contentHash = $this->contentHash($capture);

        return [
            'schema_version' => 'atlas.capture.cognitive_quarantine.v1',
            'raw_capture' => true,
            'memory_eligible' => false,
            'context_eligible' => false,
            'constellation_eligible' => false,
            'embedding_allowed' => false,
            'provider_export_allowed' => false,
            'open_brain_context_allowed' => false,
            'raw_content_exposed' => false,
            'promotion_status' => 'unclassified',
            'promotion_target' => null,
            'source_type' => 'capture',
            'source_client_id' => $capture['client_id'],
            'source_kind' => $capture['kind'],
            'source_domain' => $capture['domain'],
            'content_hash' => $contentHash,
            'lineage' => [
                'origin' => 'capture_pipeline_contract_backfill',
                'captured_at' => $capture['captured_at'],
                'content_hash' => $contentHash,
            ],
            'review' => [
                'required' => true,
                'status' => 'pending',
                'reason' => 'legacy_capture_quarantined_before_memory_or_context',
            ],
            'backfill_receipt' => [
                'schema_version' => 'atlas.capture.contract_backfill_receipt.v1',
                'raw_content_copied' => false,
                'provider_export_allowed' => false,
                'open_brain_context_allowed' => false,
                'backfilled_at' => $now,
            ],
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @param  array<string,mixed>  $capture
     * @return array<string,mixed>
     */
    private function contentIntelligence(array $capture): array
    {
        $contentHash = $this->contentHash($capture);

        return [
            'schema_version' => 'atlas.capture.content_intelligence.v1',
            'status' => 'candidate_pending_review',
            'content_type' => $capture['kind'],
            'source_type' => 'operator_capture',
            'source_domain' => $capture['domain'],
            'source_refs' => [
                'capture_id' => $capture['id'],
                'client_id' => $capture['client_id'],
                'content_hash' => $contentHash,
            ],
            'quality' => [
                'score' => $this->qualityScore($capture),
                'label' => 'backfilled_candidate',
                'explanation' => 'legacy_capture_contract_backfill',
            ],
            'destination' => [
                'enum' => 'semantic_note',
                'status' => 'pending_review',
            ],
            'dedupe' => [
                'content_hash' => $contentHash,
                'status' => 'not_checked',
            ],
            'privacy' => [
                'provider_export_allowed' => false,
                'open_brain_context_allowed' => false,
                'embedding_allowed' => false,
                'memory_write_allowed' => false,
            ],
            'created_by' => 'capture-inbox-pipeline-contract-backfill',
            'created_at' => now()->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $capture
     */
    private function contentHash(array $capture): ?string
    {
        if (is_string($capture['content_sha256'] ?? null) && $capture['content_sha256'] !== '') {
            return (string) $capture['content_sha256'];
        }

        if (is_string($capture['content_text'] ?? null) && trim((string) $capture['content_text']) !== '') {
            return hash('sha256', (string) $capture['content_text']);
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $capture
     */
    private function qualityScore(array $capture): float
    {
        if (is_string($capture['content_text'] ?? null) && trim((string) $capture['content_text']) !== '') {
            return 0.55;
        }

        if (($capture['content_size_bytes'] ?? null) !== null || ($capture['content_duration_ms'] ?? null) !== null) {
            return 0.45;
        }

        return 0.25;
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

    private function dateString(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value)->toJSON();
        }

        if (is_string($value) && $value !== '') {
            return CarbonImmutable::parse($value)->toJSON();
        }

        return null;
    }
}
