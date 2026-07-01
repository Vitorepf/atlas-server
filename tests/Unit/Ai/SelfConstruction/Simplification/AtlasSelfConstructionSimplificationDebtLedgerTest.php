<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Simplification;

use App\Services\Ai\SelfConstruction\Simplification\AtlasSelfConstructionSimplificationDebtLedger;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionSimplificationDebtLedgerTest extends TestCase
{
    public function test_each_entry_carries_required_fields(): void
    {
        $result = (new AtlasSelfConstructionSimplificationDebtLedger)->record([
            'entries' => [
                [
                    'category' => 'duplicate_circuit',
                    'affected_targets' => ['app/Services/Ai/SelfConstruction/A.php', 'app/Services/Ai/SelfConstruction/B.php'],
                    'evidence_refs' => ['docs/x.md'],
                ],
            ],
        ]);

        $entry = $result['entries'][0];
        $this->assertSame('duplicate_circuit', $entry['category']);
        $this->assertSame(['app/Services/Ai/SelfConstruction/A.php', 'app/Services/Ai/SelfConstruction/B.php'], $entry['affected_targets']);
        $this->assertSame(['docs/x.md'], $entry['evidence_refs']);
        $this->assertArrayHasKey('impact', $entry);
        $this->assertArrayHasKey('risk', $entry);
        $this->assertArrayHasKey('next_action', $entry);
        $this->assertArrayHasKey('accountable_gate', $entry);
    }

    public function test_duplicate_circuit_stale_scaffold_cosmetic_and_high_risk_entries(): void
    {
        $result = (new AtlasSelfConstructionSimplificationDebtLedger)->record([
            'entries' => [
                ['category' => 'cosmetic', 'affected_targets' => ['x.php'], 'evidence_refs' => []],
                ['category' => 'stale_scaffold', 'affected_targets' => ['y.php'], 'evidence_refs' => []],
                ['category' => 'duplicate_circuit', 'affected_targets' => ['z.php'], 'evidence_refs' => []],
                ['category' => 'broken_contract', 'affected_targets' => ['w.php'], 'evidence_refs' => []],
            ],
        ]);

        $categories = array_column($result['entries'], 'category');
        $this->assertSame(['broken_contract', 'duplicate_circuit', 'stale_scaffold', 'cosmetic'], $categories);

        $brokenContract = $result['entries'][0];
        $this->assertSame('high', $brokenContract['risk']);
        $this->assertSame('repair_contract_before_next_wave', $brokenContract['next_action']);
    }

    public function test_cosmetic_only_ranked_below_debt_reducing_duplicated_decision_paths(): void
    {
        $result = (new AtlasSelfConstructionSimplificationDebtLedger)->record([
            'entries' => [
                ['category' => 'cosmetic', 'affected_targets' => [], 'evidence_refs' => []],
                ['category' => 'duplicate_circuit', 'affected_targets' => [], 'evidence_refs' => []],
            ],
        ]);

        $this->assertSame('duplicate_circuit', $result['entries'][0]['category']);
        $this->assertSame('cosmetic', $result['entries'][1]['category']);
        $this->assertSame('cosmetic_only', $result['entries'][1]['impact']);
        $this->assertSame('none', $result['entries'][1]['risk']);
    }

    public function test_unknown_category_is_ignored(): void
    {
        $result = (new AtlasSelfConstructionSimplificationDebtLedger)->record([
            'entries' => [
                ['category' => 'not_a_real_category', 'affected_targets' => [], 'evidence_refs' => []],
            ],
        ]);

        $this->assertSame([], $result['entries']);
    }

    public function test_no_entries_returns_empty_ledger(): void
    {
        $result = (new AtlasSelfConstructionSimplificationDebtLedger)->record([]);

        $this->assertSame([], $result['entries']);
        $this->assertSame('atlas.self_construction.simplification.debt_ledger.v1', $result['schema']);
    }

    // ── AC: compound priority, circuit, proof gap, age bucket, next task hint ──

    public function test_entry_carries_compound_priority_proof_gap_age_bucket_and_next_task_hint(): void
    {
        $result = (new AtlasSelfConstructionSimplificationDebtLedger)->record([
            'entries' => [
                [
                    'category' => 'duplicate_circuit',
                    'affected_targets' => ['app/Services/Foo.php'],
                    'evidence_refs' => ['docs/x.md'],
                    'first_observed_at_days_ago' => 3,
                ],
            ],
        ]);

        $entry = $result['entries'][0];
        $this->assertArrayHasKey('compound_priority', $entry);
        $this->assertArrayHasKey('proof_gap', $entry);
        $this->assertArrayHasKey('age_bucket', $entry);
        $this->assertArrayHasKey('next_task_hint', $entry);
        $this->assertFalse($entry['proof_gap'], 'evidence_refs present ⇒ no proof gap');
        $this->assertSame(AtlasSelfConstructionSimplificationDebtLedger::AGE_BUCKET_FRESH, $entry['age_bucket']);
        $this->assertSame('consolidate_into_single_organ:app/Services/Foo.php', $entry['next_task_hint']);
    }

    public function test_missing_evidence_refs_is_a_proof_gap(): void
    {
        $result = (new AtlasSelfConstructionSimplificationDebtLedger)->record([
            'entries' => [
                ['category' => 'broken_contract', 'affected_targets' => ['a.php'], 'evidence_refs' => []],
            ],
        ]);

        $this->assertTrue($result['entries'][0]['proof_gap']);
    }

    // ── AC: priority ordering (category always dominates staleness/proof-gap) ──

    public function test_category_priority_never_crosses_even_with_extreme_staleness(): void
    {
        $result = (new AtlasSelfConstructionSimplificationDebtLedger)->record([
            'entries' => [
                ['category' => 'cosmetic', 'affected_targets' => ['a.php'], 'evidence_refs' => ['x'], 'first_observed_at_days_ago' => 9999],
                ['category' => 'duplicate_circuit', 'affected_targets' => ['b.php'], 'evidence_refs' => [], 'first_observed_at_days_ago' => 0],
            ],
        ]);

        $this->assertSame('duplicate_circuit', $result['entries'][0]['category']);
        $this->assertSame('cosmetic', $result['entries'][1]['category']);
    }

    // ── AC: stale debt escalation (within the same category) ───────────────────

    public function test_stale_debt_outranks_fresh_debt_within_the_same_category(): void
    {
        $result = (new AtlasSelfConstructionSimplificationDebtLedger)->record([
            'entries' => [
                ['category' => 'stale_scaffold', 'affected_targets' => ['fresh.php'], 'evidence_refs' => ['x'], 'first_observed_at_days_ago' => 1],
                ['category' => 'stale_scaffold', 'affected_targets' => ['old.php'], 'evidence_refs' => ['x'], 'first_observed_at_days_ago' => 60],
            ],
        ]);

        $this->assertSame(['old.php'], $result['entries'][0]['affected_targets']);
        $this->assertSame(AtlasSelfConstructionSimplificationDebtLedger::AGE_BUCKET_STALE, $result['entries'][0]['age_bucket']);
        $this->assertGreaterThan($result['entries'][1]['compound_priority'], $result['entries'][0]['compound_priority']);
    }

    public function test_missing_age_signal_is_unknown_not_optimistically_fresh(): void
    {
        $result = (new AtlasSelfConstructionSimplificationDebtLedger)->record([
            'entries' => [
                ['category' => 'stale_scaffold', 'affected_targets' => ['x.php'], 'evidence_refs' => []],
            ],
        ]);

        $this->assertSame(AtlasSelfConstructionSimplificationDebtLedger::AGE_BUCKET_UNKNOWN, $result['entries'][0]['age_bucket']);
        $this->assertNull($result['entries'][0]['stale_age_days']);
    }

    // ── AC: duplicate debt collapse ─────────────────────────────────────────────

    public function test_duplicate_debt_with_same_category_and_targets_collapses_into_one_entry(): void
    {
        $result = (new AtlasSelfConstructionSimplificationDebtLedger)->record([
            'entries' => [
                ['category' => 'duplicate_circuit', 'affected_targets' => ['a.php', 'b.php'], 'evidence_refs' => ['docs/one.md']],
                ['category' => 'duplicate_circuit', 'affected_targets' => ['b.php', 'a.php'], 'evidence_refs' => ['docs/two.md']],
            ],
        ]);

        $this->assertCount(1, $result['entries']);
        $entry = $result['entries'][0];
        $this->assertSame(2, $entry['duplicate_count']);
        $this->assertSame(['docs/one.md', 'docs/two.md'], $entry['evidence_refs']);
    }

    public function test_different_affected_targets_do_not_collapse(): void
    {
        $result = (new AtlasSelfConstructionSimplificationDebtLedger)->record([
            'entries' => [
                ['category' => 'duplicate_circuit', 'affected_targets' => ['a.php'], 'evidence_refs' => []],
                ['category' => 'duplicate_circuit', 'affected_targets' => ['c.php'], 'evidence_refs' => []],
            ],
        ]);

        $this->assertCount(2, $result['entries']);
    }

    // ── AC: provider-safe export ─────────────────────────────────────────────────

    public function test_entries_are_json_encodable_and_contain_only_plain_scalar_or_list_values(): void
    {
        $result = (new AtlasSelfConstructionSimplificationDebtLedger)->record([
            'entries' => [
                ['category' => 'broken_contract', 'affected_targets' => ['a.php'], 'evidence_refs' => ['x'], 'first_observed_at_days_ago' => 10],
            ],
        ]);

        $json = json_encode($result, JSON_THROW_ON_ERROR);
        $this->assertIsString($json);

        foreach ($result['entries'][0] as $key => $value) {
            $this->assertTrue(
                is_scalar($value) || is_array($value) || $value === null,
                "field {$key} must be provider-safe plain data, got ".gettype($value),
            );
        }
    }
}
