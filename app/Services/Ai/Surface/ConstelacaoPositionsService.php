<?php

namespace App\Services\Ai\Surface;

use App\Models\AtlasLedgerEvent;
use App\Models\Capture;
use App\Models\SemanticNote;
use App\Services\Ai\Context\LocalRagReadinessService;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ConstelacaoPositionsService
{
    private const SCHEMA_VERSION = 'atlas.constelacao.positions.v1';

    public function __construct(
        private readonly AtlasEvidenceLedger $ledger,
        private readonly LocalRagReadinessService $localRagReadiness,
    ) {}

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    public function positions(array $filters = []): array
    {
        $limit = $this->limit($filters['limit'] ?? null);
        $domain = $this->nullableString($filters['domain'] ?? null);
        $lens = $this->lens($filters['lens'] ?? null);
        $tenantId = $this->nullableString($filters['tenant_id'] ?? null) ?: 'default';
        $operatorId = $this->nullableString($filters['operator_id'] ?? null) ?: 'vitor';

        $semanticNotes = $this->semanticNotes($limit, $domain);
        $remaining = max(0, $limit - $semanticNotes->count());
        $captures = $this->captures($remaining, $domain);
        $semanticReadiness = $this->semanticPositioningReadiness();

        $items = $semanticNotes
            ->map(fn (SemanticNote $note): array => $this->semanticNotePosition($note))
            ->concat($captures->map(fn (Capture $capture): array => $this->capturePosition($capture)))
            ->values()
            ->all();

        $payloadHash = hash('sha256', json_encode([
            'lens' => $lens,
            'domain' => $domain,
            'limit' => $limit,
            'semantic_readiness_status' => $semanticReadiness['status'],
            'items' => collect($items)->map(fn (array $item): array => [
                'source_type' => $item['source_type'],
                'source_id' => $item['source_id'],
                'position_hash' => $item['position_hash'],
            ])->all(),
        ], JSON_THROW_ON_ERROR));

        $event = $this->recordEvidence($items, [
            'tenant_id' => $tenantId,
            'operator_id' => $operatorId,
            'domain' => $domain,
            'lens' => $lens,
            'limit' => $limit,
            'client_surface' => $this->nullableString($filters['client_surface'] ?? null),
            'semantic_readiness' => $semanticReadiness,
            'payload_hash' => $payloadHash,
        ]);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'ok',
            'surface_id' => 'constelacao',
            'lens' => $lens,
            'ui_contract' => [
                'schema_version' => 'atlas.constelacao.ui_contract.v1',
                'lens' => $lens,
                'lens_role' => $lens === 'bilderatlas' ? 'contemplative_serendipity' : 'explicit_operational_overlay',
                'operational_chrome_allowed' => $lens !== 'bilderatlas',
                'default_interaction' => 'tap_star_opens_existing_detail_sheet',
                'raw_reading_allowed' => false,
                'telemetry' => [
                    'required_events' => [
                        'constelacao_opened',
                        'constelacao_backend_loaded',
                        'constelacao_backend_failed',
                        'constelacao_star_tapped',
                    ],
                    'privacy_class' => 'p2_metadata',
                    'raw_content_allowed' => false,
                ],
            ],
            'position_engine' => [
                'mode' => 'governed_backend_v1',
                'source' => 'deterministic_semantic_fallback',
                'semantic_positioning_mode' => 'fallback_until_promotion_gate_passes',
                'graph_rag_status' => 'future_governed',
                'embedding_runtime' => 'not_required_for_v1',
                'fallback_active' => true,
                'semantic_positioning_readiness' => $semanticReadiness,
            ],
            'privacy' => [
                'privacy_class' => 'p2_metadata',
                'raw_content_exposed' => false,
                'body_excerpt_exposed' => false,
                'vault_raw_content_exposed' => false,
                'payload_hash' => $payloadHash,
            ],
            'items' => $items,
            'evidence_ledger' => [
                'recorded' => $event !== null,
                'event_type' => LedgerEventType::ConstelacaoPositionsServed->value,
                'event_id' => $event?->event_id,
            ],
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * @return EloquentCollection<int,SemanticNote>
     */
    private function semanticNotes(int $limit, ?string $domain): EloquentCollection
    {
        if ($limit <= 0 || ! Schema::hasTable('semantic_notes')) {
            return new EloquentCollection;
        }

        return SemanticNote::query()
            ->whereNull('deleted_at')
            ->whereIn('status', ['active', 'testing', 'validated', 'draft', 'inbox'])
            ->latest('updated_at')
            ->limit($limit * 2)
            ->get()
            ->filter(fn (SemanticNote $note): bool => $domain === null || in_array($domain, (array) $note->domains, true))
            ->take($limit)
            ->values();
    }

    /**
     * @return EloquentCollection<int,Capture>
     */
    private function captures(int $limit, ?string $domain): EloquentCollection
    {
        if ($limit <= 0 || ! Schema::hasTable('captures')) {
            return new EloquentCollection;
        }

        return Capture::query()
            ->whereNull('deleted_at')
            ->when($domain !== null, fn ($query) => $query->where('domain', $domain))
            ->latest('updated_at')
            ->limit($limit)
            ->get();
    }

    /**
     * @return array<string,mixed>
     */
    private function semanticNotePosition(SemanticNote $note): array
    {
        $domains = array_values(array_filter((array) $note->domains, fn (mixed $domain): bool => is_scalar($domain) && trim((string) $domain) !== ''));
        $domain = (string) ($domains[0] ?? 'general');
        $coordinates = $this->coordinates('semantic_note', (string) $note->id, (string) $note->title, $domain);

        return [
            'source_type' => 'semantic_note',
            'source_id' => (string) $note->id,
            'title' => Str::limit((string) $note->title, 96, ''),
            'domains' => $domains,
            'kind' => (string) $note->type,
            'status' => (string) $note->status,
            'x' => $coordinates['x'],
            'y' => $coordinates['y'],
            'intensity' => $this->intensity((float) ($note->usefulness_avg ?? 0), (int) ($note->activation_count ?? 0)),
            'cluster_key' => $this->clusterKey($domain),
            'position_method' => 'deterministic_domain_jitter',
            'position_hash' => $coordinates['hash'],
            'updated_at' => $note->updated_at?->toJSON(),
            'preview' => [
                'summary_available' => trim((string) $note->summary) !== '',
                'content_redacted' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function capturePosition(Capture $capture): array
    {
        $domain = (string) ($capture->domain ?: 'general');
        $title = $this->captureTitle($capture);
        $coordinates = $this->coordinates('capture', (string) $capture->id, $title, $domain);

        return [
            'source_type' => 'capture',
            'source_id' => (string) $capture->id,
            'title' => $title,
            'domains' => [$domain],
            'kind' => (string) $capture->kind,
            'status' => (string) $capture->transcription_status,
            'x' => $coordinates['x'],
            'y' => $coordinates['y'],
            'intensity' => 0.45,
            'cluster_key' => $this->clusterKey($domain),
            'position_method' => 'deterministic_domain_jitter',
            'position_hash' => $coordinates['hash'],
            'updated_at' => $capture->updated_at?->toJSON(),
            'preview' => [
                'summary_available' => false,
                'content_redacted' => true,
            ],
        ];
    }

    /**
     * @return array{x:float,y:float,hash:string}
     */
    private function coordinates(string $sourceType, string $sourceId, string $title, string $domain): array
    {
        $anchor = $this->anchor($domain);
        $hash = hash('sha256', implode('|', [$sourceType, $sourceId, $title, $domain]));
        $jx = (hexdec(substr($hash, 0, 8)) / 0xFFFFFFFF) - 0.5;
        $jy = (hexdec(substr($hash, 8, 8)) / 0xFFFFFFFF) - 0.5;

        return [
            'x' => round(max(-1, min(1, $anchor[0] + ($jx * 0.28))), 6),
            'y' => round(max(-1, min(1, $anchor[1] + ($jy * 0.28))), 6),
            'hash' => $hash,
        ];
    }

    /**
     * @return array{0:float,1:float}
     */
    private function anchor(string $domain): array
    {
        return match ($this->clusterKey($domain)) {
            'programming' => [-0.58, -0.32],
            'finance' => [0.42, -0.48],
            'marketing' => [0.58, 0.18],
            'personal_development', 'health' => [-0.18, 0.52],
            'learning', 'research', 'writing' => [0.08, 0.12],
            default => [0.0, 0.0],
        };
    }

    private function clusterKey(string $domain): string
    {
        $normalized = Str::of($domain)->lower()->replace(['-', ' '], '_')->toString();

        return match ($normalized) {
            'dev', 'code', 'software', 'engineering' => 'programming',
            'financas', 'financial' => 'finance',
            'saude', 'personal', 'personal_dev' => 'personal_development',
            'pesquisa' => 'research',
            'escrita' => 'writing',
            default => $normalized ?: 'general',
        };
    }

    private function intensity(float $usefulness, int $activations): float
    {
        $score = 0.35 + min(0.35, max(0, $usefulness) / 5 * 0.35) + min(0.3, $activations * 0.03);

        return round(min(1, $score), 4);
    }

    private function captureTitle(Capture $capture): string
    {
        $metadataTitle = data_get($capture->metadata, 'title');
        if (is_string($metadataTitle) && trim($metadataTitle) !== '') {
            return Str::limit($metadataTitle, 96, '');
        }

        return 'Capture '.Str::of((string) $capture->kind)->replace('_', ' ')->title();
    }

    private function limit(mixed $value): int
    {
        if (! is_numeric($value)) {
            return 50;
        }

        return max(1, min(100, (int) $value));
    }

    private function lens(mixed $value): string
    {
        $lens = is_scalar($value) ? strtolower(trim((string) $value)) : '';

        return in_array($lens, ['bilderatlas', 'command_sky'], true) ? $lens : 'bilderatlas';
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    /**
     * @return array<string,mixed>
     */
    private function semanticPositioningReadiness(): array
    {
        $report = $this->localRagReadiness->report();

        return [
            'schema_version' => 'atlas.constelacao.semantic_positioning_readiness.v1',
            'status' => $report['status'],
            'source' => 'atlas.local_rag_readiness',
            'vector_ready' => (bool) data_get($report, 'gates.vector_retrieval_governed', false),
            'graph_rag_status' => data_get($report, 'gates.graph_retrieval_future_governed') === true
                ? 'future_governed'
                : 'unavailable',
            'embedding_provider' => data_get($report, 'embedding.provider'),
            'local_default_embedding' => (bool) data_get($report, 'embedding.local_default', false),
            'provider_bypass_allowed' => false,
            'parallel_memory_allowed' => false,
            'promotion_gate' => [
                'schema_version' => 'atlas.constelacao.semantic_positioning_promotion_gate.v1',
                'vector_positioning_allowed' => $report['status'] === 'ready',
                'graph_rag_promotion_allowed' => false,
                'python_runtime_allowed' => false,
                'requires_human_review' => true,
                'requires_decision_receipt' => true,
                'requires_local_rag_benchmark' => true,
                'required_evidence' => [
                    LedgerEventType::LocalRagPlanCreated->value,
                    LedgerEventType::LocalRagQualityCorpusEvaluated->value,
                    LedgerEventType::LocalRagGraphPromotionBlocked->value,
                ],
                'blocking_reason' => $report['status'] === 'ready'
                    ? 'vector_positioning_may_be_used_as_read_model_only; graph_rag_python_promotion_requires_reviewed_curator_proposal'
                    : 'local_rag_readiness_not_ready',
            ],
            'next_action' => $report['next_action'],
            'blocking_gates' => $report['blocking_gates'],
            'attention_gates' => $report['attention_gates'],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     * @param  array<string,mixed>  $context
     */
    private function recordEvidence(array $items, array $context): ?AtlasLedgerEvent
    {
        return $this->ledger->record(LedgerEventType::ConstelacaoPositionsServed, [
            'schema_version' => 'atlas.constelacao.ledger_event.v1',
            'surface_id' => 'constelacao',
            'privacy_class' => 'p2_metadata',
            'raw_content_exposed' => false,
            'position_engine' => 'deterministic_semantic_fallback',
            'semantic_positioning_mode' => 'fallback_until_promotion_gate_passes',
            'graph_rag_status' => 'future_governed',
            'semantic_readiness_status' => data_get($context, 'semantic_readiness.status'),
            'client_surface' => $context['client_surface'] ?? 'unknown',
            'provider_bypass_allowed' => false,
            'parallel_memory_allowed' => false,
            'promotion_gate' => [
                'vector_positioning_allowed' => (bool) data_get($context, 'semantic_readiness.promotion_gate.vector_positioning_allowed', false),
                'graph_rag_promotion_allowed' => false,
                'python_runtime_allowed' => false,
                'requires_human_review' => true,
                'requires_decision_receipt' => true,
            ],
            'lens' => $context['lens'],
            'domain' => $context['domain'],
            'item_count' => count($items),
            'source_counts' => collect($items)->countBy('source_type')->all(),
            'payload_hash' => $context['payload_hash'],
            'item_refs' => collect($items)->take(25)->map(fn (array $item): array => [
                'source_type' => $item['source_type'],
                'source_id' => $item['source_id'],
                'position_hash' => $item['position_hash'],
            ])->values()->all(),
        ], [
            'tenant_id' => $context['tenant_id'],
            'operator_id' => $context['operator_id'],
            'envelope_id' => 'constelacao_positions:'.$context['payload_hash'],
            'correlation_id' => 'constelacao_positions',
            'emitter_stage' => 'atlas.constelacao_surface',
            'emitter_version' => self::SCHEMA_VERSION,
        ]);
    }
}
