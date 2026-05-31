<?php

declare(strict_types=1);

namespace Tests\Unit\PlanExecution;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\PlanCompletionTrackerService;
use PHPUnit\Framework\TestCase;

final class PlanCompletionTrackerServiceTest extends TestCase
{
    private string $storageRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storageRoot = sys_get_temp_dir().'/atlas_plan_completion_test_'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->storageRoot);
        parent::tearDown();
    }

    private function service(): PlanCompletionTrackerService
    {
        $s = new PlanCompletionTrackerService;
        $s->setStorageRootForTesting($this->storageRoot);

        return $s;
    }

    /**
     * @param  list<array{id:string,depends_on?:list<string>}>  $sliceSpecs
     * @return array<string,mixed>
     */
    private function plan(string $planId, array $sliceSpecs): array
    {
        $slices = [];
        $seq = 1;
        foreach ($sliceSpecs as $spec) {
            $id = $spec['id'];
            $slices[] = [
                'slice_id' => $id,
                'sequence' => $seq++,
                'label' => $id,
                'objective' => 'obj '.$id,
                'delivery' => 'del '.$id,
                'acceptance_criteria' => ['aceite text '.$id],
                'authority_guard' => 'dev',
                'depends_on' => $spec['depends_on'] ?? [],
                'allowed_files' => [],
                'owner' => 'atlas_dev',
                'finding' => [
                    'title' => 'finding '.$id,
                    'detail' => 'd',
                    'affected_files' => [],
                    'owner_candidate' => 'atlas_dev',
                    'finding_id' => $id, // contract: finding_id SET EQUAL to slice_id
                    'finding_hash' => 'sha256:x',
                    'severity' => 'medium',
                    'kind' => 'gap_candidate',
                    'origin_type' => 'deep_scan',
                    'spec_seed' => ['tests_required' => []],
                ],
                'executable_slices' => [],
                'planner_status' => 'sliced',
            ];
        }

        return [
            'schema_version' => 'atlas.plan_execution.decomposed_plan.v1',
            'plan_id' => $planId,
            'plan_title' => 'Plan '.$planId,
            'doc_path' => 'docs/x.md',
            'source_doc_hash' => 'sha256:doc',
            'decomposition_status' => 'complete',
            'slices' => $slices,
            'dependency_graph' => [],
            'blockers' => [],
            'plan_hash' => 'sha256:plan_'.$planId,
        ];
    }

    /**
     * Build a RAW loop cycle. Defaults to a fully real, merged, validated cycle.
     *
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function cycle(string $findingId, array $overrides = []): array
    {
        $base = [
            'cycle_id' => 'cyc_'.$findingId.'_'.bin2hex(random_bytes(3)),
            'final_status' => 'cycle_completed',
            'merge_performed' => true,
            'blockers' => [],
            'selected_finding' => ['finding_id' => $findingId, 'title' => 'finding '.$findingId],
            'changed_files' => ['app/Foo.php'],
            'validation' => ['passed' => true, 'commands' => ['php artisan test'], 'results' => [['ok' => true]]],
            'merge_governance' => ['status' => 'merged', 'merge_commit' => 'abc123'],
            'result_bridge_id' => 'rb_'.$findingId,
            'inbox_item_id' => 'inbox_'.$findingId,
            'owner' => 'atlas_dev',
            'owner_flow' => ['provider_router_used' => false],
            'owner_result' => ['runtime_invocation' => ['command_result' => ['owner_cli_provider_calls' => 2]]],
        ];

        // Top-level overrides replace wholesale (array_replace_recursive would keep base
        // list elements, e.g. changed_files => [] would not actually clear it).
        return array_replace($base, $overrides);
    }

    public function test_happy_path_single_slice_delivered_ready(): void
    {
        $plan = $this->plan('P1', [['id' => 'S1']]);
        $ledger = $this->service()->recordCycle([
            'decomposed_plan' => $plan,
            'area_id' => 'agentic_engineering_os',
            'cycle' => $this->cycle('S1'),
        ]);

        $this->assertSame(PlanCompletionTrackerService::LEDGER_SCHEMA, $ledger['schema_version']);
        $this->assertSame('ready', $ledger['status']);
        $this->assertSame(1, $ledger['total_slices']);
        $this->assertSame(1, $ledger['delivered_count']);
        $this->assertSame(100.0, $ledger['completion_pct']);
        $row = $ledger['slice_states']['S1'];
        $this->assertSame('delivered', $row['state']);
        $this->assertTrue($row['provider_proof']);
        $this->assertSame('provider_call_count', $row['provider_proof_basis']);
        $this->assertTrue($row['acceptance_met']);
        $this->assertSame('operator_acceptance_pending', $row['acceptance_basis']);
        $this->assertSame('abc123', $row['merge_hash']);
        $this->assertSame([], $ledger['blockers']);
    }

    public function test_session_shaped_owner_flow_merge_is_counted_delivered(): void
    {
        // Regression: the plan-execution path returns the SESSION cycle, which does NOT carry
        // a top-level `validation` block nor the owner_result.runtime_invocation provider-call
        // path. Its real proof lives under merge_governance.validation (the governor's green
        // gate) and owner_flow.execution_result.owner_cli_provider_calls. A genuinely merged,
        // validated, provider-backed slice in that shape must be counted as delivered — not
        // left in_progress with a false provider_call_count_unavailable blocker.
        $plan = $this->plan('PS', [['id' => 'S1']]);
        $sessionCycle = [
            'cycle_id' => 'cyc_session_S1',
            'final_status' => 'cycle_completed',
            'merge_performed' => true,
            'blockers' => [],
            'selected_finding' => ['finding_id' => 'S1', 'title' => 'finding S1'],
            'changed_files' => ['app/Services/Ai/Aaeos/Svc.php'],
            'merge_governance' => [
                'status' => 'merged',
                'merge_commit' => 'def456',
                'validation' => ['passed' => true, 'commands' => ['./vendor/bin/phpunit x'], 'results' => [['ok' => true]]],
            ],
            'merge_hash' => 'def456',
            'result_bridge_id' => 'rb_S1',
            'owner' => 'atlas_dev',
            'owner_flow' => [
                'provider_router_used' => false,
                'execution_result' => ['owner_cli_provider_calls' => 2],
            ],
            // NOTE: deliberately NO top-level 'validation' and NO 'owner_result' path.
        ];

        $ledger = $this->service()->recordCycle([
            'decomposed_plan' => $plan,
            'area_id' => 'agentic_engineering_os',
            'cycle' => $sessionCycle,
        ]);

        $row = $ledger['slice_states']['S1'];
        $this->assertSame('delivered', $row['state']);
        $this->assertTrue($row['provider_proof']);
        $this->assertSame('provider_call_count', $row['provider_proof_basis']);
        $this->assertSame(1, $ledger['delivered_count']);
        $this->assertNotContains('provider_call_count_unavailable', $ledger['blockers']);
    }

    public function test_completion_pct_is_honest_three_of_six(): void
    {
        $plan = $this->plan('P2', [
            ['id' => 'S1'], ['id' => 'S2'], ['id' => 'S3'],
            ['id' => 'S4'], ['id' => 'S5'], ['id' => 'S6'],
        ]);
        $svc = $this->service();
        foreach (['S1', 'S2', 'S3'] as $sid) {
            $ledger = $svc->recordCycle([
                'decomposed_plan' => $plan,
                'area_id' => 'a',
                'cycle' => $this->cycle($sid),
            ]);
        }
        $this->assertSame(3, $ledger['delivered_count']);
        $this->assertSame(6, $ledger['total_slices']);
        $this->assertSame(50.0, $ledger['completion_pct']);
        $this->assertSame('partial', $ledger['status']);
    }

    public function test_provider_router_used_yields_no_provider_proof_never_delivered(): void
    {
        $plan = $this->plan('P3', [['id' => 'S1']]);
        $ledger = $this->service()->recordCycle([
            'decomposed_plan' => $plan,
            'area_id' => 'a',
            'cycle' => $this->cycle('S1', ['owner_flow' => ['provider_router_used' => true]]),
        ]);
        $row = $ledger['slice_states']['S1'];
        $this->assertFalse($row['provider_proof']);
        $this->assertSame('none', $row['provider_proof_basis']);
        $this->assertContains($row['state'], ['in_progress', 'blocked']);
        $this->assertNotSame('delivered', $row['state']);
    }

    public function test_empty_changed_files_yields_no_provider_proof(): void
    {
        $plan = $this->plan('P3b', [['id' => 'S1']]);
        $ledger = $this->service()->recordCycle([
            'decomposed_plan' => $plan,
            'area_id' => 'a',
            'cycle' => $this->cycle('S1', ['changed_files' => []]),
        ]);
        $row = $ledger['slice_states']['S1'];
        $this->assertFalse($row['provider_proof']);
        $this->assertContains($row['state'], ['in_progress', 'blocked']);
    }

    public function test_provider_call_count_unavailable_blocker_and_basis(): void
    {
        $plan = $this->plan('P4', [['id' => 'S1']]);
        // Real diff + router_used=false but NO owner_cli_provider_calls readable.
        $cycle = $this->cycle('S1');
        unset($cycle['owner_result']);
        $cycle['runtime_invocation'] = ['command_result' => []]; // no provider call count

        $ledger = $this->service()->recordCycle([
            'decomposed_plan' => $plan,
            'area_id' => 'a',
            'cycle' => $cycle,
        ]);
        $row = $ledger['slice_states']['S1'];
        $this->assertTrue($row['provider_proof']);
        $this->assertSame('router_real_diff', $row['provider_proof_basis']);
        $this->assertContains('provider_call_count_unavailable', $ledger['blockers']);
        // still delivered because honest gates all pass
        $this->assertSame('delivered', $row['state']);
    }

    public function test_validation_failed_blocks_acceptance_never_delivered(): void
    {
        $plan = $this->plan('P5', [['id' => 'S1']]);
        $ledger = $this->service()->recordCycle([
            'decomposed_plan' => $plan,
            'area_id' => 'a',
            'cycle' => $this->cycle('S1', ['validation' => ['passed' => false]]),
        ]);
        $row = $ledger['slice_states']['S1'];
        $this->assertFalse($row['acceptance_met']);
        $this->assertSame('validation_failed', $row['acceptance_basis']);
        $this->assertNotSame('delivered', $row['state']);
    }

    public function test_acceptance_basis_pending_when_passed(): void
    {
        $plan = $this->plan('P5b', [['id' => 'S1']]);
        $ledger = $this->service()->recordCycle([
            'decomposed_plan' => $plan,
            'area_id' => 'a',
            'cycle' => $this->cycle('S1'),
        ]);
        $this->assertSame('operator_acceptance_pending', $ledger['slice_states']['S1']['acceptance_basis']);
    }

    public function test_unmatched_finding_id_appends_no_advance_returns_warning(): void
    {
        $plan = $this->plan('P6', [['id' => 'S1']]);
        $svc = $this->service();
        $ledger = $svc->recordCycle([
            'decomposed_plan' => $plan,
            'area_id' => 'a',
            'cycle' => $this->cycle('S999_does_not_exist'),
        ]);
        $this->assertContains('finding_id_unmatched_no_slice_advance', $ledger['warnings']);
        $this->assertSame(0, $ledger['delivered_count']);
        $this->assertSame('planned', $ledger['slice_states']['S1']['state']);
        // No event was appended -> ledger file should not exist.
        $this->assertFileDoesNotExist($svc->ledgerPath('P6', 'a'));
    }

    public function test_dependency_gate_out_of_order_merge_stays_partial(): void
    {
        $plan = $this->plan('P7', [
            ['id' => 'S1'],
            ['id' => 'S2', 'depends_on' => ['S1']],
        ]);
        $svc = $this->service();
        // Deliver S2 first (out of order) — S1 not delivered yet.
        $ledger = $svc->recordCycle([
            'decomposed_plan' => $plan,
            'area_id' => 'a',
            'cycle' => $this->cycle('S2'),
        ]);
        $s2 = $ledger['slice_states']['S2'];
        $this->assertFalse($s2['dependency_satisfied']);
        // merged out of order => NOT counted delivered
        $this->assertSame('in_progress', $s2['state']);
        $this->assertSame(0, $ledger['delivered_count']);
        $this->assertSame('partial', $ledger['status']);
    }

    public function test_ready_only_when_all_delivered_and_dependencies_satisfied(): void
    {
        $plan = $this->plan('P8', [
            ['id' => 'S1'],
            ['id' => 'S2', 'depends_on' => ['S1']],
        ]);
        $svc = $this->service();
        $svc->recordCycle(['decomposed_plan' => $plan, 'area_id' => 'a', 'cycle' => $this->cycle('S1')]);
        $ledger = $svc->recordCycle(['decomposed_plan' => $plan, 'area_id' => 'a', 'cycle' => $this->cycle('S2')]);

        $this->assertTrue($ledger['slice_states']['S2']['dependency_satisfied']);
        $this->assertSame('delivered', $ledger['slice_states']['S2']['state']);
        $this->assertSame(2, $ledger['delivered_count']);
        $this->assertSame('ready', $ledger['status']);
    }

    public function test_zero_slices_yields_blocked(): void
    {
        $plan = $this->plan('P9', []);
        $ledger = $this->service()->rollup('P9', 'a', $plan);
        $this->assertSame(0, $ledger['total_slices']);
        $this->assertSame('blocked', $ledger['status']);
        $this->assertSame(0.0, $ledger['completion_pct']);
    }

    public function test_idempotent_replay_no_double_count(): void
    {
        $plan = $this->plan('P10', [['id' => 'S1']]);
        $svc = $this->service();
        $cycle = $this->cycle('S1');
        $svc->recordCycle(['decomposed_plan' => $plan, 'area_id' => 'a', 'cycle' => $cycle]);
        $ledger = $svc->recordCycle(['decomposed_plan' => $plan, 'area_id' => 'a', 'cycle' => $cycle]);
        $this->assertSame(1, $ledger['delivered_count']);
        $this->assertSame(100.0, $ledger['completion_pct']);
    }

    public function test_corruption_tolerant_rollup(): void
    {
        $plan = $this->plan('P11', [['id' => 'S1']]);
        $svc = $this->service();
        $svc->recordCycle(['decomposed_plan' => $plan, 'area_id' => 'a', 'cycle' => $this->cycle('S1')]);

        $path = $svc->ledgerPath('P11', 'a');
        file_put_contents($path, "this is not json\n{bad\n", FILE_APPEND);

        $ledger = $svc->rollup('P11', 'a', $plan);
        $this->assertSame(1, $ledger['delivered_count']);
        $this->assertContains('corrupted_ledger_lines_skipped', $ledger['blockers']);
    }

    public function test_anti_fake_forged_provider_proof_input_is_ignored(): void
    {
        $plan = $this->plan('P12', [['id' => 'S1']]);
        // Forge: caller tries to claim provider_proof / acceptance via input fields AND
        // top-level provider_proof, while the real cycle used the router (no real proof).
        $cycle = $this->cycle('S1', ['owner_flow' => ['provider_router_used' => true]]);
        $cycle['provider_proof'] = true;        // forged
        $cycle['acceptance_met'] = true;        // forged
        $cycle['state'] = 'delivered';          // forged

        $ledger = $this->service()->recordCycle([
            'decomposed_plan' => $plan,
            'area_id' => 'a',
            'cycle' => $cycle,
        ]);
        $row = $ledger['slice_states']['S1'];
        // Derivation ignores caller input: router was used => no proof => not delivered.
        $this->assertFalse($row['provider_proof']);
        $this->assertNotSame('delivered', $row['state']);
        $this->assertNotSame('ready', $ledger['status']);
        $this->assertNotSame(100.0, $ledger['completion_pct']);
    }

    public function test_blocked_cycle_records_blocked_slice(): void
    {
        $plan = $this->plan('P13', [['id' => 'S1']]);
        $ledger = $this->service()->recordCycle([
            'decomposed_plan' => $plan,
            'area_id' => 'a',
            'cycle' => $this->cycle('S1', [
                'final_status' => 'blocked',
                'merge_performed' => false,
                'blockers' => ['awis_execution_gate_blocked'],
            ]),
        ]);
        $this->assertSame('blocked', $ledger['slice_states']['S1']['state']);
        $this->assertSame(0, $ledger['delivered_count']);
        $this->assertSame('partial', $ledger['status']);
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
