<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Maestro\ProviderLearning\AtlasMaestroProviderPerformanceLedger;
use Tests\TestCase;

final class AtlasMaestroProviderPerformanceLedgerTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-maestro-perf-'.bin2hex(random_bytes(6));
        @mkdir($this->root, 0o755, true);
        AtlasMaestroProviderPerformanceLedger::setRootForTesting($this->root);
    }

    protected function tearDown(): void
    {
        AtlasMaestroProviderPerformanceLedger::setRootForTesting(null);
        foreach ((array) glob($this->root.'/*') as $f) {
            @unlink($f);
        }
        @rmdir($this->root);
        parent::tearDown();
    }

    public function test_record_outcome_is_deterministic_byte_identical_on_re_run(): void
    {
        $ledger = new AtlasMaestroProviderPerformanceLedger();
        $ledger->recordOutcome('codex', 'refactor', 'success', 1500, 1700000000);
        $ledger->recordOutcome('codex', 'refactor', 'success', 900, 1700000010);
        $blobA = (string) file_get_contents($ledger->snapshotPath());

        // Re-run the same sequence on a fresh root → must produce byte-identical bytes.
        AtlasMaestroProviderPerformanceLedger::setRootForTesting($this->root.'-fresh');
        @mkdir($this->root.'-fresh', 0o755, true);
        $ledgerB = new AtlasMaestroProviderPerformanceLedger();
        $ledgerB->recordOutcome('codex', 'refactor', 'success', 1500, 1700000000);
        $ledgerB->recordOutcome('codex', 'refactor', 'success', 900, 1700000010);
        $blobB = (string) file_get_contents($ledgerB->snapshotPath());
        @unlink($ledgerB->snapshotPath());
        @rmdir($this->root.'-fresh');
        AtlasMaestroProviderPerformanceLedger::setRootForTesting($this->root);

        $this->assertSame($blobA, $blobB, 'identical sequence must produce byte-identical snapshot');
    }

    public function test_facts_for_class_returns_aggregates_with_avg_duration(): void
    {
        $ledger = new AtlasMaestroProviderPerformanceLedger();
        $ledger->recordOutcome('codex', 'refactor', 'success', 1000, 1700000000);
        $ledger->recordOutcome('codex', 'refactor', 'success', 2000, 1700000010);
        $ledger->recordOutcome('codex', 'refactor', 'give_back', 500, 1700000020);

        $facts = $ledger->factsForClass('refactor');
        $this->assertArrayHasKey('codex', $facts);
        $this->assertSame(2, $facts['codex']['success_count']);
        $this->assertSame(1, $facts['codex']['give_back_count']);
        $this->assertSame(1167, $facts['codex']['avg_duration_ms']);
    }

    public function test_facts_for_class_unknown_returns_empty(): void
    {
        $ledger = new AtlasMaestroProviderPerformanceLedger();
        $this->assertSame([], $ledger->factsForClass('does_not_exist'));
    }

    public function test_atlas_native_is_first_class_provider_with_zero_cost_duration(): void
    {
        $ledger = new AtlasMaestroProviderPerformanceLedger();
        $ledger->recordOutcome(AtlasMaestroProviderPerformanceLedger::ATLAS_NATIVE, 'refactor', AtlasMaestroProviderPerformanceLedger::OUTCOME_SUCCESS, 0, 1700000000);
        $ledger->recordOutcome(AtlasMaestroProviderPerformanceLedger::ATLAS_NATIVE, 'refactor', AtlasMaestroProviderPerformanceLedger::OUTCOME_SUCCESS, 0, 1700000010);

        $facts = $ledger->factsForClass('refactor');
        $this->assertArrayHasKey('atlas_native', $facts, 'atlas_native must appear explicitly in facts');
        $this->assertSame(2, $facts['atlas_native']['success_count']);
        $this->assertSame(0, $facts['atlas_native']['give_back_count']);
        $this->assertSame(0, $facts['atlas_native']['avg_duration_ms'], 'zero-cost path must report avg_duration_ms=0');
    }

    public function test_unknown_outcome_and_negative_duration_do_not_contaminate_aggregates(): void
    {
        $ledger = new AtlasMaestroProviderPerformanceLedger();
        // Unknown outcome: must not increment success_count or give_back_count.
        $ledger->recordOutcome('codex', 'refactor', 'unknown_outcome', 5000, 1700000000);
        // Negative duration: must not contribute to avg_duration_ms.
        $ledger->recordOutcome('codex', 'refactor', AtlasMaestroProviderPerformanceLedger::OUTCOME_SUCCESS, -100, 1700000010);
        // Valid record: the only one that counts.
        $ledger->recordOutcome('codex', 'refactor', AtlasMaestroProviderPerformanceLedger::OUTCOME_SUCCESS, 1000, 1700000020);

        $facts = $ledger->factsForClass('refactor');
        // Both OUTCOME_SUCCESS calls count (negative duration is an invalid timing, not an invalid outcome).
        $this->assertSame(2, $facts['codex']['success_count']);
        $this->assertSame(0, $facts['codex']['give_back_count']);
        // Only the valid 1000ms duration is summed; unknown-outcome's 5000ms and negative -100ms are excluded.
        $this->assertSame(1000, $facts['codex']['avg_duration_ms'], 'invalid durations must not contaminate avg');
    }

    public function test_persists_three_providers_two_classes_round_trips_identically(): void
    {
        $a = new AtlasMaestroProviderPerformanceLedger();
        $records = [
            ['codex', 'refactor', 'success', 1000, 1700000000],
            ['codex', 'refactor', 'success', 1500, 1700000010],
            ['codex', 'docs', 'success', 700, 1700000020],
            ['claude', 'refactor', 'success', 1100, 1700000030],
            ['claude', 'docs', 'give_back', 400, 1700000040],
            ['minimax', 'refactor', 'give_back', 200, 1700000050],
            ['minimax', 'docs', 'success', 800, 1700000060],
        ];
        foreach ($records as $r) {
            $a->recordOutcome(...$r);
        }
        $blob1 = (string) file_get_contents($a->snapshotPath());

        $b = new AtlasMaestroProviderPerformanceLedger();
        $allA = $a->allFacts();
        $allB = $b->allFacts();
        $this->assertSame(json_encode($allA), json_encode($allB), 'fresh instance must read identical facts');
        $this->assertCount(2, $allA, 'two task_classes seeded');
        $this->assertCount(3, $allA['refactor'], 'three providers in refactor class');
        $blob2 = (string) file_get_contents($b->snapshotPath());
        $this->assertSame($blob1, $blob2, 'on-disk snapshot is unchanged after read-only access');
    }
}
