<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasAgentsAndMcpContractService;
use Tests\TestCase;

/**
 * Pins the MCP tool-authorization gate and contract invariants from the doc.
 * Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/spec-operating-system/agents-and-mcp-contract.md
 */
class AtlasAgentsAndMcpContractTest extends TestCase
{
    private function service(): AtlasAgentsAndMcpContractService
    {
        return new AtlasAgentsAndMcpContractService;
    }

    public function test_narrow_read_tool_is_allowed_but_grants_no_runtime_authority(): void
    {
        $d = $this->service()->decide(['tool' => 'read_file_context']);

        $this->assertSame(AtlasAgentsAndMcpContractService::VERDICT_ALLOW, $d['verdict']);
        $this->assertTrue($d['is_allowed_tool']);
        // "MCP is a surface, not authority."
        $this->assertFalse($d['grants_runtime_authority']);
        $this->assertTrue($this->service()->isPermitted(['tool' => 'inspect_drift']));
    }

    public function test_write_tool_without_receipt_is_blocked(): void
    {
        $d = $this->service()->decide(['tool' => 'apply_patch']);

        $this->assertSame(AtlasAgentsAndMcpContractService::VERDICT_BLOCK, $d['verdict']);
        $this->assertTrue($d['is_write_capable']);
        $this->assertContains('write_tool_blocked_missing_dedicated_receipt', $d['reasons']);
    }

    public function test_write_tool_with_receipt_but_no_human_gate_stays_blocked(): void
    {
        $d = $this->service()->decide([
            'tool' => 'create_pull_request',
            'decision_receipt' => 'AP-42',
            'human_gate' => false,
        ]);

        $this->assertSame(AtlasAgentsAndMcpContractService::VERDICT_BLOCK, $d['verdict']);
        $this->assertContains('write_tool_blocked_missing_human_gate', $d['reasons']);
    }

    public function test_write_tool_with_receipt_and_human_gate_requires_receipt_path(): void
    {
        $d = $this->service()->decide([
            'tool' => 'write_external_repo',
            'decision_receipt' => 'AP-42',
            'human_gate' => true,
        ]);

        $this->assertSame(
            AtlasAgentsAndMcpContractService::VERDICT_REQUIRES_RECEIPT,
            $d['verdict'],
        );
    }

    public function test_secrets_in_context_block_even_an_allowlisted_tool(): void
    {
        $d = $this->service()->decide([
            'tool' => 'read_file_context',
            'secrets_in_context' => true,
        ]);

        // "Secrets never enter model context." overrides the read allowlist.
        $this->assertSame(AtlasAgentsAndMcpContractService::VERDICT_BLOCK, $d['verdict']);
        $this->assertContains('secrets_never_enter_model_context', $d['reasons']);
    }

    public function test_raw_external_server_is_blocked_until_wrapped(): void
    {
        $raw = $this->service()->decide([
            'tool' => 'search_code_context',
            'external_server' => true,
            'wrapped_by_tool_runtime' => false,
        ]);
        $this->assertSame(AtlasAgentsAndMcpContractService::VERDICT_BLOCK, $raw['verdict']);
        $this->assertContains('external_server_not_wrapped_by_tool_runtime', $raw['reasons']);

        $wrapped = $this->service()->decide([
            'tool' => 'search_code_context',
            'external_server' => true,
            'wrapped_by_tool_runtime' => true,
        ]);
        $this->assertSame(AtlasAgentsAndMcpContractService::VERDICT_ALLOW, $wrapped['verdict']);
    }

    public function test_unknown_tool_is_blocked_by_closed_allowlist(): void
    {
        $d = $this->service()->decide(['tool' => 'delete_production_database']);

        $this->assertSame(AtlasAgentsAndMcpContractService::VERDICT_BLOCK, $d['verdict']);
        $this->assertContains('tool_not_on_closed_allowlist', $d['reasons']);
    }

    public function test_tool_output_validated_before_becoming_spec_evidence(): void
    {
        $svc = $this->service();

        $this->assertTrue($svc->mayBecomeSpecEvidence(['validated' => true]));
        $this->assertFalse($svc->mayBecomeSpecEvidence(['validated' => false]));
        // Validated but still carrying secrets must not become evidence.
        $this->assertFalse($svc->mayBecomeSpecEvidence([
            'validated' => true,
            'contains_secrets' => true,
        ]));
    }

    public function test_manifest_pins_fourteen_agents_and_closed_allowlists(): void
    {
        $m = $this->service()->manifest();

        $this->assertSame(14, $m['agent_count']);
        $this->assertCount(14, $m['agents']);
        $this->assertCount(7, $m['allowed_tools']);
        $this->assertCount(8, $m['prompts']);
        $this->assertCount(10, $m['resources']);
        $this->assertSame('mcp_is_a_surface_not_authority', $m['authority']);
        $this->assertContains('secrets_never_enter_model_context', $m['invariants']);
    }
}
