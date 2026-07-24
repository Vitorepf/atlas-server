<?php

namespace App\Services\Ai\Domain;

use App\Services\Ai\Kernel\Decision\DecisionReceiptIssuer;
use App\Services\Ai\Kernel\Envelope\OperationEnvelopeFactory;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Support\AiStringListNormalizer;

class MarketingDraftService
{
    use DomainInputNormalization;

    public const SCHEMA_VERSION = 'atlas.marketing.draft_packet.v1';

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
        $goal = $this->string($input['goal'] ?? $input['brief'] ?? '');
        $offer = $this->string($input['offer'] ?? '');
        $audience = $this->string($input['audience'] ?? $input['icp'] ?? '');
        $brandVoice = $this->string($input['brand_voice'] ?? '');
        $claims = AiStringListNormalizer::uniqueTruthyTrimmedCastValues($input['claims'] ?? []);
        $channels = AiStringListNormalizer::uniqueTruthyTrimmedCastValues($input['channels'] ?? []);
        $constraints = AiStringListNormalizer::uniqueTruthyTrimmedCastValues($input['constraints'] ?? []);
        $businessContext = $this->string($input['business_context'] ?? $input['product_context'] ?? '');

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'ok',
            'mode' => 'draft_and_review',
            'domain' => 'marketing',
            'flow' => $flow,
            'business_context' => $businessContext,
            'brief' => [
                'goal' => $goal,
                'offer' => $offer,
                'audience' => $audience,
                'brand_voice' => $brandVoice,
                'claims' => $claims,
                'channels' => $channels,
                'constraints' => $constraints,
                'missing_inputs' => $this->missingInputs($goal, $offer, $audience, $brandVoice, $claims),
            ],
            'draft_outputs' => $this->draftOutputs($flow),
            'gates' => $this->gates($goal, $offer, $audience, $brandVoice, $claims),
            'measurement_plan' => [
                'required' => true,
                'default_metrics' => ['ctr', 'cvr', 'cpa', 'revenue', 'qualitative_feedback'],
                'outcome_calibration_required' => true,
            ],
            'rules' => [
                'draft_until_operator_approval' => true,
                'external_publish_allowed' => false,
                'ad_spend_allowed' => false,
                'claim_substantiation_required' => true,
                'brand_review_required' => true,
            ],
            'forbidden_actions' => [
                'auto_publish',
                'auto_ad_spend',
                'auto_claim_creation_without_evidence',
                'auto_contact_customer_or_audience',
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
                'surface_id' => $this->string($input['surface_id'] ?? 'atlas_domain_orchestrator_marketing') ?: 'atlas_domain_orchestrator_marketing',
                'surface_version' => 'atlas.marketing.v1',
                'session_id' => $this->string($input['session_id'] ?? ''),
            ],
            'input' => [
                'primary_type' => 'text',
                'text' => trim(data_get($packet, 'brief.goal', '')."\n".data_get($packet, 'brief.offer', '')),
                'hints' => [
                    'domain' => 'marketing',
                    'flow' => $flow,
                    'mode' => 'draft_and_review',
                    'audit_only' => true,
                ],
                'locale' => 'pt-BR',
            ],
        ]);

        $receipt = $this->receipts->issue($envelope, [
            'dry_run' => true,
            'signed_by' => 'atlas.marketing.draft.v1',
            'domain' => 'marketing',
            'flow' => $flow,
            'risk' => 'medium',
            'provider_selection' => [
                'primary' => 'none',
                'model' => 'not_applicable_deterministic_draft_packet',
                'fallbacks' => [],
                'selection_mode' => 'auto_best_allowed',
                'selection_reason' => 'Marketing orchestrator currently emits a governed draft packet; provider generation is a later runtime stage.',
            ],
            'budgets' => [
                'max_prompt_tokens' => 0,
                'max_output_tokens' => 0,
                'max_cost_usd' => 0,
            ],
            'required_gates' => $requiredGates,
            'required_evidence' => ['marketing_draft_packet', 'claim_substantiation_gate', 'measurement_plan'],
            'repair_policy' => [
                'enabled' => true,
                'max_attempts' => 1,
                'strategy' => 'reframe_brief_or_request_missing_inputs',
            ],
            'metadata' => [
                'tenant_id' => $tenantId,
                'operator_id' => $operatorId,
                'draft_until_operator_approval' => true,
                'external_publish_allowed' => false,
                'packet_schema_version' => self::SCHEMA_VERSION,
            ],
        ])->toArray();

        $this->ledger->recordDecisionIssued($receipt, [
            'tenant_id' => $tenantId,
            'operator_id' => $operatorId,
            'trace_id' => $envelope->audit->traceId,
            'correlation_id' => $envelope->audit->traceId,
            'emitter_stage' => 'atlas.marketing.draft',
            'emitter_version' => self::SCHEMA_VERSION,
        ]);

        $packed = $this->ledger->record(LedgerEventType::EvidencePacked, [
            'envelope_id' => $envelope->envelopeId,
            'receipt_id' => $receipt['receipt_id'],
            'domain' => 'marketing',
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
            'emitter_stage' => 'atlas.marketing.draft',
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
     * @return array<int,string>
     */
    private function missingInputs(string $goal, string $offer, string $audience, string $brandVoice, array $claims): array
    {
        $missing = [];

        foreach (['goal' => $goal, 'offer' => $offer, 'audience' => $audience, 'brand_voice' => $brandVoice] as $key => $value) {
            if ($value === '') {
                $missing[] = $key;
            }
        }

        if ($claims === []) {
            $missing[] = 'claims';
        }

        return $missing;
    }

    /**
     * @return array<int,array{id:string,status:string,reason:string}>
     */
    private function gates(string $goal, string $offer, string $audience, string $brandVoice, array $claims): array
    {
        return [
            $this->gate('strategy_brief', $goal !== '' && $offer !== '', 'Goal and offer are required.'),
            $this->gate('audience_fit', $audience !== '', 'Audience/ICP must be explicit.'),
            $this->gate('brand_alignment', $brandVoice !== '', 'Brand voice must be explicit before asset generation.'),
            $this->gate('claim_substantiation', $claims !== [], 'Claims need evidence before publication or paid traffic.'),
            $this->gate('measurement_plan', true, 'Every marketing draft includes outcome metrics and calibration plan.'),
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

    /**
     * @return array<string,mixed>
     */
    private function draftOutputs(string $flow): array
    {
        return match ($flow) {
            'marketing.copywriting' => ['copy_variants' => 3, 'claim_table_required' => true],
            'marketing.creative' => ['creative_directions' => 3, 'visual_brief_required' => true],
            'marketing.analytics' => ['insight_summary' => true, 'next_experiment_required' => true],
            'marketing.ab_test' => ['hypothesis' => true, 'variant_matrix_required' => true],
            'marketing.forge' => ['campaign_kit' => true, 'asset_manifest_required' => true],
            default => ['brief' => true, 'review_packet_required' => true],
        };
    }
}
