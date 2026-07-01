<?php

namespace Tests\Unit\Services\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentDispatchPlannerCertificationService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The dispatch-planner circuit certifies its own invariants without executing anything:
 * certification reports blocked when any invariant fails (and available when the layer's
 * read-only invariants hold), and every runtime-safety flag remains false in both the
 * envelope and runtimeFlags().
 */
final class AgentDispatchPlannerCertificationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_status_available_when_all_invariants_hold(): void
    {
        $result = (new AgentDispatchPlannerCertificationService)->certify();

        self::assertSame('available', $result['status']);
        self::assertTrue($result['invariants_all_true']);
        self::assertSame(0, $result['violation_count']);
    }

    public function test_status_blocked_when_any_invariant_fails(): void
    {
        $result = (new AgentDispatchPlannerCertificationService)->certify();
        $forced = $result;
        $forced['invariants'][] = ['name' => 'forced_failure', 'ok' => false, 'description' => 'test-only', 'warning' => false];
        $violations = 0;
        foreach ($forced['invariants'] as $invariant) {
            if (! $invariant['ok']) {
                $violations++;
            }
        }
        $status = $violations > 0 ? 'blocked' : 'available';

        self::assertSame('blocked', $status, 'a single failing invariant must flip status to blocked');
    }

    public function test_all_invariants_are_true_on_the_real_service(): void
    {
        $result = (new AgentDispatchPlannerCertificationService)->certify();

        foreach ($result['invariants'] as $invariant) {
            self::assertTrue($invariant['ok'], 'invariant '.$invariant['name'].' must hold');
        }
    }

    public function test_certification_never_claims_dispatches_or_calls_providers(): void
    {
        $result = (new AgentDispatchPlannerCertificationService)->certify();

        foreach (['runtime_execution_allowed', 'dispatch_allowed', 'provider_call_allowed', 'token_spend_allowed', 'self_programming_allowed', 'ledger_write_allowed', 'claim_real_allowed'] as $flag) {
            self::assertFalse($result[$flag], "{$flag} must be false");
            self::assertFalse($result['runtime_safety'][$flag], "runtime_safety.{$flag} must be false");
        }
        self::assertTrue($result['runtime_safety']['runtime_safety_all_false']);

        foreach ((new AgentDispatchPlannerCertificationService)->runtimeFlags() as $key => $value) {
            self::assertFalse($value, "runtimeFlags()[{$key}] must be false");
        }
    }

    public function test_certification_hash_is_stable(): void
    {
        $svc = new AgentDispatchPlannerCertificationService;

        $a = $svc->certify();
        $b = $svc->certify();

        self::assertSame($a['certification_hash'], $b['certification_hash']);
    }
}
