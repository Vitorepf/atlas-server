<?php

namespace App\Services\Ai\Domain;

use App\Services\Ai\Kernel\Architecture\AtlasRivalsStrategyCaseRegistrar;
use App\Services\Ai\Kernel\Decision\DecisionReceiptIssuer;
use App\Services\Ai\Kernel\Envelope\OperationEnvelopeFactory;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Support\AiStringListNormalizer;

class StrategicDecisionReviewService
{
    public const SCHEMA_VERSION = 'atlas.strategic_decision.review_packet.v1';

    public function __construct(
        private readonly OperationEnvelopeFactory $envelopes,
        private readonly DecisionReceiptIssuer $receipts,
        private readonly AtlasEvidenceLedger $ledger,
        private readonly AtlasRivalsStrategyCaseRegistrar $rivalsRegistrar,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function packet(array $input): array
    {
        $title = $this->string($input['title'] ?? null);
        $decision = $this->string($input['decision'] ?? null);
        $impact = $this->impact($input['impact'] ?? null);
        $horizonDays = max(1, min(3650, (int) ($input['horizon_days'] ?? 90)));
        $options = AiStringListNormalizer::uniqueTruthyTrimmedCastValues($input['options'] ?? []);
        $values = AiStringListNormalizer::uniqueTruthyTrimmedCastValues($input['values'] ?? []);
        $constraints = AiStringListNormalizer::uniqueTruthyTrimmedCastValues($input['constraints'] ?? []);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'ok',
            'mode' => 'plan_only',
            'domain' => 'strategic_decision',
            'flow' => 'strategic_decision.review',
            'title' => $title,
            'decision_frame' => [
                'decision' => $decision,
                'impact' => $impact,
                'horizon_days' => $horizonDays,
                'options' => $options,
                'constraints' => $constraints,
                'missing_inputs' => $this->missingInputs($title, $decision, $options, $values),
            ],
            'cooldown' => $this->cooldown($impact, $horizonDays),
            'counterargument' => $this->counterargument($decision, $options),
            'values_alignment' => $this->valuesAlignment($values),
            'rivals_strategy' => [
                'recommended' => true,
                'required_for_high_impact' => in_array($impact, ['high', 'critical'], true),
                'register_command' => sprintf(
                    'atlas ai rivals-strategy register-case --title=%s --baseline=%s --atlas=%s --horizon=%d --json',
                    escapeshellarg($title !== '' ? $title : 'strategic decision'),
                    escapeshellarg($decision !== '' ? $decision : 'baseline choice pending'),
                    escapeshellarg('Atlas-assisted review packet'),
                    $horizonDays,
                ),
                'review_horizons_days' => [30, 90, 180, 365],
            ],
            'gates' => $this->gates($title, $decision, $options, $values, $impact),
            'forbidden_actions' => [
                'autonomous_commitment',
                'auto_financial_execution',
                'auto_life_decision',
                'external_publish_or_mutation',
            ],
            'rules' => [
                'review_only' => true,
                'requires_human_approval' => true,
                'preserve_operator_agency' => true,
                'no_external_side_effects' => true,
            ],
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function auditedPacket(array $input): array
    {
        $packet = $this->packet($input);
        $tenantId = $this->string($input['tenant_id'] ?? 'default') ?: 'default';
        $operatorId = $this->string($input['operator_id'] ?? 'system') ?: 'system';
        $impact = (string) data_get($packet, 'decision_frame.impact', 'medium');
        $requiredGates = collect((array) $packet['gates'])
            ->pluck('id')
            ->filter()
            ->values()
            ->all();

        $envelope = $this->envelopes->create([
            'operator' => [
                'tenant_id' => $tenantId,
                'operator_id' => $operatorId,
            ],
            'origin' => [
                'surface_id' => $this->string($input['surface_id'] ?? 'atlas_cli_strategic_decision') ?: 'atlas_cli_strategic_decision',
                'surface_version' => 'atlas.strategic_decision.v1',
                'session_id' => $this->string($input['session_id'] ?? ''),
            ],
            'input' => [
                'primary_type' => 'text',
                'text' => trim(($packet['title'] ?? '')."\n".data_get($packet, 'decision_frame.decision', '')),
                'hints' => [
                    'domain' => 'strategic_decision',
                    'flow' => 'strategic_decision.review',
                    'mode' => 'plan_only',
                    'audit_only' => true,
                ],
                'locale' => 'pt-BR',
            ],
        ]);

        $receipt = $this->receipts->issue($envelope, [
            'dry_run' => true,
            'signed_by' => 'atlas.strategic_decision.review.v1',
            'domain' => 'strategic_decision',
            'flow' => 'strategic_decision.review',
            'risk' => $impact,
            'provider_selection' => [
                'primary' => 'none',
                'model' => 'not_applicable_plan_only',
                'fallbacks' => [],
                'selection_mode' => 'auto_best_allowed',
                'selection_reason' => 'Strategic decision review packet is deterministic and plan-only; no provider call is required.',
            ],
            'budgets' => [
                'max_prompt_tokens' => 0,
                'max_output_tokens' => 0,
                'max_cost_usd' => 0,
            ],
            'required_gates' => $requiredGates,
            'required_evidence' => ['strategic_decision_review_packet', 'operator_agency_gate', 'rivals_strategy_hint'],
            'repair_policy' => [
                'enabled' => false,
                'max_attempts' => 0,
            ],
            'metadata' => [
                'tenant_id' => $tenantId,
                'operator_id' => $operatorId,
                'review_only' => true,
                'no_external_side_effects' => true,
                'packet_schema_version' => self::SCHEMA_VERSION,
            ],
        ])->toArray();

        $this->ledger->recordDecisionIssued($receipt, [
            'tenant_id' => $tenantId,
            'operator_id' => $operatorId,
            'trace_id' => $envelope->audit->traceId,
            'correlation_id' => $envelope->audit->traceId,
            'emitter_stage' => 'atlas.strategic_decision.review',
            'emitter_version' => self::SCHEMA_VERSION,
        ]);

        $evidenceEvent = $this->ledger->record(LedgerEventType::EvidencePacked, [
            'envelope_id' => $envelope->envelopeId,
            'receipt_id' => $receipt['receipt_id'],
            'domain' => 'strategic_decision',
            'flow' => 'strategic_decision.review',
            'packet' => $packet,
            'rules' => $packet['rules'],
        ], [
            'tenant_id' => $tenantId,
            'operator_id' => $operatorId,
            'envelope_id' => $envelope->envelopeId,
            'receipt_id' => $receipt['receipt_id'],
            'trace_id' => $envelope->audit->traceId,
            'correlation_id' => $envelope->audit->traceId,
            'emitter_stage' => 'atlas.strategic_decision.review',
            'emitter_version' => self::SCHEMA_VERSION,
        ]);

        $completedEvent = $this->ledger->record(LedgerEventType::OperationCompleted, [
            'envelope_id' => $envelope->envelopeId,
            'receipt_id' => $receipt['receipt_id'],
            'domain' => 'strategic_decision',
            'flow' => 'strategic_decision.review',
            'status' => 'review_packet_generated',
            'mode' => 'plan_only',
            'no_external_side_effects' => true,
            'gate_status_counts' => collect((array) $packet['gates'])->countBy('status')->all(),
        ], [
            'tenant_id' => $tenantId,
            'operator_id' => $operatorId,
            'envelope_id' => $envelope->envelopeId,
            'receipt_id' => $receipt['receipt_id'],
            'trace_id' => $envelope->audit->traceId,
            'correlation_id' => $envelope->audit->traceId,
            'emitter_stage' => 'atlas.strategic_decision.review',
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
                    $evidenceEvent?->event_type,
                    $completedEvent?->event_type,
                ])),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $packet
     * @param  array<string,mixed>|null  $receipt
     * @return array<string,mixed>
     */
    public function registerRivalsCase(array $packet, ?array $receipt = null): array
    {
        $title = $this->string($packet['title'] ?? 'strategic decision');
        $decision = $this->string(data_get($packet, 'decision_frame.decision', ''));
        $options = AiStringListNormalizer::uniqueTruthyTrimmedCastValues(data_get($packet, 'decision_frame.options', []));
        $values = AiStringListNormalizer::uniqueTruthyTrimmedCastValues(data_get($packet, 'values_alignment.values', []));
        $constraints = AiStringListNormalizer::uniqueTruthyTrimmedCastValues(data_get($packet, 'decision_frame.constraints', []));
        $horizonDays = (int) data_get($packet, 'decision_frame.horizon_days', 90);

        $registration = $this->rivalsRegistrar->register([
            'title' => $title !== '' ? $title : 'strategic decision',
            'baseline_choice' => $decision !== '' ? $decision : implode(' | ', $options),
            'atlas_assisted_choice' => 'Atlas strategic_decision.review packet generated; see context_summary and gates.',
            'context_summary' => json_encode([
                'decision' => $decision,
                'options' => $options,
                'values' => $values,
                'constraints' => $constraints,
                'gates' => collect((array) ($packet['gates'] ?? []))->pluck('status', 'id')->all(),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'horizon_days' => $horizonDays,
            'decision_domain' => 'strategic_decision',
            'source' => 'atlas.strategic_decision.review',
            'mode' => 'explicit_operator_registration',
            'source_hash' => hash('sha256', json_encode([
                'packet_schema' => $packet['schema_version'] ?? self::SCHEMA_VERSION,
                'title' => $title,
                'decision' => $decision,
                'options' => $options,
                'values' => $values,
                'horizon_days' => $horizonDays,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
            'source_envelope_id' => $receipt['envelope_id'] ?? null,
            'source_receipt_id' => $receipt['receipt_id'] ?? null,
            'tags' => ['strategic_decision', 'rivals_strategy', 'operator_approved'],
        ]);

        if ($receipt !== null) {
            $this->ledger->record(LedgerEventType::LearningProposed, [
                'envelope_id' => $receipt['envelope_id'] ?? null,
                'receipt_id' => $receipt['receipt_id'] ?? null,
                'domain' => 'strategic_decision',
                'flow' => 'strategic_decision.review',
                'proposal_type' => 'rivals_strategy_case_registered',
                'case_id' => $registration['case_id'],
                'source_hash' => $registration['source_hash'],
                'no_external_side_effects' => true,
                'operator_approved' => true,
            ], [
                'tenant_id' => data_get($receipt, 'metadata.tenant_id', 'default'),
                'operator_id' => data_get($receipt, 'metadata.operator_id', 'system'),
                'envelope_id' => $receipt['envelope_id'] ?? 'unknown',
                'receipt_id' => $receipt['receipt_id'] ?? null,
                'trace_id' => data_get($receipt, 'metadata.trace_id'),
                'correlation_id' => $receipt['envelope_id'] ?? null,
                'emitter_stage' => 'atlas.strategic_decision.rivals_registration',
                'emitter_version' => AtlasRivalsStrategyCaseRegistrar::SCHEMA_VERSION,
            ]);
        }

        return $registration;
    }

    private function string(mixed $value): string
    {
        return trim((string) $value);
    }

    private function impact(mixed $value): string
    {
        $impact = strtolower($this->string($value));

        return in_array($impact, ['low', 'medium', 'high', 'critical'], true) ? $impact : 'medium';
    }

    /**
     * @param  array<int,string>  $options
     * @param  array<int,string>  $values
     * @return array<int,string>
     */
    private function missingInputs(string $title, string $decision, array $options, array $values): array
    {
        $missing = [];

        if ($title === '') {
            $missing[] = 'title';
        }
        if ($decision === '') {
            $missing[] = 'decision';
        }
        if ($options === []) {
            $missing[] = 'options';
        }
        if ($values === []) {
            $missing[] = 'values';
        }

        return $missing;
    }

    /**
     * @return array<string,mixed>
     */
    private function cooldown(string $impact, int $horizonDays): array
    {
        $hours = match ($impact) {
            'critical' => 72,
            'high' => 48,
            'medium' => 24,
            default => 0,
        };

        return [
            'required' => $hours > 0,
            'minimum_hours' => $hours,
            'revisit_after' => $hours > 0 ? now()->addHours($hours)->toJSON() : null,
            'horizon_days' => $horizonDays,
        ];
    }

    /**
     * @param  array<int,string>  $options
     * @return array<string,mixed>
     */
    private function counterargument(string $decision, array $options): array
    {
        return [
            'steelman_required' => true,
            'red_team_questions' => [
                'What evidence would make this decision obviously wrong?',
                'Which option preserves future optionality better?',
                'What hidden cost appears only after the first success?',
            ],
            'option_pressure_test' => collect($options)
                ->map(fn (string $option): array => [
                    'option' => $option,
                    'challenge' => "State the strongest case against {$option}.",
                ])
                ->values()
                ->all(),
            'decision_under_review' => $decision,
        ];
    }

    /**
     * @param  array<int,string>  $values
     * @return array<string,mixed>
     */
    private function valuesAlignment(array $values): array
    {
        return [
            'values' => $values,
            'questions' => [
                'Which value is protected by this decision?',
                'Which value is being traded away?',
                'Would future Vitor endorse the reasoning, not only the outcome?',
            ],
        ];
    }

    /**
     * @param  array<int,string>  $options
     * @param  array<int,string>  $values
     * @return array<int,array{id:string,status:string,reason:string}>
     */
    private function gates(string $title, string $decision, array $options, array $values, string $impact): array
    {
        return [
            $this->gate('decision_frame', $title !== '' && $decision !== '', 'Title and decision statement are required.'),
            $this->gate('multi_perspective_review', count($options) >= 2, 'At least two options are required for real comparison.'),
            $this->gate('values_alignment', $values !== [], 'Values must be explicit before strategic review.'),
            $this->gate('cooldown_policy', ! in_array($impact, ['high', 'critical'], true) || $title !== '', 'High-impact decisions require cool-down and named case.'),
            $this->gate('operator_agency', true, 'Packet is review-only and cannot execute commitments.'),
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
