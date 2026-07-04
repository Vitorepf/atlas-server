<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel;

use App\Services\Ai\EngineeringKernel\AcceptanceBundle;
use App\Services\Ai\EngineeringKernel\CertVerdict;
use App\Services\Ai\EngineeringKernel\ExecutionEvidence;
use App\Services\Ai\EngineeringKernel\TrustLevel;
use PHPUnit\Framework\TestCase;

/**
 * Slice 0 — pure contract. No behavior yet: the enum + DTOs construct and expose the shape the
 * floor will evaluate. Wiper-safe: zero DB, zero Laravel bootstrap.
 */
final class AcceptanceGateContractTest extends TestCase
{
    public function test_trust_level_has_three_cases_and_only_witness_set_varies(): void
    {
        $witnesses = array_map(
            static fn (TrustLevel $t): string => $t->witnessSet(),
            TrustLevel::cases(),
        );

        self::assertSame(['dev', 'forge', 'autonomos'], array_map(static fn (TrustLevel $t) => $t->value, TrustLevel::cases()));
        // three distinct witness-sets, one per level
        self::assertCount(3, array_unique($witnesses));
    }

    public function test_acceptance_bundle_from_array_normalizes_evidence(): void
    {
        $bundle = AcceptanceBundle::fromArray([
            'criteria_hash' => 'abc',
            'frozen_hash' => 'abc',
            'changed_files' => ['app/Foo.php'],
            'changed_public_symbols' => [['symbol' => 'Foo::bar', 'has_criterion' => true, 'has_test' => true]],
            'execution' => [
                'commands' => ['php artisan test tests/Unit/FooTest.php'],
                'claimed_status' => 'passed',
                'tests_run' => 4,
                'assertions_executed' => 11,
                'selected_tests' => ['tests/Unit/FooTest.php'],
                'artifacts' => [],
            ],
            'mutation_report' => ['kill_ratio' => 0.8, 'mutants_generated' => 12, 'decision_surface_added' => true],
            'security_scan' => ['ran' => true, 'secret_free' => true, 'critical_sast' => 0, 'critical_cve' => 0],
            'judges' => [['name' => 'j1', 'provider_family' => 'anthropic', 'approved' => true]],
            'context_sufficiency' => 90,
        ]);

        self::assertInstanceOf(ExecutionEvidence::class, $bundle->execution);
        self::assertSame('abc', $bundle->criteriaHash);
        self::assertSame(4, $bundle->execution->testsRun);
        self::assertSame(11, $bundle->execution->assertionsExecuted);
        self::assertSame('passed', $bundle->execution->claimedStatus);
    }

    public function test_cert_verdict_is_fail_closed_value(): void
    {
        $promote = CertVerdict::promote(['x' => ['status' => 'pass', 'detail' => 'ok']], 'w');
        $refuse = CertVerdict::refuse(['false_claim_blocked'], ['x' => ['status' => 'fail', 'detail' => 'no']], 'w');

        self::assertSame('promote', $promote->status);
        self::assertTrue($promote->promoted());
        self::assertSame([], $promote->blockers);

        self::assertSame('refuse', $refuse->status);
        self::assertFalse($refuse->promoted());
        self::assertContains('false_claim_blocked', $refuse->blockers);
        self::assertSame('w', $refuse->witnessSet);
    }
}
