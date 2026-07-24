<?php

namespace App\Services\Ai\Domain;

use App\Services\Ai\Kernel\Decision\DecisionReceiptIssuer;
use App\Services\Ai\Kernel\Decision\DreyfusReceiptExtensionContract;
use App\Services\Ai\Kernel\Envelope\OperationEnvelopeFactory;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Kernel\Gates\PedagogyMatchesStageGate;
use App\Services\Ai\Kernel\Gates\WorkedExampleAppropriateForStageGate;
use App\Services\Ai\Learning\Dreyfus\DreyfusPedagogyPromptBuilder;
use App\Services\Ai\Learning\Dreyfus\DreyfusPedagogyResolver;
use App\Services\Ai\Learning\WorkedExample\ProcessFadingScheduler;
use App\Services\Ai\Learning\WorkedExample\WorkedExampleRenderer;
use App\Services\Ai\Learning\WorkedExample\WorkedExampleSelector;
use App\Services\Ai\Support\AiStringListNormalizer;

class LearningPlanService
{
    use DomainInputNormalization;

    public const SCHEMA_VERSION = 'atlas.learning.packet.v1';

    public function __construct(
        private readonly OperationEnvelopeFactory $envelopes,
        private readonly DecisionReceiptIssuer $receipts,
        private readonly AtlasEvidenceLedger $ledger,
        private readonly DreyfusPedagogyResolver $dreyfus,
        private readonly DreyfusPedagogyPromptBuilder $promptBuilder,
        private readonly DreyfusReceiptExtensionContract $dreyfusReceipt,
        private readonly PedagogyMatchesStageGate $pedagogyGate,
        private readonly WorkedExampleSelector $workedExamples,
        private readonly ProcessFadingScheduler $fading,
        private readonly WorkedExampleRenderer $workedExampleRenderer,
        private readonly WorkedExampleAppropriateForStageGate $workedExampleGate,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function packet(string $flow, array $input): array
    {
        $objective = $this->string($input['objective'] ?? $input['goal'] ?? '');
        $topic = $this->string($input['topic'] ?? $input['skill'] ?? '');
        $currentLevel = $this->string($input['current_level'] ?? 'unknown');
        $targetLevel = $this->string($input['target_level'] ?? '');
        $timeBudget = $this->string($input['time_budget'] ?? '');
        $resources = AiStringListNormalizer::uniqueTruthyTrimmedCastValues($input['resources'] ?? []);
        $constraints = AiStringListNormalizer::uniqueTruthyTrimmedCastValues($input['constraints'] ?? []);
        $dreyfus = $this->dreyfus->resolve([
            ...$input,
            'domain' => $input['domain'] ?? 'learning',
            'flow' => $flow,
            'topic' => $topic,
        ]);
        $pedagogyGate = $this->pedagogyGate->evaluate(['cognitive_decision' => [
            'dreyfus_stage_resolved' => $dreyfus['dreyfus_stage_resolved'],
            'pedagogy_mode_resolved' => $dreyfus['pedagogy_mode_resolved'],
        ]]);
        $workedExample = $flow === 'learning.worked_example'
            ? $this->workedExamplePacket($dreyfus, $input, (string) ($input['domain'] ?? 'learning'))
            : null;

        return array_filter([
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'ok',
            'mode' => $this->mode($flow),
            'domain' => 'learning',
            'flow' => $flow,
            'brief' => [
                'objective' => $objective,
                'topic' => $topic,
                'current_level' => $currentLevel,
                'target_level' => $targetLevel,
                'time_budget' => $timeBudget,
                'resources' => $resources,
                'constraints' => $constraints,
                'missing_inputs' => $this->missingInputs($objective, $topic, $targetLevel),
            ],
            'dreyfus' => [
                'resolution' => $dreyfus,
                'prompt_policy' => $this->dreyfus->promptPolicy($dreyfus),
                'prompt_builder' => $this->promptBuilder->build($dreyfus, [
                    'objective' => $objective,
                    'topic' => $topic,
                    'target_level' => $targetLevel,
                    'flow' => $flow,
                ]),
            ],
            'worked_example' => $workedExample,
            'learning_contract' => [
                'diagnostic_required' => true,
                'practice_loop_required' => true,
                'retrieval_practice_required' => true,
                'spaced_review_required' => true,
                'mastery_rubric_required' => true,
                'outcome_evidence_required' => true,
            ],
            'output_contract' => $this->outputContract($flow),
            'gates' => $this->gates($flow, $objective, $topic, $targetLevel, $pedagogyGate),
            'rules' => [
                'plan_only_until_operator_acceptance' => true,
                'does_not_mutate_calendar_or_tasks' => true,
                'does_not_promote_memory_without_review' => true,
                'learning_domain_is_not_core_learning_plane' => true,
            ],
            'forbidden_actions' => [
                'auto_schedule_calendar',
                'auto_create_tasks',
                'claim_mastery_without_evidence',
                'promote_learning_result_without_review',
                'confuse_domain_learning_with_core_learning_plane',
            ],
            'generated_at' => now()->toJSON(),
        ], fn (mixed $value): bool => $value !== null);
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
        $cognitiveDecision = $this->dreyfusReceipt->cognitiveDecision((array) data_get($packet, 'dreyfus.resolution', []));

        $envelope = $this->envelopes->create([
            'operator' => [
                'tenant_id' => $tenantId,
                'operator_id' => $operatorId,
            ],
            'origin' => [
                'surface_id' => $this->string($input['surface_id'] ?? 'atlas_domain_orchestrator_learning') ?: 'atlas_domain_orchestrator_learning',
                'surface_version' => 'atlas.learning.v1',
                'session_id' => $this->string($input['session_id'] ?? ''),
            ],
            'input' => [
                'primary_type' => 'text',
                'text' => trim(data_get($packet, 'brief.objective', '')."\n".data_get($packet, 'brief.topic', '')),
                'hints' => [
                    'domain' => 'learning',
                    'flow' => $flow,
                    'mode' => $packet['mode'],
                    'audit_only' => true,
                    ...$this->dreyfusReceipt->envelopeHints((array) data_get($packet, 'dreyfus.resolution', [])),
                ],
                'locale' => 'pt-BR',
            ],
        ]);

        $receipt = $this->receipts->issue($envelope, [
            'dry_run' => true,
            'signed_by' => 'atlas.learning.packet.v1',
            'domain' => 'learning',
            'flow' => $flow,
            'risk' => 'low',
            'provider_selection' => [
                'primary' => 'none',
                'model' => 'not_applicable_learning_packet',
                'fallbacks' => [],
                'selection_mode' => 'auto_best_allowed',
                'selection_reason' => 'Learning orchestrator emits a governed learning packet; provider synthesis is a later runtime stage.',
            ],
            'budgets' => [
                'max_prompt_tokens' => 0,
                'max_output_tokens' => 0,
                'max_cost_usd' => 0,
            ],
            'required_gates' => $requiredGates,
            'required_evidence' => ['learning_packet', 'practice_loop', 'mastery_rubric'],
            'repair_policy' => [
                'enabled' => true,
                'max_attempts' => 1,
                'strategy' => 'request_objective_topic_or_target_level',
            ],
            'metadata' => [
                'tenant_id' => $tenantId,
                'operator_id' => $operatorId,
                'plan_only_until_operator_acceptance' => true,
                'learning_domain_is_not_core_learning_plane' => true,
                'packet_schema_version' => self::SCHEMA_VERSION,
                'cognitive_decision' => $cognitiveDecision,
                'worked_example_request' => data_get($packet, 'worked_example.status') ? [
                    'schema_version' => 'atlas.decide.extension.worked_example.v1',
                    'status' => data_get($packet, 'worked_example.status'),
                    'knowledge_node_id' => data_get($packet, 'dreyfus.resolution.knowledge_node_id'),
                    'fading_level_resolved' => data_get($packet, 'worked_example.fading.fading_level_resolved'),
                    'source' => data_get($packet, 'worked_example.render.source'),
                    'worked_example_id' => data_get($packet, 'worked_example.render.worked_example_id'),
                ] : null,
            ],
        ])->toArray();

        $this->ledger->recordDecisionIssued($receipt, [
            'tenant_id' => $tenantId,
            'operator_id' => $operatorId,
            'trace_id' => $envelope->audit->traceId,
            'correlation_id' => $envelope->audit->traceId,
            'emitter_stage' => 'atlas.learning.packet',
            'emitter_version' => self::SCHEMA_VERSION,
        ]);

        $this->ledger->record(LedgerEventType::DreyfusPedagogyModeResolved, [
            'schema_version' => 'atlas.cognitive.dreyfus_pedagogy_mode_resolved.v1',
            'knowledge_node_id' => data_get($packet, 'dreyfus.resolution.knowledge_node_id'),
            'domain' => 'learning',
            'flow' => $flow,
            'dreyfus_stage_resolved' => data_get($packet, 'dreyfus.resolution.dreyfus_stage_resolved'),
            'pedagogy_mode_resolved' => data_get($packet, 'dreyfus.resolution.pedagogy_mode_resolved'),
            'confidence' => data_get($packet, 'dreyfus.resolution.confidence'),
            'source' => data_get($packet, 'dreyfus.resolution.source'),
        ], [
            'tenant_id' => $tenantId,
            'operator_id' => $operatorId,
            'envelope_id' => $envelope->envelopeId,
            'receipt_id' => $receipt['receipt_id'],
            'trace_id' => $envelope->audit->traceId,
            'correlation_id' => $envelope->audit->traceId,
            'emitter_stage' => 'atlas.learning.dreyfus',
            'emitter_version' => self::SCHEMA_VERSION,
        ]);

        $packed = $this->ledger->record(LedgerEventType::EvidencePacked, [
            'envelope_id' => $envelope->envelopeId,
            'receipt_id' => $receipt['receipt_id'],
            'domain' => 'learning',
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
            'emitter_stage' => 'atlas.learning.packet',
            'emitter_version' => self::SCHEMA_VERSION,
        ]);

        $workedExampleDelivered = null;
        if (data_get($packet, 'worked_example.status') === 'ok') {
            $workedExampleDelivered = $this->ledger->record(LedgerEventType::WorkedExampleDelivered, [
                'schema_version' => 'atlas.cognitive.worked_example_delivered.v1',
                'worked_example_id' => data_get($packet, 'worked_example.render.worked_example_id'),
                'knowledge_node_id' => data_get($packet, 'dreyfus.resolution.knowledge_node_id'),
                'domain' => 'learning',
                'flow' => $flow,
                'dreyfus_stage' => data_get($packet, 'dreyfus.resolution.dreyfus_stage_resolved'),
                'fading_level_resolved' => data_get($packet, 'worked_example.fading.fading_level_resolved'),
                'source' => data_get($packet, 'worked_example.render.source'),
            ], [
                'tenant_id' => $tenantId,
                'operator_id' => $operatorId,
                'envelope_id' => $envelope->envelopeId,
                'receipt_id' => $receipt['receipt_id'],
                'trace_id' => $envelope->audit->traceId,
                'correlation_id' => $envelope->audit->traceId,
                'emitter_stage' => 'atlas.learning.worked_example',
                'emitter_version' => self::SCHEMA_VERSION,
            ]);
        }

        return [
            'packet' => $packet,
            'receipt' => $receipt,
            'ledger' => [
                'envelope_id' => $envelope->envelopeId,
                'trace_id' => $envelope->audit->traceId,
                'events' => array_values(array_filter([
                    'ENVELOPE_CREATED',
                    'DECISION_ISSUED',
                    LedgerEventType::DreyfusPedagogyModeResolved->value,
                    $workedExampleDelivered?->event_type,
                    $packed?->event_type,
                ])),
            ],
        ];
    }

    private function mode(string $flow): string
    {
        return match ($flow) {
            'learning.practice' => 'deliberate_practice_plan',
            'learning.review' => 'learning_review',
            'learning.spaced_review' => 'spaced_review_plan',
            'learning.worked_example' => 'worked_example_plan',
            'learning.failure_review' => 'failure_review_plan',
            default => 'learning_plan',
        };
    }

    /**
     * @return array<string,bool>
     */
    private function outputContract(string $flow): array
    {
        return match ($flow) {
            'learning.practice' => ['drill_set' => true, 'feedback_loop' => true, 'difficulty_ladder' => true],
            'learning.review' => ['evidence_review' => true, 'gap_map' => true, 'next_iteration' => true],
            'learning.spaced_review' => ['review_schedule_proposal' => true, 'retrieval_prompts' => true, 'forgetting_risk' => true],
            'learning.worked_example' => ['worked_example' => true, 'fading_schedule' => true, 'operator_fill_hidden_steps' => true],
            'learning.failure_review' => ['failure_signature_review' => true, 'diversity_index' => true, 'repetition_alerts' => true],
            default => ['learning_path' => true, 'practice_plan' => true, 'mastery_rubric' => true],
        };
    }

    /**
     * @param  array<string,mixed>  $dreyfus
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function workedExamplePacket(array $dreyfus, array $input, string $domain): array
    {
        $selection = $this->workedExamples->select(
            (string) $dreyfus['knowledge_node_id'],
            $domain,
            $this->string($input['source_preference'] ?? 'any') ?: 'any',
        );

        if (($selection['status'] ?? null) !== 'selected') {
            return [
                'schema_version' => 'atlas.cognitive.worked_example_packet.v1',
                'status' => 'missing',
                'selection' => $selection,
            ];
        }

        $example = (array) $selection['selected'];
        $schedule = $this->fading->schedule((int) $dreyfus['dreyfus_stage_resolved'], (array) ($example['fading_levels'] ?? []));
        $gate = $this->workedExampleGate->evaluate([
            'domain' => $domain,
            'surface_id' => 'atlas_learning_packet',
            'dreyfus_stage' => $dreyfus['dreyfus_stage_resolved'],
            'fading_level_resolved' => $schedule['fading_level_resolved'],
        ]);

        if (($gate['status'] ?? null) !== 'passed') {
            return [
                'schema_version' => 'atlas.cognitive.worked_example_packet.v1',
                'status' => 'blocked',
                'selection' => $selection,
                'fading' => $schedule,
                'gate' => $gate,
            ];
        }

        return [
            'schema_version' => 'atlas.cognitive.worked_example_packet.v1',
            'status' => 'ok',
            'selection' => $selection,
            'fading' => $schedule,
            'gate' => $gate,
            'render' => $this->workedExampleRenderer->render($example, $schedule),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function missingInputs(string $objective, string $topic, string $targetLevel): array
    {
        $missing = [];

        foreach (['objective' => $objective, 'topic' => $topic, 'target_level' => $targetLevel] as $key => $value) {
            if ($value === '') {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    /**
     * @return array<int,array{id:string,status:string,reason:string}>
     */
    private function gates(string $flow, string $objective, string $topic, string $targetLevel, array $pedagogyGate): array
    {
        return [
            $this->gate('learning_objective', $objective !== '', 'Learning objective is required.'),
            $this->gate('skill_scope', $topic !== '', 'Topic or skill scope must be explicit.'),
            $this->gate('target_level', $targetLevel !== '', 'Target level must be explicit.'),
            $this->gate('practice_loop', true, 'Every learning packet includes deliberate practice loop.'),
            $this->gate('mastery_rubric', true, 'Every learning packet includes mastery rubric.'),
            $this->gate('spaced_review', $flow !== 'learning.spaced_review' || ($objective !== '' && $topic !== ''), 'Spaced review requires objective and topic.'),
            [
                'id' => 'pedagogy_matches_stage',
                'status' => $pedagogyGate['status'],
                'reason' => $pedagogyGate['reason'],
            ],
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
