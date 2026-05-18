<?php

namespace Tests\Feature\Ai\RouterRuntime;

use App\Models\AiAtlasIntentClassification;
use App\Models\AiAtlasRouterDecision;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\RouterRuntime\FlowRouterService;
use App\Services\Ai\RouterRuntime\IntentKernelService;
use App\Services\Ai\RouterRuntime\RouterRuntimeCanon;
use App\Services\Ai\RouterRuntime\RuntimeDispatchService;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesRouterRuntimeTables;
use Tests\TestCase;

class DispatchBlockedAmbiguousTest extends TestCase
{
    use CreatesRouterRuntimeTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRouterRuntimeTables();
    }

    protected function tearDown(): void
    {
        $this->dropRouterRuntimeTables();
        parent::tearDown();
    }

    public function test_unknown_prompt_classifies_as_unknown_with_high_ambiguity(): void
    {
        $intent = app(IntentKernelService::class)->classify('xyz');
        $this->assertSame(RouterRuntimeCanon::INTENT_UNKNOWN, $intent->intent_type);
        $this->assertGreaterThanOrEqual(0.5, (float) $intent->ambiguity_score);
    }

    public function test_router_decision_with_blocked_mode_yields_blocked_dispatch(): void
    {
        // Build an intent record directly so we can force a routing_mode=blocked
        // decision and prove that the dispatch service propagates the blocker.
        $intent = AiAtlasIntentClassification::query()->create([
            'uuid' => (string) Str::uuid(),
            'mission_id' => null,
            'raw_input' => 'forced blocked test',
            'normalized_intent' => 'forced blocked test',
            'intent_type' => RouterRuntimeCanon::INTENT_UNKNOWN,
            'ambiguity_score' => 0.95,
            'confidence' => 0.10,
            'signals' => ['matched_keywords' => []],
            'status' => 'classified',
        ]);

        $decision = AiAtlasRouterDecision::query()->create([
            'uuid' => (string) Str::uuid(),
            'mission_id' => null,
            'work_order_id' => null,
            'intent_classification_id' => $intent->id,
            'primary_domain' => 'conversation',
            'secondary_domains' => [],
            'routing_mode' => RouterRuntimeCanon::MODE_BLOCKED,
            'decision_reason' => [
                'reasons' => ['routing_mode:blocked', 'clarification_needed:high_ambiguity'],
            ],
            'policy_required' => true,
            'evidence_required' => false,
            'tool_plan_required' => false,
            'status' => 'routed',
            'receipt_hash' => MissionCanonicalHash::sha256(['forced' => 'blocked']),
        ]);

        $flow = app(FlowRouterService::class)->decideFlow($decision, $intent);
        $dispatch = app(RuntimeDispatchService::class)->dispatch($decision, $flow, $intent);

        $this->assertSame(RouterRuntimeCanon::DISPATCH_BLOCKED, $dispatch->dispatch_status);
        $this->assertIsArray($dispatch->blockers);
        $this->assertContains('routing_mode_blocked', $dispatch->blockers);
        $this->assertContains('clarification_needed:high_ambiguity', $dispatch->blockers);
        $this->assertSame(64, strlen((string) $dispatch->receipt_hash));
    }
}
