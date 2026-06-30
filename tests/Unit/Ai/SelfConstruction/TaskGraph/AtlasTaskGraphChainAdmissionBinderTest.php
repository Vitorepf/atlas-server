<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskGraph;

use App\Services\Ai\SelfConstruction\TaskGraph\AtlasTaskGraphChainAdmissionBinder;
use Tests\TestCase;

final class AtlasTaskGraphChainAdmissionBinderTest extends TestCase
{
    private function svc(): AtlasTaskGraphChainAdmissionBinder
    {
        return new AtlasTaskGraphChainAdmissionBinder;
    }

    /** A step that passes the brutal value gate. */
    private function step(
        string $id,
        array $deps = [],
        array $files = [],
        float $impact = 0.80,
        float $risk = 0.10,
    ): array {
        return [
            'task_id' => $id,
            'depends_on' => $deps,
            // Paths must satisfy gate impl pattern (/Services/) and test pattern (Test.php)
            'allowed_files' => $files ?: [
                "app/Services/Ai/SelfConstruction/{$id}.php",
                "tests/Unit/Ai/SelfConstruction/{$id}Test.php",
            ],
            'objective' => "Implement {$id} to deliver measurable capability improvement to the Atlas platform.",
            'compound_impact_score' => $impact,
            'give_back_risk_score' => $risk,
            'known_targets' => [],
            'is_template_farm' => false,
        ];
    }

    private function chain(string $id, array $steps): array
    {
        return ['chain_id' => $id, 'steps' => $steps];
    }

    private function bind(array $chains, array $shared = []): array
    {
        return $this->svc()->bind(['chains' => $chains, 'shared_facts' => $shared]);
    }

    // ── happy path ────────────────────────────────────────────────────────────

    public function test_valid_chain_with_ordered_deps_is_accepted(): void
    {
        $r = $this->bind([
            $this->chain('c1', [
                $this->step('t1'),
                $this->step('t2', deps: ['t1'], files: ['app/Services/T2Service.php', 'tests/T2Test.php']),
            ]),
        ]);

        $this->assertCount(1, $r['accepted_chains']);
        $this->assertSame('c1', $r['accepted_chains'][0]['chain_id']);
        $this->assertSame([], $r['rejected_chains']);
    }

    public function test_accepted_chain_includes_step_count(): void
    {
        $r = $this->bind([
            $this->chain('c1', [$this->step('t1'), $this->step('t2', files: ['app/Services/T2Service.php', 'tests/T2Test.php'])]),
        ]);

        $this->assertSame(2, $r['accepted_chains'][0]['step_count']);
    }

    // ── depends_on_edges ──────────────────────────────────────────────────────

    public function test_depends_on_edges_extracted_from_all_chains(): void
    {
        $r = $this->bind([
            $this->chain('c1', [
                $this->step('t1'),
                $this->step('t2', deps: ['t1'], files: ['app/Services/T2Service.php', 'tests/T2Test.php']),
            ]),
        ]);

        $this->assertCount(1, $r['depends_on_edges']);
        $this->assertSame('t1', $r['depends_on_edges'][0]['from']);
        $this->assertSame('t2', $r['depends_on_edges'][0]['to']);
        $this->assertSame('c1', $r['depends_on_edges'][0]['chain_id']);
    }

    // ── gate rejection ────────────────────────────────────────────────────────

    public function test_chain_rejected_when_step_fails_gate(): void
    {
        $bad = $this->step('t_bad', impact: 0.10);  // compound_impact_low
        $r = $this->bind([$this->chain('c1', [$bad])]);

        $this->assertSame([], $r['accepted_chains']);
        $this->assertCount(1, $r['rejected_chains']);
        $this->assertContains('gate_rejection', $r['rejected_chains'][0]['rejection_reasons']);
    }

    // ── depends_on order violation ────────────────────────────────────────────

    public function test_chain_rejected_when_dep_appears_after_dependent(): void
    {
        // t2 depends on t3, but t3 appears AFTER t2 in the steps array
        $r = $this->bind([
            $this->chain('c1', [
                $this->step('t1'),
                $this->step('t2', deps: ['t3'], files: ['app/T2.php', 'tests/T2Test.php']),
                $this->step('t3', files: ['app/T3.php', 'tests/T3Test.php']),
            ]),
        ]);

        $this->assertContains('depends_on_order_violation', $r['rejected_chains'][0]['rejection_reasons']);
    }

    public function test_chain_rejected_when_dep_is_missing_from_chain(): void
    {
        // t2 depends on 'ghost' which doesn't exist in steps
        $r = $this->bind([
            $this->chain('c1', [
                $this->step('t1'),
                $this->step('t2', deps: ['ghost'], files: ['app/T2.php', 'tests/T2Test.php']),
            ]),
        ]);

        $this->assertContains('depends_on_order_violation', $r['rejected_chains'][0]['rejection_reasons']);
    }

    // ── write-set conflict ────────────────────────────────────────────────────

    public function test_parallel_steps_sharing_file_trigger_write_set_conflict(): void
    {
        $shared = 'app/Services/Ai/SharedService.php';
        $r = $this->bind([
            $this->chain('c1', [
                $this->step('t1', files: [$shared, 'tests/T1Test.php']),
                $this->step('t2', files: [$shared, 'tests/T2Test.php']),
                // no dep between t1 and t2 → parallel → conflict
            ]),
        ]);

        $this->assertContains('write_set_conflict', $r['rejected_chains'][0]['rejection_reasons']);
        $this->assertCount(1, $r['write_set_conflicts']);
        $this->assertContains($shared, $r['write_set_conflicts'][0]['conflicting_files']);
    }

    public function test_steps_with_direct_dep_do_not_conflict_on_shared_file(): void
    {
        // t2 depends on t1 → sequential, not parallel → no conflict even if they share a file
        $shared = 'app/Services/Ai/SharedService.php';
        $r = $this->bind([
            $this->chain('c1', [
                $this->step('t1', files: [$shared, 'tests/T1Test.php']),
                $this->step('t2', deps: ['t1'], files: [$shared, 'tests/T2Test.php']),
            ]),
        ]);

        $this->assertSame([], $r['write_set_conflicts']);
        $this->assertCount(1, $r['accepted_chains']);
    }

    public function test_write_set_conflict_entry_includes_chain_id_and_tasks(): void
    {
        $shared = 'app/Services/Ai/SharedService.php';
        $r = $this->bind([
            $this->chain('chain_alpha', [
                $this->step('t1', files: [$shared, 'tests/T1Test.php']),
                $this->step('t2', files: [$shared, 'tests/T2Test.php']),
            ]),
        ]);

        $conflict = $r['write_set_conflicts'][0];
        $this->assertSame('chain_alpha', $conflict['chain_id']);
        $this->assertSame('t1', $conflict['task_a']);
        $this->assertSame('t2', $conflict['task_b']);
    }

    // ── multiple chains ───────────────────────────────────────────────────────

    public function test_multiple_chains_processed_independently(): void
    {
        $r = $this->bind([
            $this->chain('good', [$this->step('t1')]),
            $this->chain('bad', [$this->step('bad_step', impact: 0.10)]),
        ]);

        $this->assertCount(1, $r['accepted_chains']);
        $this->assertCount(1, $r['rejected_chains']);
        $this->assertSame('good', $r['accepted_chains'][0]['chain_id']);
    }

    // ── schema ────────────────────────────────────────────────────────────────

    public function test_schema_version_present(): void
    {
        $r = $this->svc()->bind([]);
        $this->assertSame(AtlasTaskGraphChainAdmissionBinder::SCHEMA, $r['schema_version']);
    }
}
