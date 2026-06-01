<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasPrivateConnectorsSecurityAndStackService;
use Tests\TestCase;

/**
 * Pins the contracts from the "Private Connectors Security And Stack" doc:
 * 13 connector types; 10 hard security rules; connectors start read-only and
 * write is disabled by default (write needs explicit approval); secrets in
 * model context and un-sandboxed browser/code are hard denials; and a stack
 * candidate is adoptable only with AP + source gate + implementation plan all
 * present (never automatic). Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/research-self-improvement/private-connectors-security-and-stack.md
 */
class AtlasPrivateConnectorsSecurityAndStackTest extends TestCase
{
    private function service(): AtlasPrivateConnectorsSecurityAndStackService
    {
        return new AtlasPrivateConnectorsSecurityAndStackService;
    }

    public function test_doc_defines_thirteen_connector_types_and_ten_security_rules(): void
    {
        $service = $this->service();

        $this->assertCount(13, $service->connectorTypes());
        $this->assertSame(13, AtlasPrivateConnectorsSecurityAndStackService::CONNECTOR_TYPE_COUNT);

        $this->assertCount(10, $service->securityRules());
        $this->assertSame(10, AtlasPrivateConnectorsSecurityAndStackService::SECURITY_RULE_COUNT);

        // The three secrets/sandbox/injection rules are the non-waivable ones.
        $hardLaw = array_values(array_filter(
            $service->securityRules(),
            static fn (array $r): bool => $r['hard_law'] === true,
        ));
        $this->assertSame(['SR-03', 'SR-04', 'SR-10'], array_column($hardLaw, 'id'));
    }

    public function test_fully_safe_read_only_request_is_admitted_without_granting_write(): void
    {
        $service = $this->service();

        $result = $service->evaluateConnectorRequest($service->fullySafeReadRequest('internal_github'));

        $this->assertTrue($result['admitted']);
        $this->assertSame('safe', $result['posture']);
        $this->assertSame(0, $result['violation_count']);
        $this->assertFalse($result['is_write_request']);
        // Read-only never "grants write"; write is disabled by default.
        $this->assertFalse($result['write_granted']);
        $this->assertFalse($result['runtime_authorized']);
    }

    public function test_write_request_without_approval_is_denied_but_with_approval_grants_write(): void
    {
        $service = $this->service();

        // Same fully-safe request, but escalated to a write mode.
        $write = $service->fullySafeReadRequest('filesystem');
        $write['access_mode'] = 'write';

        $denied = $service->evaluateConnectorRequest($write);
        $this->assertTrue($denied['is_write_request']);
        $this->assertFalse($denied['admitted']);                 // write disabled by default
        $this->assertContains('SR-05', $denied['violated_ids']);
        $this->assertFalse($denied['write_granted']);

        // Now add explicit approval: SR-05 (and SR-07 if sensitive) satisfied.
        $write['approval'] = true;
        $approved = $service->evaluateConnectorRequest($write);
        $this->assertTrue($approved['admitted']);
        $this->assertNotContains('SR-05', $approved['violated_ids']);
        $this->assertTrue($approved['write_granted']);
    }

    public function test_secrets_in_model_context_is_a_hard_denial(): void
    {
        $service = $this->service();

        $req = $service->fullySafeReadRequest('crm');
        $req['secrets_in_context'] = true;

        $result = $service->evaluateConnectorRequest($req);

        // Secrets never enter model context — admission blocked, no override.
        $this->assertFalse($result['admitted']);
        $this->assertSame('blocked', $result['posture']);
        $this->assertContains('SR-03', $result['violated_ids']);
    }

    public function test_browser_tool_without_sandbox_is_denied(): void
    {
        $service = $this->service();

        $req = $service->fullySafeReadRequest('private_search');
        $req['tool_kind'] = 'browser';
        $req['sandboxed'] = false;

        $result = $service->evaluateConnectorRequest($req);

        $this->assertFalse($result['admitted']);
        $this->assertContains('SR-04', $result['violated_ids']);
    }

    public function test_unknown_connector_type_is_not_admitted(): void
    {
        $service = $this->service();

        $req = $service->fullySafeReadRequest('not_a_real_connector');

        $result = $service->evaluateConnectorRequest($req);

        // All rules pass, but an unknown connector type is never admitted.
        $this->assertSame(0, $result['violation_count']);
        $this->assertFalse($result['connector_known']);
        $this->assertFalse($result['admitted']);
    }

    public function test_stack_candidate_requires_all_three_gate_legs_and_is_never_auto_approved(): void
    {
        $service = $this->service();

        // Missing the source gate ⇒ not adoptable.
        $partial = $service->evaluateStackAdoption([
            'name' => 'qdrant',
            'ap' => true,
            'source_gate' => false,
            'implementation_plan' => true,
        ]);
        $this->assertFalse($partial['adoptable']);
        $this->assertSame(['source_gate'], $partial['missing_legs']);
        $this->assertFalse($partial['auto_approved']);

        // All three legs present ⇒ adoptable, but still never auto-approved
        // and never authorizes runtime.
        $full = $service->evaluateStackAdoption([
            'name' => 'postgresql',
            'ap' => true,
            'source_gate' => true,
            'implementation_plan' => true,
        ]);
        $this->assertTrue($full['adoptable']);
        $this->assertSame([], $full['missing_legs']);
        $this->assertFalse($full['auto_approved']);
        $this->assertFalse($full['runtime_authorized']);
    }
}
