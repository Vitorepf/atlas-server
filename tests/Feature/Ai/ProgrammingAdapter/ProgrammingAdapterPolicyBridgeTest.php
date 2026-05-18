<?php

namespace Tests\Feature\Ai\ProgrammingAdapter;

use App\Services\Ai\Programming\Kernel\AtlasDevMissionAdapter;
use App\Services\Ai\Programming\Kernel\ProgrammingPolicyBridge;
use Tests\Concerns\CreatesProgrammingAdapterTables;
use Tests\TestCase;

class ProgrammingAdapterPolicyBridgeTest extends TestCase
{
    use CreatesProgrammingAdapterTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createProgrammingAdapterTables();
    }

    protected function tearDown(): void
    {
        $this->dropProgrammingAdapterTables();
        parent::tearDown();
    }

    public function test_policy_bridge_allows_safe_action_when_runtime_unavailable(): void
    {
        $adapted = app(AtlasDevMissionAdapter::class)->adapt('rodar suite de testes phpunit');
        $decision = app(ProgrammingPolicyBridge::class)->evaluate('programming.qa', $adapted['mission']);

        $this->assertSame('allow', $decision['decision']);
        $this->assertSame('programming_policy_fallback', $decision['source']);
        $this->assertSame('policy_runtime_unavailable', $decision['reason']);
        $this->assertSame(64, strlen((string) $decision['receipt_hash']));
    }

    public function test_policy_bridge_requires_approval_for_high_risk_action_when_runtime_unavailable(): void
    {
        $adapted = app(AtlasDevMissionAdapter::class)->adapt('fazer deploy de billing em producao');
        $decision = app(ProgrammingPolicyBridge::class)->evaluate('programming.dev', $adapted['mission']);

        $this->assertSame('require_approval', $decision['decision']);
        $this->assertSame('programming_policy_fallback', $decision['source']);
        $this->assertNotEmpty($decision['risk_keywords']);
    }
}
