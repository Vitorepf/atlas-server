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

    // ── task_family, model_tier, token_cost_estimate, has_required_evidence ──

    public function test_record_outcome_with_family_tier_cost_and_evidence_tracks_all_fields(): void
    {
        $ledger = new AtlasMaestroProviderPerformanceLedger();
        $ledger->recordOutcome('codex', 'refactor', 'success', 1000, 1700000000, 'refactor-family', 'opus', 500, true);
        $ledger->recordOutcome('codex', 'refactor', 'give_back', 500, 1700000010, 'refactor-family', 'opus', 200, false);

        $facts = $ledger->factsForClass('refactor');
        $this->assertSame('refactor-family', $facts['codex']['task_family']);
        $this->assertSame('opus', $facts['codex']['model_tier']);
        $this->assertSame(700, $facts['codex']['token_cost_sum']);
        $this->assertSame(1, $facts['codex']['has_required_evidence_count']);
    }

    public function test_facts_for_family_returns_aggregates(): void
    {
        $ledger = new AtlasMaestroProviderPerformanceLedger();
        $ledger->recordOutcome('codex', 'class-a', 'success', 1000, 1700000000, 'family-x', 'opus', null, null);
        $ledger->recordOutcome('codex', 'class-b', 'success', 2000, 1700000010, 'family-x', 'opus', null, null);
        $ledger->recordOutcome('claude', 'class-a', 'give_back', 500, 1700000020, 'family-x', 'sonnet', null, null);

        $facts = $ledger->factsForFamily('family-x');
        $this->assertArrayHasKey('codex', $facts);
        $this->assertArrayHasKey('claude', $facts);
        $this->assertSame(2, $facts['codex']['success_count']);
        $this->assertSame(1500, $facts['codex']['avg_duration_ms']);
        $this->assertSame(1, $facts['claude']['give_back_count']);
    }

    public function test_facts_for_family_unknown_returns_empty(): void
    {
        $ledger = new AtlasMaestroProviderPerformanceLedger();
        $this->assertSame([], $ledger->factsForFamily('nonexistent'));
    }

    public function test_backward_compatible_call_without_new_params(): void
    {
        $ledger = new AtlasMaestroProviderPerformanceLedger();
        $ledger->recordOutcome('codex', 'refactor', 'success', 1000, 1700000000);

        $facts = $ledger->factsForClass('refactor');
        $this->assertSame(1, $facts['codex']['success_count']);
        $this->assertSame(0, $facts['codex']['token_cost_sum']);
        $this->assertSame(0, $facts['codex']['has_required_evidence_count']);
    }

    public function test_deterministic_canonical_json_ordering_preserved_with_new_fields(): void
    {
        $ledger = new AtlasMaestroProviderPerformanceLedger();
        $ledger->recordOutcome('codex', 'refactor', 'success', 1000, 1700000000, 'fam', 'opus', 500, true);
        $ledger->recordOutcome('claude', 'docs', 'give_back', 400, 1700000010, 'fam', 'sonnet', 100, false);

        $blob1 = (string) file_get_contents($ledger->snapshotPath());

        // Re-run on fresh root
        $freshRoot = $this->root.'-det';
        @mkdir($freshRoot, 0o755, true);
        AtlasMaestroProviderPerformanceLedger::setRootForTesting($freshRoot);
        $ledger2 = new AtlasMaestroProviderPerformanceLedger();
        $ledger2->recordOutcome('codex', 'refactor', 'success', 1000, 1700000000, 'fam', 'opus', 500, true);
        $ledger2->recordOutcome('claude', 'docs', 'give_back', 400, 1700000010, 'fam', 'sonnet', 100, false);
        $blob2 = (string) file_get_contents($ledger2->snapshotPath());
        @unlink($ledger2->snapshotPath());
        @rmdir($freshRoot);
        AtlasMaestroProviderPerformanceLedger::setRootForTesting($this->root);

        $this->assertSame($blob1, $blob2, 'new fields must not break deterministic ordering');
    }

    // ── AC2: worker class + proof result ────────────────────────────────────

    public function test_record_outcome_tracks_worker_class_and_proof_result_counts(): void
    {
        $ledger = new AtlasMaestroProviderPerformanceLedger();
        $ledger->recordOutcome(
            'codex', 'refactor', 'success', 1000, 1700000000,
            null, null, null, null,
            'claude-muscle-1', AtlasMaestroProviderPerformanceLedger::PROOF_PASSED,
        );
        $ledger->recordOutcome(
            'codex', 'refactor', 'give_back', 500, 1700000010,
            null, null, null, null,
            'claude-muscle-1', AtlasMaestroProviderPerformanceLedger::PROOF_FAILED,
        );

        $facts = $ledger->factsForClass('refactor');
        $this->assertSame('claude-muscle-1', $facts['codex']['worker_class']);
        $this->assertSame(1, $facts['codex']['proof_passed_count']);
        $this->assertSame(1, $facts['codex']['proof_failed_count']);
    }

    public function test_worker_class_and_proof_result_default_absent_when_not_supplied(): void
    {
        $ledger = new AtlasMaestroProviderPerformanceLedger();
        $ledger->recordOutcome('codex', 'refactor', 'success', 1000, 1700000000);

        $facts = $ledger->factsForClass('refactor');
        $this->assertArrayNotHasKey('worker_class', $facts['codex']);
        $this->assertSame(0, $facts['codex']['proof_passed_count']);
        $this->assertSame(0, $facts['codex']['proof_failed_count']);
    }

    // ── AC3/AC4: sample_size + confidence on every read surface ────────────────

    public function test_facts_for_class_includes_sample_size_and_confidence(): void
    {
        $ledger = new AtlasMaestroProviderPerformanceLedger();
        for ($i = 0; $i < 25; $i++) {
            $ledger->recordOutcome('codex', 'refactor', 'success', 1000, 1700000000 + $i);
        }

        $facts = $ledger->factsForClass('refactor');
        $this->assertSame(25, $facts['codex']['sample_size']);
        $this->assertSame('high', $facts['codex']['confidence']);
    }

    public function test_sparse_sample_is_low_confidence(): void
    {
        $ledger = new AtlasMaestroProviderPerformanceLedger();
        $ledger->recordOutcome('codex', 'refactor', 'success', 1000, 1700000000);
        $ledger->recordOutcome('codex', 'refactor', 'success', 1000, 1700000010);

        $facts = $ledger->factsForClass('refactor');
        $this->assertSame(2, $facts['codex']['sample_size']);
        $this->assertSame('low', $facts['codex']['confidence']);
    }

    public function test_contradictory_evidence_is_low_confidence_despite_decent_sample_size(): void
    {
        $ledger = new AtlasMaestroProviderPerformanceLedger();
        for ($i = 0; $i < 5; $i++) {
            $ledger->recordOutcome('codex', 'refactor', 'success', 1000, 1700000000 + $i);
        }
        for ($i = 5; $i < 10; $i++) {
            $ledger->recordOutcome('codex', 'refactor', 'give_back', 1000, 1700000000 + $i);
        }

        $facts = $ledger->factsForClass('refactor');
        $this->assertSame(10, $facts['codex']['sample_size']);
        $this->assertSame('low', $facts['codex']['confidence'], 'a 50/50 split must never drive hard routing');
    }

    public function test_non_sparse_non_contradictory_moderate_sample_is_medium_confidence(): void
    {
        $ledger = new AtlasMaestroProviderPerformanceLedger();
        for ($i = 0; $i < 9; $i++) {
            $ledger->recordOutcome('codex', 'refactor', 'success', 1000, 1700000000 + $i);
        }
        $ledger->recordOutcome('codex', 'refactor', 'give_back', 1000, 1700000009);

        $facts = $ledger->factsForClass('refactor');
        $this->assertSame(10, $facts['codex']['sample_size']);
        $this->assertSame('medium', $facts['codex']['confidence']);
    }

    public function test_facts_for_provider_includes_sample_size_and_confidence(): void
    {
        $ledger = new AtlasMaestroProviderPerformanceLedger();
        $ledger->recordOutcome('codex', 'refactor', 'success', 1000, 1700000000);

        $facts = $ledger->factsForProvider('codex');
        $this->assertArrayHasKey('sample_size', $facts['refactor']);
        $this->assertArrayHasKey('confidence', $facts['refactor']);
    }

    public function test_facts_for_family_includes_sample_size_and_confidence(): void
    {
        $ledger = new AtlasMaestroProviderPerformanceLedger();
        $ledger->recordOutcome('codex', 'refactor', 'success', 1000, 1700000000, 'family-x');

        $facts = $ledger->factsForFamily('family-x');
        $this->assertArrayHasKey('sample_size', $facts['codex']);
        $this->assertArrayHasKey('confidence', $facts['codex']);
    }

    // ── evidenceWeightedFacts: evidence_weighted_success_rate, give_back_rate, poison_rate, routing_confidence ──

    public function test_evidence_weighted_facts_has_required_keys(): void
    {
        $ledger = new AtlasMaestroProviderPerformanceLedger();
        $ledger->recordOutcome('codex', 'refactor', 'success', 1000, 1700000000, null, null, null, true);
        $facts = $ledger->evidenceWeightedFacts('refactor');
        $this->assertArrayHasKey('evidence_weighted_success_rate', $facts['codex']);
        $this->assertArrayHasKey('give_back_rate', $facts['codex']);
        $this->assertArrayHasKey('poison_rate', $facts['codex']);
        $this->assertArrayHasKey('routing_confidence', $facts['codex']);
    }

    public function test_evidence_weighted_success_rate_discounts_unproven_success(): void
    {
        $ledger = new AtlasMaestroProviderPerformanceLedger();
        // 5 successes with evidence, 5 without
        for ($i = 0; $i < 5; $i++) {
            $ledger->recordOutcome('codex', 'refactor', 'success', 1000, 1700000000, null, null, null, true);
        }
        for ($i = 0; $i < 5; $i++) {
            $ledger->recordOutcome('codex', 'refactor', 'success', 1000, 1700000000, null, null, null, false);
        }
        $facts = $ledger->evidenceWeightedFacts('refactor');
        // 10 total, 5 with evidence → weighted rate = 0.5
        $this->assertSame(0.5, $facts['codex']['evidence_weighted_success_rate']);
    }

    public function test_give_back_rate_computed_correctly(): void
    {
        $ledger = new AtlasMaestroProviderPerformanceLedger();
        $ledger->recordOutcome('codex', 'refactor', 'success', 1000, 1700000000);
        $ledger->recordOutcome('codex', 'refactor', 'give_back', 1000, 1700000000);
        $facts = $ledger->evidenceWeightedFacts('refactor');
        $this->assertSame(0.5, $facts['codex']['give_back_rate']);
    }

    public function test_poison_rate_includes_proof_failures(): void
    {
        $ledger = new AtlasMaestroProviderPerformanceLedger();
        $ledger->recordOutcome('codex', 'refactor', 'success', 1000, 1700000000, null, null, null, null, null, 'failed');
        $facts = $ledger->evidenceWeightedFacts('refactor');
        // 1 success + 0 give_back = 1 total; poison = 0 + 1 proof_failed = 1 → rate = 1.0
        $this->assertSame(1.0, $facts['codex']['poison_rate']);
    }

    public function test_routing_confidence_high_with_strong_evidence(): void
    {
        $ledger = new AtlasMaestroProviderPerformanceLedger();
        for ($i = 0; $i < 10; $i++) {
            $ledger->recordOutcome('codex', 'refactor', 'success', 1000, 1700000000, null, null, null, true, null, 'passed');
        }
        $facts = $ledger->evidenceWeightedFacts('refactor');
        $this->assertSame('high', $facts['codex']['routing_confidence']);
    }

    public function test_routing_confidence_unknown_for_sparse_sample(): void
    {
        $ledger = new AtlasMaestroProviderPerformanceLedger();
        $ledger->recordOutcome('codex', 'refactor', 'success', 1000, 1700000000);
        $facts = $ledger->evidenceWeightedFacts('refactor');
        $this->assertSame('unknown', $facts['codex']['routing_confidence']);
    }
}
