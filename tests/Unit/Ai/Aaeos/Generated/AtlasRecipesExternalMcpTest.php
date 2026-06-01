<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasRecipesExternalMcpService;
use Tests\TestCase;

/**
 * Pins the documented external offensive MCP wrapped-recipe governance rules.
 *
 * @see docs/engineering-knowledge-base/cyber-security/recipes-external-mcp.md
 */
class AtlasRecipesExternalMcpTest extends TestCase
{
    private function service(): AtlasRecipesExternalMcpService
    {
        return new AtlasRecipesExternalMcpService();
    }

    /**
     * @return array<string,mixed> the fully-compliant HexStrike candidate.
     */
    private function compliantHexStrike(): array
    {
        return [
            'tool_slug' => 'hexstrike-mcp',
            'recipe_name' => 'pentest-orchestrate',
            'integration' => AtlasRecipesExternalMcpService::INTEGRATION_MCP_SERVER,
            'wrapper_skill' => 'cyber-hexstrike-runner',
            'sandbox' => AtlasRecipesExternalMcpService::SANDBOX_DEDICATED_VM,
            'adr_recorded' => true,
            'extra_approval' => true,
            'enforced_controls' => AtlasRecipesExternalMcpService::REQUIRED_CONTROLS,
        ];
    }

    /** HexStrike candidate with every control => admit, T2, derived wrapper name. */
    public function test_compliant_hexstrike_candidate_is_admitted(): void
    {
        $r = $this->service()->evaluateRegistration($this->compliantHexStrike());

        $this->assertSame(AtlasRecipesExternalMcpService::VERDICT_ADMIT, $r['verdict']);
        $this->assertTrue($r['admitted']);
        $this->assertSame([], $r['violations']);
        $this->assertSame('T2', $r['execution_tier']);
        $this->assertTrue($r['extra_approval_required']);
        // Wrapper name is derived from the slug: hexstrike-mcp => cyber-hexstrike-runner.
        $this->assertSame('cyber-hexstrike-runner', $r['expected_wrapper_skill']);
    }

    /**
     * Anti-Pattern: "Never paste an offensive MCP server directly into a provider
     * config." A raw integration is rejected as bypassing governance.
     */
    public function test_raw_provider_config_integration_is_rejected_as_bypass(): void
    {
        $candidate = $this->compliantHexStrike();
        $candidate['integration'] = 'mcp_servers'; // raw provider-config entry, not wrapped

        $r = $this->service()->evaluateRegistration($candidate);

        $this->assertSame(AtlasRecipesExternalMcpService::VERDICT_REJECT, $r['verdict']);
        $codes = array_column($r['violations'], 'code');
        $this->assertContains('raw_integration_bypasses_governance', $codes);
    }

    /**
     * General External MCP Pattern step 5 + HexStrike Constraints: every mandatory
     * control must be enforced. Dropping kill_switch + no_vm_reuse => reject and
     * those exact controls reported missing.
     */
    public function test_missing_mandatory_controls_are_rejected_and_reported(): void
    {
        $candidate = $this->compliantHexStrike();
        $candidate['enforced_controls'] = array_values(array_diff(
            AtlasRecipesExternalMcpService::REQUIRED_CONTROLS,
            ['kill_switch', 'no_vm_reuse'],
        ));

        $r = $this->service()->evaluateRegistration($candidate);

        $this->assertSame(AtlasRecipesExternalMcpService::VERDICT_REJECT, $r['verdict']);
        $this->assertContains('kill_switch', $r['missing_controls']);
        $this->assertContains('no_vm_reuse', $r['missing_controls']);
        $this->assertContains('missing_required_controls', array_column($r['violations'], 'code'));
    }

    /**
     * HexStrike: "Extra approval always required" and a dedicated VM. Removing both
     * yields the two specific violation codes.
     */
    public function test_extra_approval_and_dedicated_vm_are_mandatory(): void
    {
        $candidate = $this->compliantHexStrike();
        $candidate['extra_approval'] = false;
        $candidate['sandbox'] = 'shared_container';

        $r = $this->service()->evaluateRegistration($candidate);
        $codes = array_column($r['violations'], 'code');

        $this->assertSame(AtlasRecipesExternalMcpService::VERDICT_REJECT, $r['verdict']);
        $this->assertContains('extra_approval_required', $codes);
        $this->assertContains('sandbox_not_dedicated_vm', $codes);
    }

    /**
     * Per-call Constraint: "kill-switch if the tool leaves declared scope" and
     * refusal-matrix cyber-ref-010 (target not in scope.in => refuse, no exception).
     * An out-of-scope target trips the kill-switch even with a valid receipt.
     */
    public function test_out_of_scope_target_engages_kill_switch(): void
    {
        $r = $this->service()->evaluateCall([
            'target' => 'evil.example.net',
            'scope_in' => ['app.acme-corp.test', 'api.acme-corp.test'],
            'has_decision_receipt' => true,
            'refusal_matrix_hit' => false,
            'rate_limited' => false,
        ]);

        $this->assertSame(AtlasRecipesExternalMcpService::CALL_KILL_SWITCH, $r['call_verdict']);
        $this->assertFalse($r['may_proceed']);
        $this->assertFalse($r['in_scope']);
        $this->assertTrue($r['obligations']['notify_operator']);
    }

    /**
     * Per-call Constraint: "apply Decision Receipt before every call." An in-scope
     * target with NO receipt is refused (not allowed to proceed).
     */
    public function test_missing_decision_receipt_refuses_in_scope_call(): void
    {
        $r = $this->service()->evaluateCall([
            'target' => 'app.acme-corp.test',
            'scope_in' => ['app.acme-corp.test'],
            'has_decision_receipt' => false,
            'refusal_matrix_hit' => false,
            'rate_limited' => false,
        ]);

        $this->assertSame(AtlasRecipesExternalMcpService::CALL_REFUSE, $r['call_verdict']);
        $this->assertFalse($r['may_proceed']);
        $this->assertTrue($r['in_scope']);
    }

    /** Happy path per-call: in scope, receipt present, no refusal, within rate limit => proceed. */
    public function test_in_scope_call_with_receipt_proceeds(): void
    {
        $r = $this->service()->evaluateCall([
            'target' => 'app.acme-corp.test',
            'scope_in' => ['app.acme-corp.test'],
            'has_decision_receipt' => true,
            'refusal_matrix_hit' => false,
            'rate_limited' => false,
        ]);

        $this->assertSame(AtlasRecipesExternalMcpService::CALL_PROCEED, $r['call_verdict']);
        $this->assertTrue($r['may_proceed']);
        $this->assertTrue($r['obligations']['evidence_ledger']);
    }
}
