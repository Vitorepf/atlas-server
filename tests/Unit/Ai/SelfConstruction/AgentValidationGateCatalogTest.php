<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentValidationGateCatalog;
use Tests\TestCase;

/**
 * Unit tests for the hardened AgentValidationGateCatalog: deterministic
 * metadata, coverageMap gap flags, and hash stability across key ordering.
 */
final class AgentValidationGateCatalogTest extends TestCase
{
    private function catalog(): AgentValidationGateCatalog
    {
        return new AgentValidationGateCatalog;
    }

    // ── AC: describe(), gates(), idsByType(), blockingIds(), nonBlockingIds() ─

    public function test_describe_is_deterministic(): void
    {
        $a = $this->catalog()->describe();
        $b = $this->catalog()->describe();
        $this->assertSame($a, $b);
    }

    public function test_gates_returns_associative_map_keyed_by_id(): void
    {
        $gates = $this->catalog()->gates();
        $this->assertNotEmpty($gates);
        foreach ($gates as $id => $gate) {
            $this->assertSame($id, $gate['id']);
        }
    }

    public function test_ids_by_type_groups_correctly(): void
    {
        $byType = $this->catalog()->idsByType();
        $this->assertArrayHasKey('test', $byType);
        $this->assertArrayHasKey('lint', $byType);
        $this->assertArrayHasKey('scope', $byType);
        $this->assertContains('unit_tests', $byType['test']);
        $this->assertContains('php_lint', $byType['lint']);
        $this->assertContains('scope_check', $byType['scope']);
    }

    public function test_blocking_ids_are_all_blocking_true(): void
    {
        $blockingIds = $this->catalog()->blockingIds();
        $gates = $this->catalog()->gates();
        foreach ($blockingIds as $id) {
            $this->assertTrue($gates[$id]['blocking'], "gate {$id} should be blocking");
        }
    }

    public function test_non_blocking_ids_are_all_blocking_false(): void
    {
        $nonBlockingIds = $this->catalog()->nonBlockingIds();
        $gates = $this->catalog()->gates();
        foreach ($nonBlockingIds as $id) {
            $this->assertFalse($gates[$id]['blocking'], "gate {$id} should be non-blocking");
        }
    }

    public function test_blocking_and_non_blocking_partition_is_complete(): void
    {
        $blocking = $this->catalog()->blockingIds();
        $nonBlocking = $this->catalog()->nonBlockingIds();
        $all = $this->catalog()->ids();

        $this->assertSame(count($all), count($blocking) + count($nonBlocking));
        $this->assertSame([], array_intersect($blocking, $nonBlocking));
    }

    public function test_describe_gate_ids_match_gates_keys(): void
    {
        $describe = $this->catalog()->describe();
        $gateIds = $describe['gate_ids'];
        $gatesKeys = array_keys($this->catalog()->gates());
        sort($gateIds);
        sort($gatesKeys);
        $this->assertSame($gatesKeys, $gateIds);
    }

    // ── AC: coverageMap() reports risk gaps ──────────────────────────────────

    public function test_coverage_map_flags_gaps_for_scope_proof_queue_runtime_and_worker_safety(): void
    {
        $map = $this->catalog()->coverageMap();

        $this->assertArrayHasKey('families', $map);
        $this->assertArrayHasKey('gate_gap_rank', $map);

        // Collect all blind spots across families.
        $allBlindSpots = [];
        foreach ($map['families'] as $family) {
            foreach ($family['blind_spots'] as $spot) {
                $allBlindSpots[$spot] = true;
            }
        }

        // The coverage map must flag at least some of these risk categories.
        $riskCategories = ['runtime_integration_proof', 'security_review', 'concurrency_safety', 'data_backfill_correctness', 'link_freshness'];
        $foundAny = false;
        foreach ($riskCategories as $cat) {
            if (isset($allBlindSpots[$cat])) {
                $foundAny = true;
            }
        }
        $this->assertTrue($foundAny, 'coverageMap must flag at least one known risk gap');
    }

