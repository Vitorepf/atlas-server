<?php

namespace App\Services\Ai\Domain;

use App\Services\Ai\Kernel\Decision\DecisionReceiptIssuer;
use App\Services\Ai\Kernel\Envelope\OperationEnvelopeFactory;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Support\AiStringListNormalizer;

class ResearchPacketService
{
    use DomainInputNormalization;

    public const SCHEMA_VERSION = 'atlas.research.packet.v1';

    public function __construct(
        private readonly OperationEnvelopeFactory $envelopes,
        private readonly DecisionReceiptIssuer $receipts,
        private readonly AtlasEvidenceLedger $ledger,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function packet(string $flow, array $input): array
    {
        $question = $this->string($input['question'] ?? $input['query'] ?? '');
        $purpose = $this->string($input['purpose'] ?? '');
        $sources = $this->sources($input['sources'] ?? []);
        $constraints = AiStringListNormalizer::uniqueTruthyTrimmedCastValues($input['constraints'] ?? []);
        $freshness = $this->string($input['freshness'] ?? ($flow === 'research.super' ? 'high' : 'normal'));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'ok',
            'mode' => $flow === 'research.super' ? 'deep_research_plan' : 'quick_research_plan',
            'domain' => 'research',
            'flow' => $flow,
            'question' => $question,
            'purpose' => $purpose,
            'source_pack' => [
                'sources' => $sources,
                'source_count' => count($sources),
                'minimum_source_count' => $flow === 'research.super' ? 3 : 1,
                'freshness_requirement' => $freshness,
            ],
            'research_plan' => [
                'steps' => $flow === 'research.super'
                    ? ['discover_sources', 'extract_claims', 'check_contradictions', 'synthesize', 'score_confidence']
                    : ['extract_claims', 'synthesize', 'state_uncertainty'],
                'constraints' => $constraints,
                'contradiction_check_required' => $flow === 'research.super',
                'citation_required' => true,
            ],
            'gates' => $this->gates($flow, $question, $sources),
            'output_contract' => [
                'answer_summary' => true,
                'claims_table' => true,
                'source_refs' => true,
                'uncertainty' => true,
                'open_questions' => true,
                'memory_promotion' => 'proposal_only',
            ],
            'rules' => [
                'source_grounded' => true,
                'no_unsourced_claims' => true,
                'memory_promotion_requires_review' => true,
                'external_side_effects_allowed' => false,
            ],
            'forbidden_actions' => [
                'treat_unsourced_claim_as_fact',
                'promote_to_memory_without_review',
                'hide_uncertainty',
                'scrape_private_or_forbidden_sources',
            ],
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function auditedPacket(string $flow, array $input): array
    {
        $packet = $this->packet($flow, $input);
        $tenantId = $this->string($input['tenant_id'] ?? 'default') ?: 'default';
        $operatorId = $this->string($input['operator_id'] ?? 'system') ?: 'system';
        $requiredGates = collect((array) $packet['gates'])->pluck('id')->values()->all();

        $envelope = $this->envelopes->create([
            'operator' => [
                'tenant_id' => $tenantId,
                'operator_id' => $operatorId,
            ],
            'origin' => [
                'surface_id' => $this->string($input['surface_id'] ?? 'atlas_domain_orchestrator_research') ?: 'atlas_domain_orchestrator_research',
                'surface_version' => 'atlas.research.v1',
                'session_id' => $this->string($input['session_id'] ?? ''),
            ],
            'input' => [
                'primary_type' => 'text',
                'text' => (string) ($packet['question'] ?? ''),
                'hints' => [
                    'domain' => 'research',
                    'flow' => $flow,
                    'mode' => $packet['mode'],
                    'audit_only' => true,
                ],
                'locale' => 'pt-BR',
            ],
        ]);

        $receipt = $this->receipts->issue($envelope, [
            'dry_run' => true,
            'signed_by' => 'atlas.research.packet.v1',
            'domain' => 'research',
            'flow' => $flow,
            'risk' => 'medium',
            'provider_selection' => [
                'primary' => 'none',
                'model' => 'not_applicable_research_packet',
                'fallbacks' => [],
                'selection_mode' => 'auto_best_allowed',
                'selection_reason' => 'Research orchestrator currently emits a grounded research plan packet; provider/source execution is a later runtime stage.',
            ],
            'budgets' => [
                'max_prompt_tokens' => 0,
                'max_output_tokens' => 0,
                'max_cost_usd' => 0,
            ],
            'required_gates' => $requiredGates,
            'required_evidence' => ['research_packet', 'source_refs', 'uncertainty_statement'],
            'repair_policy' => [
                'enabled' => true,
                'max_attempts' => 1,
                'strategy' => 'request_sources_or_narrow_question',
            ],
            'metadata' => [
                'tenant_id' => $tenantId,
                'operator_id' => $operatorId,
                'source_grounded' => true,
                'memory_promotion' => 'proposal_only',
                'packet_schema_version' => self::SCHEMA_VERSION,
            ],
        ])->toArray();

        $this->ledger->recordDecisionIssued($receipt, [
            'tenant_id' => $tenantId,
            'operator_id' => $operatorId,
            'trace_id' => $envelope->audit->traceId,
            'correlation_id' => $envelope->audit->traceId,
            'emitter_stage' => 'atlas.research.packet',
            'emitter_version' => self::SCHEMA_VERSION,
        ]);

        $packed = $this->ledger->record(LedgerEventType::EvidencePacked, [
            'envelope_id' => $envelope->envelopeId,
            'receipt_id' => $receipt['receipt_id'],
            'domain' => 'research',
            'flow' => $flow,
            'packet' => $packet,
            'rules' => $packet['rules'],
        ], [
            'tenant_id' => $tenantId,
            'operator_id' => $operatorId,
            'envelope_id' => $envelope->envelopeId,
            'receipt_id' => $receipt['receipt_id'],
            'trace_id' => $envelope->audit->traceId,
            'correlation_id' => $envelope->audit->traceId,
            'emitter_stage' => 'atlas.research.packet',
            'emitter_version' => self::SCHEMA_VERSION,
        ]);

        return [
            'packet' => $packet,
            'receipt' => $receipt,
            'ledger' => [
                'envelope_id' => $envelope->envelopeId,
                'trace_id' => $envelope->audit->traceId,
                'events' => array_values(array_filter([
                    'ENVELOPE_CREATED',
                    'DECISION_ISSUED',
                    $packed?->event_type,
                ])),
            ],
        ];
    }

    /**
     * @param  array<int|string,mixed>  $sources
     * @return array<int,array<string,string>>
     */
    private function sources(mixed $sources): array
    {
        return collect((array) $sources)
            ->map(function (mixed $source): array {
                if (is_array($source)) {
                    return [
                        'title' => $this->string($source['title'] ?? $source['url'] ?? $source['ref'] ?? ''),
                        'url' => $this->string($source['url'] ?? ''),
                        'type' => $this->string($source['type'] ?? 'source'),
                    ];
                }

                return [
                    'title' => $this->string($source),
                    'url' => filter_var($source, FILTER_VALIDATE_URL) ? $this->string($source) : '',
                    'type' => 'source',
                ];
            })
            ->filter(fn (array $source): bool => $source['title'] !== '' || $source['url'] !== '')
            ->values()
            ->all();
    }

    /**
     * @param  array<int,array<string,string>>  $sources
     * @return array<int,array{id:string,status:string,reason:string}>
     */
    private function gates(string $flow, string $question, array $sources): array
    {
        $minimumSources = $flow === 'research.super' ? 3 : 1;

        return [
            $this->gate('research_question', $question !== '', 'Research question is required.'),
            $this->gate('source_pack', count($sources) >= $minimumSources, "At least {$minimumSources} source(s) are required."),
            $this->gate('citation_policy', true, 'Final answer must cite source refs.'),
            $this->gate('uncertainty_statement', true, 'Research output must state uncertainty and open questions.'),
            $this->gate('contradiction_check', $flow !== 'research.super' || count($sources) >= 3, 'Deep research requires contradiction search across sources.'),
        ];
    }

    /**
     * @return array{id:string,status:string,reason:string}
     */
    private function gate(string $id, bool $passed, string $reason): array
    {
        return [
            'id' => $id,
            'status' => $passed ? 'passed' : 'missing',
            'reason' => $reason,
        ];
    }
}
