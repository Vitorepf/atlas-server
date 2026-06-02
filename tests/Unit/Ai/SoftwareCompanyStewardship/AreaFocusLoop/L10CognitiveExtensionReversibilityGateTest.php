<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L10CognitiveExtensionReversibilityGate;
use PHPUnit\Framework\TestCase;

final class L10CognitiveExtensionReversibilityGateTest extends TestCase
{
    private L10CognitiveExtensionReversibilityGate $gate;

    protected function setUp(): void
    {
        $this->gate = new L10CognitiveExtensionReversibilityGate();
    }

    /**
     * @return array<string, mixed>
     */
    private function validSession(): array
    {
        return [
            'operator_opt_in' => true,
            'reversible_detach' => true,
            'operator_override' => true,
            'audit_ready' => true,
            'audit_channels' => ['decision_receipt_ledger'],
            'coupling_active' => true,
        ];
    }

    public function testValidSessionIsAllowedWithAllReversibilitySurfacesReady(): void
    {
        $result = $this->gate->evaluate($this->validSession());

        $this->assertSame('atlas.aaeos.l10.cognitive_extension_reversibility_gate.v1', $result['schema_version']);
        $this->assertTrue($result['allowed']);
        $this->assertTrue($result['opt_in_present']);
        $this->assertTrue($result['detach_available']);
        $this->assertTrue($result['override_available']);
        $this->assertTrue($result['audit_ready']);
        $this->assertSame([], $result['blockers']);
    }

    public function testMissingDetachBlocks(): void
    {
        $session = $this->validSession();
        $session['reversible_detach'] = false;
        $session['detach_available'] = false;

        $result = $this->gate->evaluate($session);

        $this->assertFalse($result['allowed']);
        $this->assertFalse($result['detach_available']);
        $this->assertContains('reversible_detach_unavailable', $result['blockers']);
    }

    public function testPinnedDetachBlocksEvenWhenFlagSet(): void
    {
        // Anti-scaffold: a pinned/locked coupling overrides any detach flag.
        $session = $this->validSession();
        $session['reversible_detach'] = true;
        $session['detach_pinned'] = true;

        $result = $this->gate->evaluate($session);

        $this->assertFalse($result['detach_available']);
        $this->assertFalse($result['allowed']);
        $this->assertContains('reversible_detach_unavailable', $result['blockers']);
    }

    public function testMissingOverrideBlocks(): void
    {
        $session = $this->validSession();
        $session['operator_override'] = false;
        $session['override_available'] = false;

        $result = $this->gate->evaluate($session);

        $this->assertFalse($result['allowed']);
        $this->assertFalse($result['override_available']);
        $this->assertContains('operator_override_unavailable', $result['blockers']);
    }

    public function testLockedOverrideBlocksEvenWhenFlagSet(): void
    {
        // Anti-scaffold: a locked override overrides any override flag.
        $session = $this->validSession();
        $session['operator_override'] = true;
        $session['override_locked'] = true;

        $result = $this->gate->evaluate($session);

        $this->assertFalse($result['override_available']);
        $this->assertFalse($result['allowed']);
        $this->assertContains('operator_override_unavailable', $result['blockers']);
    }

    public function testSilentCouplingBlocks(): void
    {
        $session = $this->validSession();
        $session['silent_coupling'] = true;

        $result = $this->gate->evaluate($session);

        $this->assertFalse($result['allowed']);
        $this->assertContains('silent_coupling_rejected', $result['blockers']);
    }

    public function testCouplingWithoutAuditTrailIsSilentCouplingAndBlocks(): void
    {
        // An extension attached to the operator while leaving no audit trail is, by
        // definition, silent coupling - rejected on top of the audit-trail blocker.
        $session = [
            'operator_opt_in' => true,
            'reversible_detach' => true,
            'operator_override' => true,
            'extension_attached' => true,
            // no audit_ready, no audit_channels
        ];

        $result = $this->gate->evaluate($session);

        $this->assertFalse($result['allowed']);
        $this->assertFalse($result['audit_ready']);
        $this->assertContains('audit_trail_missing', $result['blockers']);
        $this->assertContains('silent_coupling_rejected', $result['blockers']);
    }

