<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\LiveCycle;

use App\Services\Ai\AutonomousEvolution\LiveCycle\Integration\AtlasLoopLiveCyclePhaseReceiptComposer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Proves the LiveCycle phase-receipt composer: 8 deterministic phase hashes ⇒ byte-identical root_hash
 * across two compositions; flipping ANY one phase hash changes root_hash (tamper detection); fewer than
 * 8 hashes ⇒ throws and emits no FACT (fail-closed, no fabrication); sub_ledger_links carry the
 * projection_outcome / cycle_git_contract / impact_receipt ids verbatim.
 */
final class AtlasLoopLiveCyclePhaseReceiptComposerTest extends TestCase
{
    private function phaseHashes(): array
    {
        return [
            hash('sha256', 'phase-1-orient'),
            hash('sha256', 'phase-2-comprehend'),
            hash('sha256', 'phase-3-leverage'),
            hash('sha256', 'phase-4-architect'),
            hash('sha256', 'phase-5-decompose'),
            hash('sha256', 'phase-6-implement'),
            hash('sha256', 'phase-7-certify'),
            hash('sha256', 'phase-8-close'),
        ];
    }

    public function test_root_hash_is_deterministic_across_two_compositions_of_same_input(): void
    {
        $hashes = $this->phaseHashes();
        $composer = new AtlasLoopLiveCyclePhaseReceiptComposer;

        $a = $composer->compose('cycle-1', $hashes);
        $b = $composer->compose('cycle-1', $hashes);

        $this->assertSame($a['root_hash'], $b['root_hash']);
        $this->assertSame(64, strlen($a['root_hash']), 'SHA-256 hex digest');
    }

    public function test_flipping_any_single_phase_hash_changes_root_hash(): void
    {
        $composer = new AtlasLoopLiveCyclePhaseReceiptComposer;
        $base = $this->phaseHashes();
        $baseRoot = $composer->compose('c1', $base)['root_hash'];

        foreach (range(0, 7) as $i) {
            $tampered = $base;
            $tampered[$i] = hash('sha256', 'TAMPERED-'.$i);
            $tamperedRoot = $composer->compose('c1', $tampered)['root_hash'];
            $this->assertNotSame($baseRoot, $tamperedRoot, "flipping phase {$i} must invalidate root_hash");
        }
    }

    public function test_fewer_than_eight_receipts_throws_fail_closed_no_fact_emitted(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/exactly 8 phase receipts/');
        (new AtlasLoopLiveCyclePhaseReceiptComposer)->compose('c1', array_slice($this->phaseHashes(), 0, 7));
    }

    public function test_missing_individual_phase_hash_string_throws_fail_closed(): void
    {
        $hashes = $this->phaseHashes();
        $hashes[3] = ''; // empty hash
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/phase receipt hash 3 is missing/');
        (new AtlasLoopLiveCyclePhaseReceiptComposer)->compose('c1', $hashes);
    }

    public function test_sub_ledger_links_carry_canonical_ids_when_supplied(): void
    {
        $envelope = (new AtlasLoopLiveCyclePhaseReceiptComposer)->compose('c1', $this->phaseHashes(), [
            'projection_outcome' => 'proj-rec-01',
            'cycle_git_contract' => 'git-rec-02',
            'impact_receipt' => 'imp-rec-03',
        ]);

        $this->assertSame('proj-rec-01', $envelope['sub_ledger_links']['projection_outcome']);
        $this->assertSame('git-rec-02', $envelope['sub_ledger_links']['cycle_git_contract']);
        $this->assertSame('imp-rec-03', $envelope['sub_ledger_links']['impact_receipt']);
    }

    public function test_fact_name_is_cycle_receipt_composed_and_envelope_carries_all_keys(): void
    {
        $envelope = (new AtlasLoopLiveCyclePhaseReceiptComposer)->compose('c1', $this->phaseHashes());
        $this->assertSame(AtlasLoopLiveCyclePhaseReceiptComposer::FACT_NAME, $envelope['fact']);
        $this->assertArrayHasKey('cycle_id', $envelope);
        $this->assertArrayHasKey('root_hash', $envelope);
        $this->assertArrayHasKey('phase_receipt_hashes', $envelope);
        $this->assertCount(8, $envelope['phase_receipt_hashes']);
        $this->assertArrayHasKey('sub_ledger_links', $envelope);
    }
}
