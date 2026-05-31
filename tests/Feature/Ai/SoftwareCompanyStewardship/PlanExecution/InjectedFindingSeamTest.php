<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SoftwareCompanyStewardship\PlanExecution;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSessionService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\OwnerFlowPlanSliceCycleExecutor;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Pilar 1 · Plan Execution · injected-finding seam.
 *
 * Proves the gap that made every backlog slice block on `ap726_handoff_hash_required` is
 * closed: a build-plan slice injected into the REAL AutonomousEvolutionSessionService is
 * selected as the cycle's finding (native deep-scan skipped) and flows through the proven
 * owner-flow machinery — building the AP-726 preflight/handoff — instead of blocking before
 * it. Runs in dry-run (execute=false) so NO provider is invoked and NO repo mutation occurs.
 */
final class InjectedFindingSeamTest extends TestCase
{
    public function test_injected_finding_is_selected_and_plans_past_handoff(): void
    {
        $session = app(AutonomousEvolutionSessionService::class);

        $finding = [
            'finding_id' => 'SEAMTEST1',
            'finding_hash' => 'seamtesthash01',
            'title' => 'Injected plan slice seam test',
            'kind' => 'plan_slice',
            'severity' => 'medium',
            'owner_candidate' => 'atlas_dev',
            'affected_files' => ['app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/PlanExecution/PlanSliceSelectionService.php'],
            'acceptance_criteria' => ['behaviour proven by a test'],
            'auto_execution_allowed' => true,
            'operator_review_required' => false,
        ];

        $result = $session->run([
            'area_id' => 'plan_seam_test',
            'focus' => 'dev_forge',
            'scope_profile' => 'balanced',
            'execute' => false,
            'cycles' => 1,
            'injected_finding' => $finding,
        ]);

        $cycle = $result['cycles'][0] ?? [];

        // The injected finding IS the selected finding (native scan was skipped).
        $this->assertSame('SEAMTEST1', $cycle['selected_finding']['finding_id'] ?? null);
        $this->assertTrue($cycle['priority_report']['plan_injected'] ?? false);

        // In dry-run the cycle PLANS — it never blocks on the handoff gate that the raw
        // owner-flow path tripped on. Real delivery still requires --execute + provider proof.
        $this->assertSame('dry_run_planned', $cycle['final_status'] ?? null);
        $this->assertNotContains('ap726_handoff_hash_required', (array) ($cycle['blockers'] ?? []));
    }

    public function test_executor_delegates_slice_to_session_dry_run(): void
    {
        $session = app(AutonomousEvolutionSessionService::class);
        $executor = new OwnerFlowPlanSliceCycleExecutor($session, false);

        $this->assertFalse($executor->isSimulated());

        $cycle = $executor->executeSlice(
            [
                'slice_id' => 'S1',
                'finding_id' => 'S1',
                'title' => 'first slice',
                'owner' => 'atlas_dev',
                'affected_files' => ['docs/engineering-knowledge-base/atlas-aaeos-loop-evolution-backlog.md'],
                'acceptance_criteria' => ['planned'],
            ],
            ['area_id' => 'plan_seam_test', 'focus' => 'dev_forge', 'scope_profile' => 'balanced', 'cycle_index' => 0],
        );

        $this->assertSame('S1', $cycle['selected_finding']['finding_id'] ?? null);
        $this->assertSame('S1', $cycle['plan_slice_id'] ?? null);
        $this->assertFalse($cycle['simulated'] ?? true);
        $this->assertNotContains('ap726_handoff_hash_required', (array) ($cycle['blockers'] ?? []));
    }

    public function test_execute_executor_blocks_dirty_base_before_provider_spend(): void
    {
        $repo = sys_get_temp_dir().'/atlas_plan_dirty_base_'.uniqid('', true);
        File::ensureDirectoryExists($repo.'/app');

        try {
            $this->git($repo, ['init', '-q', '-b', 'main']);
            $this->git($repo, ['config', 'user.email', 'atlas@example.test']);
            $this->git($repo, ['config', 'user.name', 'Atlas Test']);
            file_put_contents($repo.'/README.md', "# fixture\n");
            $this->git($repo, ['add', '-A']);
            $this->git($repo, ['commit', '-q', '-m', 'baseline']);
            file_put_contents($repo.'/operator-wip.md', "do not spend provider while dirty\n");

            $session = app(AutonomousEvolutionSessionService::class);
            $executor = new OwnerFlowPlanSliceCycleExecutor($session, true);

            $cycle = $executor->executeSlice(
                [
                    'slice_id' => 'DIRTY_BASE',
                    'finding_id' => 'DIRTY_BASE',
                    'title' => 'dirty base preflight',
                    'owner' => 'atlas_dev',
                    'affected_files' => ['app/DirtyBaseProof.php'],
                    'acceptance_criteria' => ['blocked before provider'],
                ],
                [
                    'repo_root' => $repo,
                    'area_id' => 'plan_seam_test',
                    'focus' => 'dev_forge',
                    'scope_profile' => 'balanced',
                    'cycle_index' => 0,
                ],
            );

            $this->assertSame('blocked', $cycle['final_status'] ?? null);
            $this->assertContains('base_worktree_dirty', (array) ($cycle['blockers'] ?? []));
            $this->assertFalse($cycle['provider_called'] ?? true);
            $this->assertFalse($cycle['provider_invoked'] ?? true);
            $this->assertFalse($cycle['branch_created'] ?? true);
            $this->assertFalse($cycle['worktree_created'] ?? true);
            $this->assertSame('DIRTY_BASE', $cycle['selected_finding']['finding_id'] ?? null);
            $this->assertSame('DIRTY_BASE', $cycle['plan_slice_id'] ?? null);
        } finally {
            File::deleteDirectory($repo);
        }
    }

    /**
     * @param  list<string>  $args
     */
    private function git(string $repo, array $args): void
    {
        $process = new Process(array_merge(['git'], $args), $repo);
        $process->setTimeout(30);
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
    }
}
