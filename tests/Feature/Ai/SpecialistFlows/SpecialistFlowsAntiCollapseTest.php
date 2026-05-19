<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SpecialistFlows;

use App\Services\Ai\Router\AtlasAiSpecialistFlowExecutionService;
use App\Services\Ai\Router\AtlasAiSpecialistFlowRuntimeService;
use App\Services\Ai\RouterRuntime\RouterRuntimeCanon;
use App\Services\Ai\SpecialistFlows\SpecialistFlowsRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Atlas AI Specialist Flows · Anti-Collapse Certification.
 *
 * Hard invariant: a non-programming intent NEVER silently lands in
 * `atlas_dev`, `atlas_forge`, `atlas_debug` or `atlas_review` once it goes
 * through the canonical pipeline:
 *
 *   intent → INTENT_TO_FLOW → SpecialistFlowsRegistry → RuntimeService
 *      → ExecutionService → handler config
 *
 * Each non-programming flow must:
 *   - resolve to its own dedicated flow_id (NOT a programming-anchored one);
 *   - emit a runtime contract with a non-`conversation` execution_mode;
 *   - emit an execution handler with a dedicated `handler_id` (not the
 *     generic `atlas_conversation_direct_handler`);
 *   - emit `forbidden_actions` specific to its domain.
 *
 * If any of these fail, finance/cyber/marketing traffic could silently get
 * routed to Atlas Dev, which is exactly the failure mode this test exists
 * to prevent.
 */
class SpecialistFlowsAntiCollapseTest extends TestCase
{
    #[DataProvider('nonProgrammingIntentProvider')]
    public function test_non_programming_intent_resolves_to_dedicated_flow(string $intent, string $expectedFlow): void
    {
        $mapped = RouterRuntimeCanon::INTENT_TO_FLOW[$intent] ?? null;
        $this->assertSame($expectedFlow, $mapped, "intent {$intent} must map to {$expectedFlow}");
        $this->assertNotContains($mapped, RouterRuntimeCanon::PROGRAMMING_FLOW_IDS, "intent {$intent} must NOT map to programming-anchored flow");
    }

    #[DataProvider('nonProgrammingFlowProvider')]
    public function test_runtime_service_emits_distinct_contract_per_flow(string $flowId, string $expectedExecutionMode, string $domainSpecificForbidden): void
    {
        $service = new AtlasAiSpecialistFlowRuntimeService;
        $data = $service->apply([
            'input_text' => "Specialist flow contract for {$flowId}",
            'payload' => [
                'atlas_ai_router' => [
                    'flow_id' => $flowId,
                    'flow_origin' => 'router_auto',
                    'routing_reason' => 'anti_collapse_certification',
                    'handoff_payload' => [
                        'surface_id' => 'atlas_mobile_ai',
                        'workspace_present' => false,
                    ],
                ],
            ],
        ]);

        $runtime = data_get($data, 'payload.specialist_flow_runtime');
        $this->assertIsArray($runtime, "runtime contract for {$flowId} must be emitted");
        $this->assertSame($flowId, $runtime['flow_id']);
        $this->assertSame($expectedExecutionMode, $runtime['execution_mode'], "execution_mode for {$flowId} must be {$expectedExecutionMode}, not generic conversation");
        $this->assertContains($domainSpecificForbidden, $runtime['forbidden_actions'] ?? [], "forbidden_actions for {$flowId} must include {$domainSpecificForbidden}");
        $this->assertIsArray($runtime['receipt'] ?? null);
        $this->assertNotEmpty($runtime['receipt']['contract_hash']);
    }

    #[DataProvider('nonProgrammingFlowProvider')]
    public function test_execution_service_emits_distinct_handler_per_flow(string $flowId, string $expectedExecutionMode, string $domainSpecificForbidden): void
    {
        $runtime = new AtlasAiSpecialistFlowRuntimeService;
        $execution = new AtlasAiSpecialistFlowExecutionService;

        $data = $runtime->apply([
            'input_text' => "Specialist flow handler for {$flowId}",
            'payload' => [
                'atlas_ai_router' => [
                    'flow_id' => $flowId,
                    'flow_origin' => 'router_auto',
                ],
            ],
        ]);

        $data = $execution->apply($data);
        $handlerPacket = data_get($data, 'payload.specialist_flow_execution');

        $this->assertIsArray($handlerPacket, "execution handler for {$flowId} must be emitted");
        $this->assertSame($flowId, $handlerPacket['flow_id']);
        if ($flowId !== RouterRuntimeCanon::FLOW_CONVERSATION) {
            $this->assertNotSame('atlas_conversation_direct_handler', $handlerPacket['handler_id'], "{$flowId} MUST NOT fall back to generic conversation handler");
        }
        $this->assertStringStartsWith('atlas_', $handlerPacket['handler_id']);
        $this->assertNotEmpty($handlerPacket['response_shape']);
        $this->assertNotEmpty($handlerPacket['audit_checks']);
        $this->assertNotEmpty($handlerPacket['completion_checks']);
        $this->assertNotEmpty($handlerPacket['failure_modes']);
    }

    public function test_registry_refuses_programming_anchored_flow_ids(): void
    {
        $registry = $this->app->make(SpecialistFlowsRegistry::class);
        foreach (RouterRuntimeCanon::PROGRAMMING_FLOW_IDS as $flowId) {
            $this->assertTrue($registry->isProgrammingAnchored($flowId), "{$flowId} must be marked programming-anchored");
            $this->assertFalse($registry->has($flowId), "registry MUST NOT own handler for programming-anchored flow {$flowId}");
        }
    }

