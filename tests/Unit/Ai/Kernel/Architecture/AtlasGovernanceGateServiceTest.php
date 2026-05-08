<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasGovernanceGateService;
use Tests\TestCase;

class AtlasGovernanceGateServiceTest extends TestCase
{
    public function test_strict_gate_blocks_only_when_payload_gate_is_blocked(): void
    {
        $gate = app(AtlasGovernanceGateService::class);

        $this->assertTrue($gate->strictBlocked(['gate_status' => 'blocked'], true));
        $this->assertFalse($gate->strictBlocked(['gate_status' => 'blocked'], false));
        $this->assertFalse($gate->strictBlocked(['gate_status' => 'attention_required'], true));
        $this->assertSame(1, $gate->cliExitCode(['gate_status' => 'blocked'], true));
        $this->assertSame(0, $gate->cliExitCode(['gate_status' => 'passed'], true));
        $this->assertSame(409, $gate->httpStatus(['gate_status' => 'blocked'], true));
        $this->assertSame(200, $gate->httpStatus(['gate_status' => 'blocked'], false));
    }

    public function test_mcp_error_names_are_stable(): void
    {
        $gate = app(AtlasGovernanceGateService::class);

        $this->assertSame('session_bootstrap_blocked_by_strict_gate', $gate->mcpError('atlas_session_bootstrap'));
        $this->assertSame('feature_placement_blocked_by_strict_gate', $gate->mcpError('atlas_feature_placement'));
        $this->assertSame('governance_blocked_by_strict_gate', $gate->mcpError('unknown_tool'));
    }
}
