<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskFabric;

use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricLowValueRetirementQueue;
use PHPUnit\Framework\TestCase;

final class AtlasTaskFabricLowValueRetirementQueueTest extends TestCase
{
    private function queue(): AtlasTaskFabricLowValueRetirementQueue
    {
        return new AtlasTaskFabricLowValueRetirementQueue;
    }

    private function spec(string $id, float $value = 0.8, bool $dup = false, bool $stale = false): array
    {
        return ['id' => $id, 'value_estimate' => $value, 'is_duplicate' => $dup, 'is_stale' => $stale];
    }

    // ── AC1: output shape ─────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->queue()->evaluate([]);
        $this->assertSame(AtlasTaskFabricLowValueRetirementQueue::SCHEMA, $r['schema_version']);
        $this->assertArrayHasKey('retired', $r);
        $this->assertArrayHasKey('protected', $r);
        $this->assertArrayHasKey('ineligible', $r);
        $this->assertArrayHasKey('total_retired', $r);
        $this->assertArrayHasKey('total_protected', $r);
    }

    public function test_empty_candidates_gives_empty_results(): void
    {
        $r = $this->queue()->evaluate([]);
        $this->assertEmpty($r['retired']);
        $this->assertEmpty($r['protected']);
        $this->assertSame(0, $r['total_retired']);
    }

    // ── Retirement eligibility ────────────────────────────────────────────────

    public function test_low_value_task_is_retired(): void
    {
        $r = $this->queue()->evaluate(['candidates' => [$this->spec('t1', 0.1)]]);

        $this->assertCount(1, $r['retired']);
        $this->assertSame('t1', $r['retired'][0]['id']);
        $this->assertSame('low_value', $r['retired'][0]['retirement_reason']);
    }

    public function test_duplicate_task_is_retired_regardless_of_value(): void
    {
        $r = $this->queue()->evaluate(['candidates' => [$this->spec('t1', 0.9, true)]]);

        $this->assertCount(1, $r['retired']);
        $this->assertSame('duplicate', $r['retired'][0]['retirement_reason']);
    }

    public function test_stale_task_is_retired(): void
    {
        $r = $this->queue()->evaluate(['candidates' => [$this->spec('t1', 0.9, false, true)]]);

        $this->assertCount(1, $r['retired']);
        $this->assertSame('stale', $r['retired'][0]['retirement_reason']);
    }

    public function test_high_value_non_duplicate_non_stale_is_ineligible(): void
    {
        $r = $this->queue()->evaluate(['candidates' => [$this->spec('t1', 0.9)]]);

        $this->assertEmpty($r['retired']);
        $this->assertCount(1, $r['ineligible']);
    }

    public function test_custom_value_threshold_overrides_default(): void
    {
        // Default threshold = 0.2; set to 0.5 → value 0.3 is now below threshold.
        $r = $this->queue()->evaluate([
            'candidates'      => [$this->spec('t1', 0.3)],
            'value_threshold' => 0.5,
        ]);

        $this->assertCount(1, $r['retired']);
        $this->assertSame(0.5, $r['value_threshold']);
    }

    // ── AC2: critical dependency chain protection ─────────────────────────────

    public function test_critical_chain_task_is_protected_not_retired(): void
    {
        $r = $this->queue()->evaluate([
            'candidates'               => [$this->spec('t1', 0.0, true, true)],
            'critical_dependency_chains' => ['t1'],
        ]);

        $this->assertEmpty($r['retired']);
        $this->assertCount(1, $r['protected']);
        $this->assertSame('on_critical_dependency_chain', $r['protected'][0]['protection_reason']);
        $this->assertSame(1, $r['total_protected']);
    }

    public function test_critical_chain_check_fires_before_eligibility(): void
    {
        // Task is a duplicate (would be retired) BUT is also on a critical chain.
        $r = $this->queue()->evaluate([
            'candidates'               => [$this->spec('t1', 0.0, true)],
            'critical_dependency_chains' => ['t1'],
        ]);

        $this->assertEmpty($r['retired']);
        $this->assertCount(1, $r['protected']);
    }

    public function test_mixed_batch_splits_correctly(): void
    {
        $r = $this->queue()->evaluate([
            'candidates' => [
                $this->spec('retire-me', 0.05),       // low value → retired
                $this->spec('protect-me', 0.0, true), // critical chain → protected
                $this->spec('keep-me', 0.9),           // high value → ineligible
            ],
            'critical_dependency_chains' => ['protect-me'],
        ]);

        $this->assertCount(1, $r['retired']);
        $this->assertCount(1, $r['protected']);
        $this->assertCount(1, $r['ineligible']);
        $this->assertSame('retire-me', $r['retired'][0]['id']);
        $this->assertSame('protect-me', $r['protected'][0]['id']);
        $this->assertSame('keep-me', $r['ineligible'][0]['id']);
    }

    public function test_output_is_deterministic(): void
    {
        $facts = [
            'candidates' => [
                $this->spec('a', 0.1),
                $this->spec('b', 0.9, true),
                $this->spec('c', 0.5),
            ],
            'critical_dependency_chains' => ['b'],
        ];
        $x = $this->queue()->evaluate($facts);
        $y = $this->queue()->evaluate($facts);
        $this->assertSame(json_encode($x), json_encode($y));
    }

    // ── AC2: inflation signals retire high-declared-value specs ───────────────

    public function test_high_proxy_risk_retires_inflated_high_value_spec(): void
    {
        $r = $this->queue()->evaluate(['candidates' => [[
            'id'             => 't1',
            'value_estimate' => 0.95,   // optimistically high
            'proxy_risk'     => 0.80,   // above 0.70 threshold
        ]]]);

        $this->assertCount(1, $r['retired']);
        $this->assertSame('high_proxy_risk', $r['retired'][0]['retirement_reason']);
    }

    public function test_high_template_similarity_retires_inflated_spec(): void
    {
        $r = $this->queue()->evaluate(['candidates' => [[
            'id'                  => 't1',
            'value_estimate'      => 0.90,
            'template_similarity' => 0.75,
        ]]]);

        $this->assertCount(1, $r['retired']);
        $this->assertSame('high_template_similarity', $r['retired'][0]['retirement_reason']);
    }

    public function test_repeated_give_back_count_retires_spec(): void
    {
        $r = $this->queue()->evaluate(['candidates' => [[
            'id'                        => 't1',
            'value_estimate'            => 0.85,
            'repeated_give_back_count'  => 3,
        ]]]);

        $this->assertCount(1, $r['retired']);
        $this->assertSame('repeated_give_back', $r['retired'][0]['retirement_reason']);
    }

    public function test_give_back_count_below_threshold_does_not_retire(): void
    {
        $r = $this->queue()->evaluate(['candidates' => [[
            'id'                       => 't1',
            'value_estimate'           => 0.85,
            'repeated_give_back_count' => 2,  // threshold is 3
        ]]]);

        $this->assertEmpty($r['retired']);
        $this->assertCount(1, $r['ineligible']);
    }

    public function test_quarantine_count_retires_spec(): void
    {
        $r = $this->queue()->evaluate(['candidates' => [[
            'id'              => 't1',
            'value_estimate'  => 0.88,
            'quarantine_count' => 2,
        ]]]);

        $this->assertCount(1, $r['retired']);
        $this->assertSame('over_quarantined', $r['retired'][0]['retirement_reason']);
    }

    public function test_stale_without_commit_retires_spec(): void
    {
        $r = $this->queue()->evaluate(['candidates' => [[
            'id'                  => 't1',
            'value_estimate'      => 0.90,
            'stale_without_commit' => true,
        ]]]);

        $this->assertCount(1, $r['retired']);
        $this->assertSame('stale_without_commit', $r['retired'][0]['retirement_reason']);
    }

    public function test_inflation_signals_overrule_high_value_estimate(): void
    {
        // High declared value (0.95) but all inflation signals present
        $r = $this->queue()->evaluate(['candidates' => [[
            'id'                       => 't1',
            'value_estimate'           => 0.95,
            'proxy_risk'               => 0.80,
            'template_similarity'      => 0.75,
            'repeated_give_back_count' => 4,
            'quarantine_count'         => 3,
        ]]]);

        $this->assertCount(1, $r['retired'], 'Inflated high-value spec must be retired');
        $this->assertEmpty($r['ineligible']);
    }

    // ── AC3: replacement_or_respec contract ──────────────────────────────────

    public function test_retired_item_has_replacement_or_respec(): void
    {
        $r = $this->queue()->evaluate(['candidates' => [$this->spec('t1', 0.1)]]);

        $this->assertArrayHasKey('replacement_or_respec', $r['retired'][0]);
        $this->assertArrayHasKey('action',                $r['retired'][0]['replacement_or_respec']);
    }

    public function test_retired_with_replacement_candidate_action_is_replace(): void
    {
        $r = $this->queue()->evaluate(['candidates' => [[
            'id'                   => 't1',
            'value_estimate'       => 0.05,
            'replacement_candidate' => 'task-better-v2',
        ]]]);

        $this->assertSame('replace', $r['retired'][0]['replacement_or_respec']['action']);
        $this->assertSame('task-better-v2', $r['retired'][0]['replacement_or_respec']['replacement_candidate_id']);
    }

    public function test_retired_without_replacement_candidate_action_is_respec_required(): void
    {
        $r = $this->queue()->evaluate(['candidates' => [$this->spec('t1', 0.05)]]);

        $this->assertSame('respec_required', $r['retired'][0]['replacement_or_respec']['action']);
        $this->assertNull($r['retired'][0]['replacement_or_respec']['replacement_candidate_id']);
    }

    public function test_critical_chain_protected_even_with_all_inflation_signals(): void
    {
        $r = $this->queue()->evaluate([
            'candidates' => [[
                'id'                       => 't1',
                'value_estimate'           => 0.0,
                'proxy_risk'               => 0.99,
                'template_similarity'      => 0.99,
                'repeated_give_back_count' => 10,
                'quarantine_count'         => 10,
                'stale_without_commit'     => true,
            ]],
            'critical_dependency_chains' => ['t1'],
        ]);

        $this->assertEmpty($r['retired']);
        $this->assertCount(1, $r['protected']);
        $this->assertSame('on_critical_dependency_chain', $r['protected'][0]['protection_reason']);
    }
}