    public function test_registry_owns_every_non_programming_specialist_flow(): void
    {
        $registry = $this->app->make(SpecialistFlowsRegistry::class);
        $owned = $registry->ownedFlowIds();

        $expected = [
            RouterRuntimeCanon::FLOW_CONVERSATION,
            RouterRuntimeCanon::FLOW_RESEARCH,
            RouterRuntimeCanon::FLOW_FINANCE,
            RouterRuntimeCanon::FLOW_MARKETING,
            RouterRuntimeCanon::FLOW_STRATEGY,
            RouterRuntimeCanon::FLOW_CYBER,
            RouterRuntimeCanon::FLOW_PERSONAL_DEVELOPMENT,
            RouterRuntimeCanon::FLOW_AUTOMATION,
        ];

        foreach ($expected as $flowId) {
            $this->assertContains($flowId, $owned, "{$flowId} must be owned by SpecialistFlowsRegistry");
        }
    }

    public function test_every_non_programming_flow_emits_handler_distinct_from_default(): void
    {
        $runtime = new AtlasAiSpecialistFlowRuntimeService;
        $execution = new AtlasAiSpecialistFlowExecutionService;
        $seen = [];

        foreach (self::nonProgrammingFlowProvider() as $row) {
            [$flowId] = $row;
            $data = $execution->apply($runtime->apply([
                'input_text' => "Distinct handler for {$flowId}",
                'payload' => ['atlas_ai_router' => ['flow_id' => $flowId]],
            ]));
            $handlerId = data_get($data, 'payload.specialist_flow_execution.handler_id');
            $this->assertNotNull($handlerId);
            $seen[$flowId] = $handlerId;
        }

        // Exclude atlas_conversation since it legitimately owns the
        // conversation handler; everyone else must have a unique handler id.
        $excludingConversation = array_filter($seen, fn (string $id): bool => $id !== 'atlas_conversation_direct_handler');
        $this->assertCount(count($seen) - 1, $excludingConversation, 'every non-conversation flow must have a dedicated handler');
        $unique = array_unique(array_values($excludingConversation));
        $this->assertCount(count($excludingConversation), $unique, 'handler ids must be unique across non-conversation flows');
    }

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function nonProgrammingIntentProvider(): array
    {
        return [
            'conversation' => [RouterRuntimeCanon::INTENT_CONVERSATION, RouterRuntimeCanon::FLOW_CONVERSATION],
            'research' => [RouterRuntimeCanon::INTENT_RESEARCH, RouterRuntimeCanon::FLOW_RESEARCH],
            'explain' => [RouterRuntimeCanon::INTENT_EXPLAIN, RouterRuntimeCanon::FLOW_EXPLAIN],
            'plan' => [RouterRuntimeCanon::INTENT_PLAN, RouterRuntimeCanon::FLOW_PLAN],
            'finance' => [RouterRuntimeCanon::INTENT_FINANCE, RouterRuntimeCanon::FLOW_FINANCE],
            'marketing' => [RouterRuntimeCanon::INTENT_MARKETING, RouterRuntimeCanon::FLOW_MARKETING],
            'strategy' => [RouterRuntimeCanon::INTENT_STRATEGY, RouterRuntimeCanon::FLOW_STRATEGY],
            'cyber' => [RouterRuntimeCanon::INTENT_CYBER, RouterRuntimeCanon::FLOW_CYBER],
            'personal_development' => [RouterRuntimeCanon::INTENT_PERSONAL_DEVELOPMENT, RouterRuntimeCanon::FLOW_PERSONAL_DEVELOPMENT],
            'automation' => [RouterRuntimeCanon::INTENT_AUTOMATION, RouterRuntimeCanon::FLOW_AUTOMATION],
            'unknown' => [RouterRuntimeCanon::INTENT_UNKNOWN, RouterRuntimeCanon::FLOW_CONVERSATION],
        ];
    }

    /**
     * @return array<string,array{0:string,1:string,2:string}>
     */
    public static function nonProgrammingFlowProvider(): array
    {
        return [
            'research' => [RouterRuntimeCanon::FLOW_RESEARCH, 'source_grounded_answer', 'hide_uncertainty'],
            'explain' => [RouterRuntimeCanon::FLOW_EXPLAIN, 'read_only_explanation', 'modify_workspace'],
            'plan' => [RouterRuntimeCanon::FLOW_PLAN, 'engineering_plan', 'modify_workspace'],
            'conversation' => [RouterRuntimeCanon::FLOW_CONVERSATION, 'conversation', 'pretend_workspace_access'],
            'finance' => [RouterRuntimeCanon::FLOW_FINANCE, 'analysis_with_assumptions', 'execute_live_trade_without_operator_approval'],
            'marketing' => [RouterRuntimeCanon::FLOW_MARKETING, 'campaign_plan', 'publish_without_operator_approval'],
            'strategy' => [RouterRuntimeCanon::FLOW_STRATEGY, 'decision_memo', 'claim_execution_happened'],
            'cyber' => [RouterRuntimeCanon::FLOW_CYBER, 'defensive_advisory', 'offensive_action_without_roe'],
            'personal_development' => [RouterRuntimeCanon::FLOW_PERSONAL_DEVELOPMENT, 'non_clinical_advisory', 'clinical_diagnosis'],
            'automation' => [RouterRuntimeCanon::FLOW_AUTOMATION, 'workflow_plan_only', 'destructive_action_without_operator_approval'],
        ];
    }
}
