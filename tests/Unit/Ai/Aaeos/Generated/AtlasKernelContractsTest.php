<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasKernelContractsService;
use Tests\TestCase;

/**
 * Pins the executable Kernel Contracts from the doc: Core Object required
 * fields, the three documented state machines (with their named branch/terminal
 * edges), and the policy invariant "No lower layer can weaken hard Kernel
 * invariants." Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/kernel/contracts.md
 */
class AtlasKernelContractsTest extends TestCase
{
    private function service(): AtlasKernelContractsService
    {
        return new AtlasKernelContractsService;
    }

    public function test_operation_envelope_missing_audit_hash_is_invalid(): void
    {
        // Doc: Operation Envelope "Must contain ... audit hash". Drop it -> invalid.
        $payload = [
            'envelope_id' => 'env-1', 'operator_tenant' => 'op/tn', 'provenance' => ['x'],
            'input_refs' => ['r1'], 'state' => 'created', 'decision' => ['d'],
            'execution' => ['e'], 'output' => ['o'],
            // audit_hash intentionally omitted
        ];

        $v = $this->service()->validateObject('operation_envelope', $payload);

        $this->assertFalse($v['valid']);
        $this->assertSame(['audit_hash'], $v['missing']);
        $this->assertContains('missing_required_field:audit_hash', $v['reasons']);
        $this->assertTrue($v['known_object']);
    }

    public function test_full_decision_receipt_v2_payload_is_valid(): void
    {
        // All 9 documented Decision Receipt v2 fields present -> valid.
        $payload = [
            'receipt_id' => 'rcpt-1', 'schema' => 'atlas.receipt.v2',
            'selected' => ['domain' => 'd', 'flow' => 'f', 'provider' => 'p', 'model' => 'm'],
            'budget' => ['cap' => 10], 'gates' => ['g1'], 'repair_policy' => ['max' => 3],
            'dry_run' => false, 'signed_by' => 'kernel', 'hashes' => ['h1'],
        ];

        $v = $this->service()->validateObject('decision_receipt_v2', $payload);

        $this->assertTrue($v['valid']);
        $this->assertSame([], $v['missing']);
    }

    public function test_operation_repair_loop_edge_is_legal_but_decided_cannot_jump_to_completed(): void
    {
        $svc = $this->service();

        // Doc names the repair branch: executing -> repairing -> executing.
        $this->assertTrue($svc->canTransition('operation', 'executing', 'repairing'));
        $this->assertTrue($svc->canTransition('operation', 'repairing', 'executing'));

        // Illegal jump skipping executing.
        $jump = $svc->transition('operation', 'decided', 'completed');
        $this->assertFalse($jump['allowed']);
        $this->assertContains('illegal_transition', $jump['reasons']);
    }

    public function test_terminal_state_has_no_outgoing_transitions(): void
    {
        // Doc lists 'completed' among operation states; it is terminal.
        $t = $this->service()->transition('operation', 'completed', 'executing');

        $this->assertFalse($t['allowed']);
        $this->assertTrue($t['from_terminal']);
        $this->assertSame([], $t['allowed_next']);
        $this->assertContains('from_state_is_terminal', $t['reasons']);
    }

    public function test_decision_receipt_and_tool_run_happy_paths_and_unknown_state_rejected(): void
    {
        $svc = $this->service();

        // Decision receipt: issued -> attached -> consumed -> replayed.
        $this->assertTrue($svc->canTransition('decision_receipt', 'issued', 'attached'));
        $this->assertTrue($svc->canTransition('decision_receipt', 'attached', 'consumed'));
        $this->assertTrue($svc->canTransition('decision_receipt', 'consumed', 'replayed'));

        // Tool run: planned -> approved -> invoked -> returned -> normalized -> evidenced.
        $this->assertTrue($svc->canTransition('tool_run', 'planned', 'approved'));
        $this->assertTrue($svc->canTransition('tool_run', 'normalized', 'evidenced'));

        // Unknown state is rejected, not silently allowed.
        $bad = $svc->transition('tool_run', 'planned', 'teleported');
        $this->assertFalse($bad['allowed']);
        $this->assertContains('unknown_to_state', $bad['reasons']);
    }

    public function test_policy_invariant_blocks_widening_and_allows_narrowing(): void
    {
        $svc = $this->service();
        $kernel = [
            'autonomy_level' => 2,
            'max_budget' => 100,
            'repair_limit' => 3,
            'quality_gates' => ['tests', 'lint'],
            'provider_allowlist' => ['claude', 'codex'],
        ];

        // Lower layer tries to weaken: more autonomy, bigger budget, drop a gate,
        // add an unlisted provider.
        $widen = $svc->evaluatePolicyOverride($kernel, [
            'autonomy_level' => 5,
            'max_budget' => 500,
            'quality_gates' => ['tests'],
            'provider_allowlist' => ['claude', 'rogue_provider'],
        ]);
        $this->assertFalse($widen['allowed']);
        $this->assertContains('weakens_invariant:autonomy_level', $widen['violations']);
        $this->assertContains('weakens_invariant:max_budget', $widen['violations']);
        $this->assertContains('drops_required_gate:lint', $widen['violations']);
        $this->assertContains('provider_outside_kernel_allowlist:rogue_provider', $widen['violations']);
        // On violation the Kernel ceiling stays effective, not the override.
        $this->assertSame($kernel, $widen['effective']);

        // Narrowing within the ceiling is allowed.
        $narrow = $svc->evaluatePolicyOverride($kernel, [
            'autonomy_level' => 1,
            'max_budget' => 50,
            'quality_gates' => ['tests', 'lint', 'security'],
            'provider_allowlist' => ['claude'],
        ]);
        $this->assertTrue($narrow['allowed']);
        $this->assertSame([], $narrow['violations']);
    }
}
