<?php

namespace App\Services\Ai\Domain;

use App\Services\Ai\Kernel\Decision\DecisionReceiptIssuer;
use App\Services\Ai\Kernel\Envelope\OperationEnvelopeFactory;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Support\AiStringListNormalizer;

class WritingDraftService
{
    use DomainInputNormalization;

    public const SCHEMA_VERSION = 'atlas.writing.packet.v1';

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
        $audience = $this->string($input['audience'] ?? '');
        $voice = $this->string($input['voice'] ?? $input['tone'] ?? '');
        $sourceMaterial = AiStringListNormalizer::uniqueTruthyTrimmedCastValues($input['source_material'] ?? $input['sources'] ?? []);
        $constraints = AiStringListNormalizer::uniqueTruthyTrimmedCastValues($input['constraints'] ?? []);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'ok',
            'mode' => $this->mode($flow),
            'domain' => 'writing',
            'flow' => $flow,
            'brief' => [
                'goal' => $goal,
                'audience' => $audience,
                'voice' => $voice,
                'source_material' => $sourceMaterial,
                'constraints' => $constraints,
                'missing_inputs' => $this->missingInputs($goal, $audience, $voice),
            ],
            'output_contract' => $this->outputContract($flow),
            'gates' => $this->gates($flow, $goal, $audience, $voice),
            'rules' => [
                'draft_until_operator_approval' => true,
                'external_publish_allowed' => false,
                'preserve_operator_voice' => true,
                'fact_claims_require_sources' => true,
                'no_sensitive_disclosure_without_review' => true,
            ],
            'forbidden_actions' => [
                'auto_publish',
                'invent_sources',
                'rewrite_operator_voice_without_review',
                'publish_sensitive_or_private_content',
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
                'surface_id' => $this->string($input['surface_id'] ?? 'atlas_domain_orchestrator_writing') ?: 'atlas_domain_orchestrator_writing',
                'surface_version' => 'atlas.writing.v1',
                'session_id' => $this->string($input['session_id'] ?? ''),
            ],
            'input' => [
                'primary_type' => 'text',
                'text' => trim(data_get($packet, 'brief.goal', '')."\n".implode("\n", data_get($packet, 'brief.source_material', []))),
                'hints' => [
                    'domain' => 'writing',
                    'flow' => $flow,
                    'mode' => $packet['mode'],
                    'audit_only' => true,
                ],
                'locale' => 'pt-BR',
            ],
        ]);

        $receipt = $this->receipts->issue($envelope, [
            'dry_run' => true,
            'signed_by' => 'atlas.writing.packet.v1',
            'domain' => 'writing',
            'flow' => $flow,
            'risk' => 'medium',
            'provider_selection' => [
                'primary' => 'none',
                'model' => 'not_applicable_writing_packet',
                'fallbacks' => [],
                'selection_mode' => 'auto_best_allowed',
                'selection_reason' => 'Writing orchestrator emits a governed packet; provider drafting is a later runtime stage.',
            ],
            'budgets' => [
                'max_prompt_tokens' => 0,
                'max_output_tokens' => 0,
                'max_cost_usd' => 0,
            ],
            'required_gates' => $requiredGates,
            'required_evidence' => ['writing_packet', 'voice_alignment', 'human_review_required'],
            'repair_policy' => [
                'enabled' => true,
                'max_attempts' => 1,
                'strategy' => 'request_brief_voice_or_source_material',
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
            'emitter_stage' => 'atlas.writing.packet',
            'emitter_version' => self::SCHEMA_VERSION,
        ]);

        $packed = $this->ledger->record(LedgerEventType::EvidencePacked, [
            'envelope_id' => $envelope->envelopeId,
            'receipt_id' => $receipt['receipt_id'],
            'domain' => 'writing',
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
            'emitter_stage' => 'atlas.writing.packet',
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

    private function mode(string $flow): string
    {
        return match ($flow) {
            'writing.edit' => 'edit_plan',
            'writing.voice_review' => 'voice_review',
            'writing.publish_review' => 'publication_review',
            default => 'draft_plan',
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function outputContract(string $flow): array
    {
        return match ($flow) {
            'writing.edit' => ['edited_version' => true, 'change_rationale' => true, 'voice_preservation_notes' => true],
            'writing.voice_review' => ['voice_score' => true, 'voice_drift_findings' => true, 'rewrite_recommendations' => true],
            'writing.publish_review' => ['publication_risk' => true, 'claim_review' => true, 'human_approval_required' => true],
            default => ['draft_outline' => true, 'draft_sections' => true, 'revision_questions' => true],
        };
    }

    /**
     * @return array<int,string>
     */
    private function missingInputs(string $goal, string $audience, string $voice): array
    {
        $missing = [];

        foreach (['goal' => $goal, 'audience' => $audience, 'voice' => $voice] as $key => $value) {
            if ($value === '') {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    /**
     * @return array<int,array{id:string,status:string,reason:string}>
     */
    private function gates(string $flow, string $goal, string $audience, string $voice): array
    {
        return [
            $this->gate('brief_clarity', $goal !== '', 'Writing goal/brief is required.'),
            $this->gate('audience_fit', $audience !== '', 'Audience must be explicit.'),
            $this->gate('voice_alignment', $voice !== '', 'Voice/tone must be explicit.'),
            $this->gate('human_review_required', true, 'Writing output stays draft until operator approval.'),
            $this->gate('publication_review', $flow !== 'writing.publish_review' || ($goal !== '' && $audience !== ''), 'Publication review requires brief and audience.'),
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
