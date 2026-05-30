<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\PlanCompletionTrackerService;
use PHPUnit\Framework\TestCase;

/**
 * P1-TRACKER-DRIFT-RETRY · Findings 7 & 9.
 *
 * Finding 7: a stale ledger from an older plan version must NOT be silently re-projected as
 * current completion. recordCycle stamps the current plan_hash; rollup downgrades a slice whose
 * latest event carries a different plan_hash from delivered -> in_progress and raises
 * plan_drift_stale_slice:<slice_id>. Re-prove, never inherit.
 *
 * Finding 9: while folding the (already-read) history, rollup additionally counts per-slice
 * attempt_count and consecutive_non_delivered (counts only, no event blobs — I7 bounded), and
 * raises slice_stuck:<slice_id> once the non-delivered streak reaches 3 (anti-inertia).
 */
final class PlanCompletionTrackerDriftRetryTest extends TestCase
{
    private string $storageRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storageRoot = sys_get_temp_dir().'/atlas_plan_drift_retry_test_'.bin2hex(random_bytes(6));
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
    private function plan(string $planId, array $sliceSpecs, string $planHash): array
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
                'acceptance_criteria' => ['aceite '.$id],
                'authority_guard' => 'dev',
                'depends_on' => $spec['depends_on'] ?? [],
                'allowed_files' => [],
                'owner' => 'atlas_dev',
                'finding' => [
                    'title' => 'finding '.$id,
                    'detail' => 'd',
                    'affected_files' => [],
                    'owner_candidate' => 'atlas_dev',
                    'finding_id' => $id,
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
            'plan_hash' => $planHash,
        ];
    }

    /**
     * Fully real, merged, validated cycle by default.
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

        return array_replace($base, $overrides);
    }

    /** A blocked cycle (no merge) so the slice records non-delivered. */
    private function blockedCycle(string $findingId): array
    {
        return $this->cycle($findingId, [
            'final_status' => 'blocked',
            'merge_performed' => false,
            'blockers' => ['awis_execution_gate_blocked'],
            'changed_files' => [],
            'merge_governance' => ['status' => 'not_merged'],
        ]);
    }

    // ---------------------------------------------------------------- Finding 7

    public function test_slice_delivered_under_old_plan_hash_is_downgraded_with_drift_blocker(): void
    {
        $svc = $this->service();
        // Deliver S1 under plan v1.
        $planV1 = $this->plan('P1', [['id' => 'S1']], 'sha256:plan_v1');
        $delivered = $svc->recordCycle([
            'decomposed_plan' => $planV1,
            'area_id' => 'a',
            'cycle' => $this->cycle('S1'),
        ]);
        $this->assertSame('delivered', $delivered['slice_states']['S1']['state']);
        $this->assertSame('ready', $delivered['status']);

        // Rollup under plan v2 (same slice, new plan_hash) — drift.
        $planV2 = $this->plan('P1', [['id' => 'S1']], 'sha256:plan_v2');
        $ledger = $svc->rollup('P1', 'a', $planV2);

        $row = $ledger['slice_states']['S1'];
        $this->assertSame('in_progress', $row['state'], 'stale delivery must be re-proven, not inherited');
        $this->assertSame(0, $ledger['delivered_count']);
        $this->assertNotSame(100.0, $ledger['completion_pct']);
        $this->assertNotSame('ready', $ledger['status']);
        $this->assertContains(
            PlanCompletionTrackerService::BLOCKER_PLAN_DRIFT_STALE_SLICE.':S1',
            $ledger['blockers'],
        );
        $this->assertSame('sha256:plan_v2', $ledger['plan_hash']);
    }

    public function test_single_stable_plan_keeps_same_hash_and_delivered_set_regression(): void
    {
        $svc = $this->service();
        $plan = $this->plan('P2', [['id' => 'S1'], ['id' => 'S2']], 'sha256:plan_stable');
        $svc->recordCycle(['decomposed_plan' => $plan, 'area_id' => 'a', 'cycle' => $this->cycle('S1')]);
        $afterRecord = $svc->recordCycle(['decomposed_plan' => $plan, 'area_id' => 'a', 'cycle' => $this->cycle('S2')]);

        // Re-rollup with the SAME plan — no drift, delivered set unchanged.
        $ledger = $svc->rollup('P2', 'a', $plan);

        $this->assertSame('sha256:plan_stable', $ledger['plan_hash']);
        $this->assertSame($afterRecord['plan_hash'], $ledger['plan_hash']);
        $this->assertSame('delivered', $ledger['slice_states']['S1']['state']);
        $this->assertSame('delivered', $ledger['slice_states']['S2']['state']);
        $this->assertSame(2, $ledger['delivered_count']);
        $this->assertSame('ready', $ledger['status']);
        // No drift blocker on a stable plan.
        foreach ($ledger['blockers'] as $b) {
            $this->assertStringNotContainsString(
                PlanCompletionTrackerService::BLOCKER_PLAN_DRIFT_STALE_SLICE,
                $b,
            );
        }
    }

    // ---------------------------------------------------------------- Finding 9

    public function test_three_consecutive_non_delivered_surfaces_counts_and_slice_stuck(): void
    {
        $svc = $this->service();
        $plan = $this->plan('P3', [['id' => 'S1']], 'sha256:plan_p3');
        for ($i = 0; $i < 3; $i++) {
            $ledger = $svc->recordCycle([
                'decomposed_plan' => $plan,
                'area_id' => 'a',
                'cycle' => $this->blockedCycle('S1'),
            ]);
        }

        $row = $ledger['slice_states']['S1'];
        $this->assertSame(3, $row['attempt_count']);
        $this->assertSame(3, $row['consecutive_non_delivered']);
        $this->assertNotSame('delivered', $row['state']);
        $this->assertContains(
            PlanCompletionTrackerService::BLOCKER_SLICE_STUCK.':S1',
            $ledger['blockers'],
        );
    }

    public function test_a_later_delivery_resets_streak_and_clears_stuck(): void
    {
        $svc = $this->service();
        $plan = $this->plan('P4', [['id' => 'S1']], 'sha256:plan_p4');
        $svc->recordCycle(['decomposed_plan' => $plan, 'area_id' => 'a', 'cycle' => $this->blockedCycle('S1')]);
        $svc->recordCycle(['decomposed_plan' => $plan, 'area_id' => 'a', 'cycle' => $this->blockedCycle('S1')]);
        // Now a real delivery.
        $ledger = $svc->recordCycle(['decomposed_plan' => $plan, 'area_id' => 'a', 'cycle' => $this->cycle('S1')]);

        $row = $ledger['slice_states']['S1'];
        $this->assertSame(3, $row['attempt_count'], 'full history is counted');
        $this->assertSame(0, $row['consecutive_non_delivered'], 'a delivery resets the streak');
        $this->assertSame('delivered', $row['state']);
        foreach ($ledger['blockers'] as $b) {
            $this->assertStringNotContainsString(
                PlanCompletionTrackerService::BLOCKER_SLICE_STUCK,
                $b,
            );
        }
    }

    public function test_surfaced_rows_carry_counts_only_never_event_blobs_i7_bounded(): void
    {
        $svc = $this->service();
        $plan = $this->plan('P5', [['id' => 'S1']], 'sha256:plan_p5');
        $svc->recordCycle(['decomposed_plan' => $plan, 'area_id' => 'a', 'cycle' => $this->blockedCycle('S1')]);
        $ledger = $svc->recordCycle(['decomposed_plan' => $plan, 'area_id' => 'a', 'cycle' => $this->blockedCycle('S1')]);

        $row = $ledger['slice_states']['S1'];
        $this->assertIsInt($row['attempt_count']);
        $this->assertIsInt($row['consecutive_non_delivered']);
        // No raw event collections leaked onto the bounded row.
        $this->assertArrayNotHasKey('events', $row);
        $this->assertArrayNotHasKey('history', $row);
        $this->assertArrayNotHasKey('event_history', $row);
        // Every value on the row is a scalar or a flat string list — never a nested event blob.
        foreach ($row as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $this->assertIsString($item, "row key {$key} must be a flat string list, not event blobs");
                }
            }
        }
    }

    // ------------------------------------------------------- preserved invariants

    public function test_planned_slice_has_zero_counts_and_no_spurious_blockers(): void
    {
        $svc = $this->service();
        $plan = $this->plan('P6', [['id' => 'S1']], 'sha256:plan_p6');
        $ledger = $svc->rollup('P6', 'a', $plan);

        $row = $ledger['slice_states']['S1'];
        $this->assertSame('planned', $row['state']);
        $this->assertSame(0, $row['attempt_count']);
        $this->assertSame(0, $row['consecutive_non_delivered']);
        $this->assertSame([], $ledger['blockers']);
    }

    public function test_pre_stamp_event_without_plan_hash_is_not_treated_as_drift_backcompat(): void
    {
        // Simulate a legacy ledger line recorded before plan_hash stamping (no plan_hash field).
        $svc = $this->service();
        $plan = $this->plan('P7', [['id' => 'S1']], 'sha256:plan_p7');
        $path = $svc->ledgerPath('P7', 'a');
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $legacy = [
            'schema_version' => PlanCompletionTrackerService::EVENT_SCHEMA,
            'plan_id' => 'P7',
            'slice_id' => 'S1',
            'state' => 'delivered',
            'merge_hash' => 'abc123',
            'provider_proof' => true,
            'provider_proof_basis' => 'provider_call_count',
            'acceptance_met' => true,
            'acceptance_basis' => 'operator_acceptance_pending',
            'evidence_refs' => [],
            'recorded_at' => '2026-05-01T00:00:00+00:00',
        ];
        file_put_contents($path, json_encode($legacy).PHP_EOL, FILE_APPEND);

        $ledger = $svc->rollup('P7', 'a', $plan);
        // Absence of plan_hash is NOT drift: the slice stays delivered (back-compat preserved).
        $this->assertSame('delivered', $ledger['slice_states']['S1']['state']);
        foreach ($ledger['blockers'] as $b) {
            $this->assertStringNotContainsString(
                PlanCompletionTrackerService::BLOCKER_PLAN_DRIFT_STALE_SLICE,
                $b,
            );
        }
    }

    public function test_provider_proof_floor_is_no_weaker_under_drift_logic(): void
    {
        // Invariant guard: drift/retry additions must NOT relax provider-proof.
        $svc = $this->service();
        $plan = $this->plan('P8', [['id' => 'S1']], 'sha256:plan_p8');
        $ledger = $svc->recordCycle([
            'decomposed_plan' => $plan,
            'area_id' => 'a',
            'cycle' => $this->cycle('S1', ['owner_flow' => ['provider_router_used' => true]]),
        ]);
        $row = $ledger['slice_states']['S1'];
        $this->assertFalse($row['provider_proof']);
        $this->assertNotSame('delivered', $row['state']);
        $this->assertNotSame('ready', $ledger['status']);
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
