<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSessionService;
use Illuminate\Support\Facades\File;
use ReflectionMethod;
use Tests\TestCase;

/**
 * AP-806 · The multi-agent workcell judge must be a HARD pre-merge gate. The
 * AP-790 ledger proved cycles 251-254/259 merged to main while the workcell
 * judge said repair_required (merge_eligible=false) — the exact false-success
 * the operator was burned by. These pin the gate: when the workcell is engaged
 * and the judge does NOT accept, the gate must refuse the merge; and the gate is
 * provider-free (it judges an already-executed result, never invokes a provider).
 */
final class WorkcellMergeGateTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_ap806_gate_'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function service(): AutonomousEvolutionSessionService
    {
        $service = app(AutonomousEvolutionSessionService::class);
        $service->setStorageDirForTesting($this->tmp.'/sessions');

        return $service;
    }

    /**
     * @param  array<string,mixed>  $cycleLike
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function gate(AutonomousEvolutionSessionService $service, array $cycleLike, array $input): array
    {
        $method = new ReflectionMethod($service, 'workcellMergeGate');

        return (array) $method->invoke($service, $cycleLike, $input);
    }

    /**
     * @param  array<string,mixed>  $cycleLike
     * @return array<string,mixed>|null
     */
    private function slice(AutonomousEvolutionSessionService $service, array $cycleLike): ?array
    {
        $method = new ReflectionMethod($service, 'workcellSliceFromCycle');
        $slice = $method->invoke($service, $cycleLike);

        return is_array($slice) ? $slice : null;
    }

    /**
     * @return array<string,mixed>
     */
    private function executedCycle(bool $validationPassed, array $changed = ['app/Services/Ai/Demo/DemoService.php']): array
    {
        return [
            'cycle_id' => 'ases_gate_'.($validationPassed ? 'pass' : 'fail'),
            'scope_profile' => 'balanced',
            'selected_finding' => [
                'finding_id' => 'afdf_gate_demo',
                'finding_hash' => 'sha256:afdf_gate_demo',
                'title' => 'Bounded step 1 (contract) — execute ONLY this step',
                'kind' => 'feature',
                'severity' => 'low',
                'owner_candidate' => 'atlas_dev',
                'affected_files' => ['app/Services/Ai/Demo/DemoService.php'],
            ],
            'allowed_files' => ['app/Services/Ai/Demo/DemoService.php'],
            'changed_files' => $changed,
            'provider_called' => true,
            'worktree_path' => $this->tmp.'/worktree',
            'branch_ref' => 'atlas/area-focus/demo',
            'inbox_item_id' => 'inbox_gate_demo',
            'result_bridge_id' => 'rb_gate_demo',
            'validation' => ['ran' => true, 'passed' => $validationPassed, 'commands' => ['php artisan test']],
        ];
    }

    public function test_gate_is_inert_when_workcell_flag_is_off(): void
    {
        $gate = $this->gate($this->service(), $this->executedCycle(true), [
            'area_id' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
        ]);

        // Flag off => the gate does not engage and never withholds a merge.
        $this->assertFalse($gate['engaged']);
        $this->assertTrue($gate['accept']);
        $this->assertNull($gate['workcell']);
    }

    public function test_gate_blocks_merge_when_judge_does_not_accept(): void
    {
        // A real owner-runtime result whose validation did NOT pass: the judge
        // must return repair_required/rejected, so merge_eligible=false and the
        // gate refuses the merge. This is the false-success path, now closed.
        $gate = $this->gate($this->service(), $this->executedCycle(false), [
            'area_id' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            'multi_agent_workcell' => true,
        ]);

        $this->assertTrue($gate['engaged'], 'workcell must engage on a real executed cycle');
        $this->assertFalse($gate['accept'], 'a non-accept judge verdict must NOT be mergeable');
        $this->assertNotSame('accepted_for_merge_governor', $gate['status']);
        $this->assertIsArray($gate['workcell']);
    }

    public function test_gate_blocks_merge_on_scope_violation(): void
    {
        // The diff touched a file outside allowed_files: the judge rejects on
        // scope, so the gate refuses the merge regardless of validation.
        $cycle = $this->executedCycle(true, ['app/Services/Ai/Demo/OutsideScope.php']);
        $gate = $this->gate($this->service(), $cycle, [
            'area_id' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            'multi_agent_workcell' => true,
        ]);

        $this->assertTrue($gate['engaged']);
        $this->assertFalse($gate['accept'], 'a scope violation must never be mergeable');
    }

    public function test_gate_allows_merge_when_judge_accepts_a_clean_validated_cycle(): void
    {
        // Non-starving direction: a clean, in-scope, validated bounded cycle with
        // evidence must ACCEPT (merge_eligible=true) so the gate does not block
        // legitimate work — the judge gates quality, it does not freeze the loop.
        $gate = $this->gate($this->service(), $this->executedCycle(true), [
            'area_id' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            'multi_agent_workcell' => true,
        ]);

        $this->assertTrue($gate['engaged']);
        $this->assertTrue($gate['accept'], 'a clean validated in-scope cycle must remain mergeable');
        $this->assertSame('accepted_for_merge_governor', $gate['status']);
        $this->assertTrue((bool) ($gate['workcell']['merge_eligible'] ?? false));
    }

    public function test_owner_flow_changed_files_are_not_lost_before_judge(): void
    {
        $changed = [
            'app/Services/Ai/Demo/DemoService.php',
            'tests/Unit/Ai/Demo/DemoServiceTest.php',
        ];

        $cycle = [
            'cycle_id' => 'ases_gate_owner_flow_changed_files',
            'scope_profile' => 'factory_max',
            'selected_finding' => [
                'finding_id' => 'afdf_gate_owner_flow_changed_files',
                'finding_hash' => 'sha256:afdf_gate_owner_flow_changed_files',
                'title' => 'Wire owner-flow changed files into workcell judge',
                'kind' => 'feature',
                'severity' => 'medium',
                'owner_candidate' => 'atlas_dev',
                'affected_files' => $changed,
            ],
            'allowed_files' => $changed,
            'provider_called' => false,
            'owner_flow' => [
                'uses_full_owner_runtime_chain' => true,
                'provider_invoked' => true,
                'provider_router_used' => false,
                'execution_result' => [
                    'provider_invoked' => true,
                    'changed_files' => $changed,
                ],
            ],
            'merge_governance' => ['status' => 'review_required'],
            'worktree_path' => $this->tmp.'/worktree',
            'branch_ref' => 'atlas/area-focus/demo',
            'inbox_item_id' => 'inbox_gate_demo',
            'result_bridge_id' => 'rb_gate_demo',
        ];

        $gate = $this->gate($this->service(), $cycle, [
            'area_id' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            'multi_agent_workcell' => true,
        ]);

        $this->assertTrue($gate['engaged']);
        $this->assertTrue($gate['accept']);
        $this->assertSame('accepted_for_merge_governor', $gate['status']);
        $this->assertNotSame('no_changed_files_to_judge', data_get($gate, 'workcell.judge_decision.reason'));
    }

    public function test_workcell_slice_uses_active_semantic_slice_not_first_slice(): void
    {
        $slice = $this->slice($this->service(), [
            'cycle_id' => 'aesc_active_slice',
            'selected_finding' => [
                'finding_id' => 'factory_max_ap786_owner_failure_specificity',
                'active_slice_id' => 'slice_skeleton',
            ],
            'finding_slice_plan' => [
                'decomposition_status' => 'sliced',
                'slices' => [
                    [
                        'slice_id' => 'slice_contract',
                        'decomposition' => 'semantic_step:contract',
                        'allowed_files' => ['app/Contract.php', 'tests/ContractTest.php'],
                    ],
                    [
                        'slice_id' => 'slice_skeleton',
                        'decomposition' => 'semantic_step:skeleton',
                        'allowed_files' => ['app/Skeleton.php', 'tests/SkeletonTest.php'],
                    ],
                ],
            ],
        ]);

        $this->assertSame('slice_skeleton', $slice['slice_id'] ?? null);
        $this->assertSame(['app/Skeleton.php', 'tests/SkeletonTest.php'], $slice['allowed_files'] ?? null);
    }
}
