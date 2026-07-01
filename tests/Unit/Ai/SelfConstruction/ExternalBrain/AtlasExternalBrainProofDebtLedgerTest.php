<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainProofDebtLedger;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainProofDebtLedgerTest extends TestCase
{
    private function ledger(): AtlasExternalBrainProofDebtLedger
    {
        return new AtlasExternalBrainProofDebtLedger;
    }

    private function fullProofTarget(string $target = 'FooService', array $overrides = []): array
    {
        return array_merge([
            'target' => $target,
            'has_tests' => true,
            'has_replay_proof' => true,
            'has_rollback_proof' => true,
            'has_contract_proof' => true,
        ], $overrides);
    }

    // ── AC: zero_debt_eligible_case ────────────────────────────────────────────

    public function test_zero_debt_eligible_case(): void
    {
        $r = $this->ledger()->ledger(['targets' => [$this->fullProofTarget()]]);

        $this->assertSame([], $r['targets']['FooService']['proof_debt']);
        $this->assertTrue($r['targets']['FooService']['eligible']);
        $this->assertContains('FooService', $r['eligible_targets']);
        $this->assertSame([], $r['held_targets']);
    }

    // ── AC: proof_debt_hold_case ────────────────────────────────────────────────

    public function test_proof_debt_hold_case_names_missing_tests(): void
    {
        $r = $this->ledger()->ledger(['targets' => [$this->fullProofTarget('BarService', ['has_tests' => false])]]);

        $this->assertContains('tests', $r['targets']['BarService']['proof_debt']);
        $this->assertFalse($r['targets']['BarService']['eligible']);
        $this->assertContains('BarService', $r['held_targets']);
        $this->assertNotContains('BarService', $r['eligible_targets']);
    }

    public function test_proof_debt_hold_case_names_missing_replay_rollback_contract(): void
    {
        $r = $this->ledger()->ledger(['targets' => [
            $this->fullProofTarget('BazService', [
                'has_replay_proof' => false,
                'has_rollback_proof' => false,
                'has_contract_proof' => false,
            ]),
        ]]);

        $debt = $r['targets']['BazService']['proof_debt'];
        $this->assertContains('replay', $debt);
        $this->assertContains('rollback', $debt);
        $this->assertContains('contract', $debt);
        $this->assertNotContains('tests', $debt);
    }

    // ── AC: aggregates required_prework per target ─────────────────────────────

    public function test_required_prework_names_exact_missing_proof_per_target(): void
    {
        $r = $this->ledger()->ledger(['targets' => [$this->fullProofTarget('QuxService', ['has_tests' => false])]]);

        $this->assertContains('provide_tests_proof_for_QuxService', $r['targets']['QuxService']['required_prework']);
    }

    public function test_required_prework_empty_when_no_debt(): void
    {
        $r = $this->ledger()->ledger(['targets' => [$this->fullProofTarget()]]);

        $this->assertSame([], $r['targets']['FooService']['required_prework']);
    }

    // ── Multiple targets ─────────────────────────────────────────────────────

    public function test_multiple_targets_split_into_eligible_and_held(): void
    {
        $r = $this->ledger()->ledger(['targets' => [
            $this->fullProofTarget('Clean'),
            $this->fullProofTarget('Dirty', ['has_rollback_proof' => false]),
        ]]);

        $this->assertContains('Clean', $r['eligible_targets']);
        $this->assertContains('Dirty', $r['held_targets']);
    }

    // ── Determinism ────────────────────────────────────────────────────────────

    public function test_ledger_is_deterministic(): void
    {
        $facts = ['targets' => [$this->fullProofTarget('Foo', ['has_tests' => false])]];
        $a = $this->ledger()->ledger($facts);
        $b = $this->ledger()->ledger($facts);

        $this->assertSame(json_encode($a), json_encode($b));
    }

    public function test_schema_version_present(): void
    {
        $r = $this->ledger()->ledger([]);
        $this->assertSame(AtlasExternalBrainProofDebtLedger::SCHEMA, $r['schema']);
    }

    public function test_empty_targets_yields_empty_ledger(): void
    {
        $r = $this->ledger()->ledger([]);

        $this->assertSame([], $r['targets']);
        $this->assertSame([], $r['eligible_targets']);
        $this->assertSame([], $r['held_targets']);
    }
}
