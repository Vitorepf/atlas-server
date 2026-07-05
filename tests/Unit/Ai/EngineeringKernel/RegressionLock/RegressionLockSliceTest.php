<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel\RegressionLock;

use App\Services\Ai\EngineeringKernel\RegressionLock\RegressionLockLedger;
use App\Services\Ai\EngineeringKernel\RegressionLock\RegressionLockWriter;
use App\Services\Ai\EngineeringKernel\SovereignHonestyFloor;
use App\Services\Ai\EngineeringKernel\TrustLevel;
use Tests\Unit\Ai\EngineeringKernel\AcceptanceBundleFactory;
use PHPUnit\Framework\TestCase;

/**
 * OBRA #4 S1 — REGRESSION-LOCK como LEI. Prova os ACs congelados da spec
 * (docs/obra4-self-hardening-harness-spec-2026-07-05.md):
 *   AC-1.1 repair sem lock => o floor REFUSA (repaired_without_regression_lock)
 *   AC-1.2 repair com lock => PROMOTE; ledger carrega a entrada
 *   AC-1.3 caso flaky (red na sonda 3x) => QUARENTENA, nunca entra como lock de suíte
 *   AC-1.4 dedupe sticky por failure_signature (a 2ª ocorrência referencia a 1ª)
 *   AC-1.5 floor 11 -> 12 invariantes, FLOOR_VERSION v2, os 11 antigos com comportamento intacto
 */
final class RegressionLockSliceTest extends TestCase
{
    private string $ledgerPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerPath = sys_get_temp_dir().'/atlas-reglock-'.bin2hex(random_bytes(4)).'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->ledgerPath);
        parent::tearDown();
    }

    public function test_ac11_repaired_without_lock_is_refused(): void
    {
        $bundle = AcceptanceBundleFactory::honest(['repair' => ['attempts' => 2]]);
        $verdict = (new SovereignHonestyFloor)->certify($bundle, TrustLevel::Dev);

        $this->assertFalse($verdict->promoted());
        $this->assertContains('regression_locked_for_repaired', $verdict->blockers);
        $this->assertSame(
            'repaired_without_regression_lock',
            $verdict->invariants['regression_locked_for_repaired']['detail'],
        );
    }

    public function test_ac12_repaired_with_lock_promotes_and_ledger_has_the_entry(): void
    {
        $ledger = new RegressionLockLedger($this->ledgerPath);
        $lock = $ledger->lock([
            'failure_signature' => 'sig-ac12',
            'origin' => 'repo_verified_delivery',
            'failing_case' => 'tests/Unit/Generated/XTest.php',
            'locked_test_ref' => 'tests/Unit/Generated/XTest.php',
        ]);
        $this->assertNotSame('', (string) $lock['lock_ref']);
        $this->assertSame(RegressionLockLedger::STATUS_LOCKED, $lock['flake_status']);
        $this->assertNotNull($ledger->has('sig-ac12'), 'ledger persiste e recupera a entrada');

        $bundle = AcceptanceBundleFactory::honest([
            'repair' => ['attempts' => 1, 'regression_lock_ref' => (string) $lock['lock_ref']],
        ]);
        $verdict = (new SovereignHonestyFloor)->certify($bundle, TrustLevel::Dev);

        $this->assertTrue($verdict->promoted(), json_encode($verdict->blockers));
        $this->assertSame('pass', $verdict->invariants['regression_locked_for_repaired']['status']);
    }

    public function test_ac13_flaky_case_is_quarantined_never_locked(): void
    {
        $writer = new RegressionLockWriter(new RegressionLockLedger($this->ledgerPath));
        $runs = 0;
        $entry = $writer->lockRepairedFailure(
            ['failure_signature' => 'sig-flaky', 'origin' => 'dev', 'failing_case' => 'tests/FooTest.php'],
            function () use (&$runs): bool {
                $runs++;

                return $runs !== 2; // verde, vermelho => flaky
            },
        );

        $this->assertSame(RegressionLockLedger::STATUS_QUARANTINED, $entry['flake_status']);
        $this->assertSame('1/3', $entry['stability']);
        // A quarentena AINDA é um lock_ref válido (trancamos o CONHECIMENTO da falha) —
        // o floor aceita, a suíte não ganha teste flaky.
        $this->assertNotSame('', (string) $entry['lock_ref']);
    }

    public function test_ac13b_stable_case_locks_after_three_greens(): void
    {
        $writer = new RegressionLockWriter(new RegressionLockLedger($this->ledgerPath));
        $entry = $writer->lockRepairedFailure(
            ['failure_signature' => 'sig-stable', 'origin' => 'dev', 'failing_case' => 'tests/FooTest.php'],
            fn (): bool => true,
        );

        $this->assertSame(RegressionLockLedger::STATUS_LOCKED, $entry['flake_status']);
        $this->assertSame('3/3', $entry['stability']);
    }

    public function test_ac14_dedupe_is_sticky_per_signature(): void
    {
        $ledger = new RegressionLockLedger($this->ledgerPath);
        $writer = new RegressionLockWriter($ledger);

        $first = $writer->lockRepairedFailure(
            ['failure_signature' => 'sig-dup', 'origin' => 'dev', 'failing_case' => 'tests/ATest.php'],
            fn (): bool => true,
        );
        $second = $writer->lockRepairedFailure(
            ['failure_signature' => 'sig-dup', 'origin' => 'forge', 'failing_case' => 'tests/BTest.php'],
            fn (): bool => $this->fail('a sonda NUNCA roda para assinatura já trancada'),
        );

        $this->assertTrue((bool) ($second['deduped'] ?? false));
        $this->assertSame($first['lock_ref'], $second['lock_ref'], 'a 2ª ocorrência referencia a 1ª');
        $this->assertCount(1, $ledger->all(), 'zero entrada duplicada no ledger');
    }

    public function test_ac15_floor_has_twelve_invariants_v2_and_the_original_eleven_are_intact(): void
    {
        $this->assertStringContainsString('.v2', SovereignHonestyFloor::FLOOR_VERSION);

        $floor = new SovereignHonestyFloor;

        // Bundle honesto (sem repair) => promote; o invariante novo WAIVA — mudança só ADITIVA.
        $green = $floor->certify(AcceptanceBundleFactory::honest(), TrustLevel::Dev);
        $this->assertTrue($green->promoted(), json_encode($green->blockers));
        $this->assertCount(12, $green->invariants);
        $this->assertSame([
            'false_claim_blocked',
            'context_sufficiency',
            'mutation_kill_ratio',
            'changed_public_symbol_census',
            'security_free',
            'criteria_hash_frozen',
            'judge_diversity',
            'performance_budget',
            'migration_safety',
            'architecture_no_regression',
            'property_clean_for_tagged',
            'regression_locked_for_repaired',
        ], array_keys($green->invariants), 'os 11 originais intactos + 1 aditivo, na mesma ordem');

        // O fake-green canônico continua REFUSADO pelos invariantes originais.
        $fake = $floor->certify(AcceptanceBundleFactory::fakeGreenStub(), TrustLevel::Dev);
        $this->assertFalse($fake->promoted());
        $this->assertContains('false_claim_blocked', $fake->blockers);
    }
}