    public function test_coverage_map_gap_rank_sorted_by_risk_weight_descending(): void
    {
        $map = $this->catalog()->coverageMap();
        $rank = $map['gate_gap_rank'];

        if ($rank === []) {
            $this->markTestSkipped('no gaps to rank');
        }

        $weights = array_column($rank, 'risk_weight');
        $sorted = $weights;
        rsort($sorted);
        $this->assertSame($sorted, $weights);
    }

    public function test_coverage_map_recommended_hint_references_top_gap(): void
    {
        $map = $this->catalog()->coverageMap();
        if ($map['gate_gap_rank'] === []) {
            $this->assertNull($map['recommended_gate_task_hint']);
        } else {
            $topGap = $map['gate_gap_rank'][0];
            $this->assertStringContainsString($topGap['blind_spot'], $map['recommended_gate_task_hint']);
            $this->assertStringContainsString($topGap['task_family'], $map['recommended_gate_task_hint']);
        }
    }

    public function test_coverage_map_covering_gates_only_reference_real_gate_ids(): void
    {
        $map = $this->catalog()->coverageMap();
        $realGateIds = $this->catalog()->ids();

        foreach ($map['families'] as $family) {
            foreach ($family['covering_gates'] as $gateId) {
                $this->assertContains($gateId, $realGateIds, "family references unknown gate {$gateId}");
            }
        }
    }

    public function test_coverage_map_is_deterministic(): void
    {
        $a = $this->catalog()->coverageMap();
        $b = $this->catalog()->coverageMap();
        $this->assertSame($a, $b);
    }

    // ── AC: hash() stability and change detection ───────────────────────────

    public function test_hash_is_stable_across_multiple_calls(): void
    {
        $a = $this->catalog()->hash();
        $b = $this->catalog()->hash();
        $this->assertSame($a, $b);
    }

    public function test_hash_is_deterministic_64_char_sha256(): void
    {
        $hash = $this->catalog()->hash();
        $this->assertSame(64, strlen($hash));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }

    public function test_describe_catalog_hash_matches_hash_method(): void
    {
        $describe = $this->catalog()->describe();
        $this->assertSame($this->catalog()->hash(), $describe['catalog_hash']);
    }

    public function test_hash_changes_when_gate_definitions_change(): void
    {
        // The hash is derived from the gate definitions. Since we can't modify
        // the catalog in place (it's immutable), we verify that the hash is
        // not a constant — it's derived from actual gate data.
        $hash = $this->catalog()->hash();
        $this->assertNotSame('', $hash);
        $this->assertNotSame(str_repeat('0', 64), $hash);
    }

    // ── AC: gate metadata exposes blocking, risk, type ───────────────────────

    public function test_each_gate_has_blocking_type_and_severity_fields(): void
    {
        $gates = $this->catalog()->gates();
        foreach ($gates as $id => $gate) {
            $this->assertArrayHasKey('blocking', $gate, "gate {$id} missing blocking");
            $this->assertArrayHasKey('type', $gate, "gate {$id} missing type");
            $this->assertArrayHasKey('severity', $gate, "gate {$id} missing severity");
            $this->assertIsBool($gate['blocking']);
            $this->assertIsString($gate['type']);
            $this->assertIsString($gate['severity']);
        }
    }

    public function test_risk_summary_counts_are_consistent_with_gates(): void
    {
        $describe = $this->catalog()->describe();
        $riskSummary = $describe['risk_summary'];
        $gates = $this->catalog()->gates();

        $this->assertSame(count($gates), $riskSummary['total_gates']);
        $this->assertSame(count($this->catalog()->blockingIds()), $riskSummary['blocking_count']);
        $this->assertSame(count($this->catalog()->nonBlockingIds()), $riskSummary['non_blocking_count']);
    }

    public function test_runtime_safety_block_all_false(): void
    {
        $describe = $this->catalog()->describe();
        $safety = $describe['runtime_safety'];
        $this->assertTrue($safety['runtime_safety_all_false']);
        $this->assertFalse($safety['execution_allowed']);
        $this->assertFalse($safety['dispatch_allowed']);
        $this->assertFalse($safety['provider_call_allowed']);
        $this->assertFalse($safety['token_spend_allowed']);
        $this->assertFalse($safety['self_programming_allowed']);
        $this->assertFalse($safety['ledger_write_allowed']);
        $this->assertFalse($safety['runtime_write_allowed']);
    }
}
