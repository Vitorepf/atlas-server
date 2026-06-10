<?php

namespace App\Services\Ai\Surface;

use App\Models\AtlasLedgerEvent;
use App\Models\Capture;
use App\Models\SemanticNote;
use App\Services\Ai\Context\LocalRagReadinessService;
use App\Services\Ai\Kernel\Architecture\AtlasRuntimeLanguageBoundaryReportService;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Str;

class ConstelacaoPositionsService
{
    private const SCHEMA_VERSION = 'atlas.constelacao.positions.v1';

    public function __construct(
        private readonly AtlasEvidenceLedger $ledger,
        private readonly LocalRagReadinessService $localRagReadiness,
        private readonly AtlasRuntimeLanguageBoundaryReportService $runtimeBoundary,
    ) {}

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    public function positions(array $filters = []): array
    {
        $limit = $this->limit($filters['limit'] ?? null);
        $domain = $this->nullableString($filters['domain'] ?? null);
        $requestedLens = $this->requestedLens($filters['lens'] ?? null);
        $lens = $this->activeLens($requestedLens);
        $lensBlocked = $requestedLens !== $lens;
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
            'requested_lens' => $requestedLens,
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
            'requested_lens' => $requestedLens,
            'lens_blocked' => $lensBlocked,
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
            'requested_lens' => $requestedLens,
            'lens_gate' => [
                'schema_version' => 'atlas.constelacao.lens_gate.v1',
                'active_lens' => $lens,
                'requested_lens' => $requestedLens,
                'allowed_lenses' => ['bilderatlas'],
                'blocked' => $lensBlocked,
                'reason' => $this->lensGateReason($requestedLens, $lensBlocked),
            ],
            'lens_maturity_gate' => $this->lensMaturityGate(),
            'lens1_usage_review_contract' => $this->lens1UsageReviewContract(),
            'ui_contract' => [
                'schema_version' => 'atlas.constelacao.ui_contract.v1',
                'lens' => $lens,
                'requested_lens' => $requestedLens,
                'lens_role' => 'contemplative_serendipity',
                'operational_chrome_allowed' => false,
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
        if ($limit <= 0 || ! DatabaseTableAvailability::has('semantic_notes')) {
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
        if ($limit <= 0 || ! DatabaseTableAvailability::has('captures')) {
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

    private function requestedLens(mixed $value): string
    {
        $lens = is_scalar($value) ? strtolower(trim((string) $value)) : '';
        $lens = (string) preg_replace('/[^a-z0-9_-]+/', '_', $lens);
        $lens = trim($lens, '_-');

        return $lens !== '' ? Str::limit($lens, 48, '') : 'bilderatlas';
    }

    private function activeLens(string $requestedLens): string
    {
        return $requestedLens === 'bilderatlas' ? 'bilderatlas' : 'bilderatlas';
    }

    private function lensGateReason(string $requestedLens, bool $lensBlocked): ?string
    {
        if (! $lensBlocked) {
            return null;
        }

        return match ($requestedLens) {
            'command_sky' => 'command_sky_requires_future_ap_human_review_and_decision_receipt',
            'lineage' => 'lineage_requires_future_ap_human_review_and_decision_receipt',
            'lens2' => 'lens2_requires_future_ap_human_review_and_decision_receipt',
            default => 'unsupported_lens_requires_future_ap_human_review_and_decision_receipt',
        };
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
                'future_runtime_invocation_contract' => $this->futureGraphRuntimeInvocationContract('constelacao_semantic_positioning_candidate'),
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
            'lens_gate' => [
                'active_lens' => $context['lens'],
                'requested_lens' => $context['requested_lens'],
                'allowed_lenses' => ['bilderatlas'],
                'blocked' => (bool) $context['lens_blocked'],
                'reason' => $this->lensGateReason((string) $context['requested_lens'], (bool) $context['lens_blocked']),
            ],
            'lens_maturity_gate' => $this->lensMaturityGate(),
            'lens1_usage_review_contract' => $this->lens1UsageReviewContract(),
            'provider_bypass_allowed' => false,
            'parallel_memory_allowed' => false,
            'promotion_gate' => [
                'promotion_allowed' => false,
                'vector_positioning_allowed' => (bool) data_get($context, 'semantic_readiness.promotion_gate.vector_positioning_allowed', false),
                'graph_rag_promotion_allowed' => false,
                'python_runtime_allowed' => false,
                'requires_human_review' => true,
                'requires_decision_receipt' => true,
                'next_action' => 'collect_constelacao_lens1_usage_telemetry_for_30_days_before_review',
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

    /**
     * @return array<string,mixed>
     */
    private function lensMaturityGate(): array
    {
        return [
            'schema_version' => 'atlas.constelacao.lens_maturity_gate.v1',
            'current_lens' => 'bilderatlas',
            'promotion_allowed' => false,
            'lens2_promotion_allowed' => false,
            'command_sky_allowed' => false,
            'lineage_allowed' => false,
            'graph_rag_positioning_allowed' => false,
            'observation_window_days_required' => 30,
            'minimum_review_event_families' => [
                'constelacao_opened',
                'constelacao_backend_loaded',
                'constelacao_backend_failed',
                'constelacao_star_tapped',
            ],
            'requires_human_review' => true,
            'requires_curator_usage_review' => true,
            'requires_decision_receipt' => true,
            'required_telemetry' => [
                'constelacao_opened',
                'constelacao_backend_loaded',
                'constelacao_star_tapped',
            ],
            'blocking_reason' => 'lens1_must_prove_contemplative_value_before_operational_or_graph_promotion',
            'next_action' => 'collect_constelacao_lens1_usage_telemetry_for_30_days_before_review',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function lens1UsageReviewContract(): array
    {
        return [
            'schema_version' => 'atlas.constelacao.lens1_usage_review.v1',
            'status' => 'observation_required',
            'current_lens' => 'bilderatlas',
            'promotion_allowed' => false,
            'observation_window_days_required' => 30,
            'human_review_required' => true,
            'curator_review_required' => true,
            'decision_receipt_required' => true,
            'required_human_decision' => 'approve_or_reject_constelacao_lens1_promotion_after_usage_review',
            'rollback_plan_required' => true,
            'policy_patch_review_required' => true,
            'auto_promotion_allowed' => false,
            'evidence_required' => [
                'CONSTELACAO_POSITIONS_SERVED',
                'constelacao_opened',
                'constelacao_backend_loaded',
                'constelacao_star_tapped',
                '30_day_observation_window',
                'curator_usage_review',
                'human_review',
                'decision_receipt_for_future_ap',
            ],
            'rollback_required' => [
                'keep_lens_bilderatlas_only',
                'disable_command_sky_entrypoint',
                'disable_lineage_ui',
                'keep_graph_rag_positioning_disabled',
                'keep_python_graph_runtime_disabled',
                'preserve_constelacao_as_contemplative_surface',
            ],
            'forbidden_until_review' => [
                'enable_lens2',
                'enable_command_sky',
                'enable_lineage',
                'enable_graph_rag_positioning',
                'enable_operational_dashboard',
                'inject_constelacao_into_provider_prompt',
                'patch_decide_policy',
                'auto_apply_policy_patch',
                'surface_direct_graph_rag_call',
            ],
            'allowed_outputs' => [
                'keep_bilderatlas_only',
                'request_more_observation',
                'draft_lens2_ap',
                'draft_command_sky_ap',
                'draft_graph_rag_positioning_ap',
            ],
            'blocked_targets' => [
                'lens2',
                'command_sky',
                'lineage',
                'graph_rag_positioning',
                'operational_dashboard',
                'decision_surface',
                'provider_prompt',
                'policy_patch',
                'python_graph_rag_runtime',
            ],
            'required_telemetry' => [
                'constelacao_opened',
                'constelacao_backend_loaded',
                'constelacao_backend_failed',
                'constelacao_star_tapped',
            ],
            'review_question' => 'lente_1_gerou_serendipidade_util_sem_ansiedade_operacional',
            'promotion_rule' => 'only_future_ap_with_human_review_curator_proposal_decision_receipt_and_rollback_plan',
            'future_runtime_invocation_contract' => $this->futureGraphRuntimeInvocationContract('constelacao_lens_future_candidate'),
            'next_action' => 'collect_constelacao_lens1_usage_telemetry_for_30_days_before_review',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function futureGraphRuntimeInvocationContract(string $runtimeId): array
    {
        return [
            ...$this->runtimeBoundary->invocationContract(),
            'selected_runtime_family' => 'python_ai_data',
            'runtime_id' => $runtimeId,
            'surface_id' => 'constelacao',
            'capability_id' => 'constelacao.future_graph_positioning_candidate',
            'evidence_rule' => 'constelacao_future_graph_runtime_must_remain_review_only_until_lens1_usage_window_curator_proposal_human_review_and_future_ap',
            'promotion_allowed_now' => false,
            'auto_enable_allowed_now' => false,
        ];
    }
}