    public function testMissingExplicitOptInBlocks(): void
    {
        $session = $this->validSession();
        unset($session['operator_opt_in']);

        $result = $this->gate->evaluate($session);

        $this->assertFalse($result['allowed']);
        $this->assertFalse($result['opt_in_present']);
        $this->assertContains('explicit_opt_in_missing', $result['blockers']);
    }

    public function testAuditReadyViaDeclaredChannelWithoutExplicitFlag(): void
    {
        // Generalisation: audit readiness can come from a declared channel alone,
        // not only the explicit audit_ready flag.
        $session = $this->validSession();
        unset($session['audit_ready']);
        $session['audit_channels'] = ['evidence_ledger', 'operator_console'];

        $result = $this->gate->evaluate($session);

        $this->assertTrue($result['audit_ready']);
        $this->assertNotContains('audit_trail_missing', $result['blockers']);
        $this->assertTrue($result['allowed']);
    }

    public function testEmptyAuditChannelsDoNotCountAsAuditReady(): void
    {
        // Generalisation guard: empty-string channels must not satisfy auditability.
        $session = $this->validSession();
        unset($session['audit_ready']);
        $session['audit_channels'] = ['', ''];
        $session['coupling_active'] = false;

        $result = $this->gate->evaluate($session);

        $this->assertFalse($result['audit_ready']);
        $this->assertContains('audit_trail_missing', $result['blockers']);
    }

    public function testWhitespaceOnlyAuditChannelsDoNotCountAsAuditReady(): void
    {
        // Fail-closed guard: a blank/whitespace-only channel is not a real audit trail.
        // A coupled session that declares only whitespace channels must be rejected as
        // both audit-missing and silent coupling, never accidentally allowed.
        $session = [
            'operator_opt_in' => true,
            'reversible_detach' => true,
            'operator_override' => true,
            'coupling_active' => true,
            'audit_channels' => ['   ', "\t", "\n"],
        ];

        $result = $this->gate->evaluate($session);

        $this->assertFalse($result['audit_ready']);
        $this->assertFalse($result['allowed']);
        $this->assertContains('audit_trail_missing', $result['blockers']);
        $this->assertContains('silent_coupling_rejected', $result['blockers']);
    }

    public function testBlockersAccumulateInOrderAndAreAListOfStrings(): void
    {
        // Reversibility-and-auditability DoD: a session with none of the surfaces
        // accumulates every blocker in the fixed evaluation order.
        $result = $this->gate->evaluate([]);

        $this->assertFalse($result['allowed']);
        $this->assertSame(
            [
                'explicit_opt_in_missing',
                'audit_trail_missing',
                'reversible_detach_unavailable',
                'operator_override_unavailable',
            ],
            $result['blockers'],
        );

        // Honour list<string>: sequential int keys from 0, every value a string.
        $this->assertSame(
            range(0, count($result['blockers']) - 1),
            array_keys($result['blockers']),
        );
        foreach ($result['blockers'] as $blocker) {
            $this->assertIsString($blocker);
        }
    }

    public function testAllFiveDecisionFieldsAreFalseWhenAttachedSessionHasNoGuards(): void
    {
        // A coupled session with no opt-in, no detach, no override and no audit
        // fails every reversibility/auditability surface at once.
        $session = [
            'coupling_active' => true,
        ];

        $result = $this->gate->evaluate($session);

        $this->assertFalse($result['allowed']);
        $this->assertFalse($result['opt_in_present']);
        $this->assertFalse($result['detach_available']);
        $this->assertFalse($result['override_available']);
        $this->assertFalse($result['audit_ready']);
        $this->assertContains('silent_coupling_rejected', $result['blockers']);
    }

    public function testReadOnlyGateExposesNoRuntimeCouplingSideEffectKeys(): void
    {
        $result = $this->gate->evaluate($this->validSession());

        $this->assertArrayNotHasKey('coupling_activated', $result);
        $this->assertArrayNotHasKey('session_started', $result);
        $this->assertArrayNotHasKey('runtime_coupling', $result);
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $session = $this->validSession();

        $first = $this->gate->evaluate($session);
        $second = $this->gate->evaluate($session);

        $this->assertSame($first, $second);
    }
}
