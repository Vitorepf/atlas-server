<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipIntegrationLaneService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipLiveCycleAuditService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipFirstLiveBranchProofService;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class StewardshipLiveCycleAuditServiceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_ap784_'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);

        $git = new Process(['git', '--version']);
        $git->run();
        if (! $git->isSuccessful()) {
            $this->markTestSkipped('git binary is required for AP-784 tests.');
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function service(): StewardshipLiveCycleAuditService
    {
        $service = app(StewardshipLiveCycleAuditService::class);
        $service->setStorageRootForTesting($this->tmp.'/storage');

        return $service;
    }

    public function test_clean_repo_with_lane_ahead_is_ready_for_promotion(): void
    {
        $repo = $this->repo();
        $this->branch($repo, 'atlas/area-focus/agentic_engineering_os/live-proof-audit');
        $this->commitFile($repo, 'docs/ap/live-proof-audit.md', "# proof\n", 'Proof');
        $candidateHead = $this->gitOut(['git', 'rev-parse', 'HEAD'], $repo);
        $this->checkout($repo, 'main');

        $lane = app(StewardshipIntegrationLaneService::class);
        $lane->setStorageRootForTesting($this->tmp.'/storage/integration_lanes');
        $lane->integrate([
            'repo_root' => $repo,
            'base_ref' => 'main',
            'branch_ref' => 'atlas/area-focus/agentic_engineering_os/live-proof-audit',
            'record' => true,
        ]);

        $mainBefore = $this->gitOut(['git', 'rev-parse', 'main'], $repo);
        $report = $this->service()->audit(['repo_root' => $repo, 'base_ref' => 'main']);

        $this->assertSame(StewardshipLiveCycleAuditService::REPORT_SCHEMA, $report['schema_version']);
        $this->assertTrue($report['base_clean']);
        $this->assertSame($mainBefore, $report['main_commit']);
        $this->assertNotSame('', $report['latest_lane_commit']);
        $this->assertContains($report['status'], [
            StewardshipLiveCycleAuditService::STATUS_READY,
        ]);
        $this->assertTrue($report['promotion_ready']);
        $this->assertTrue($report['real_steps']['branch_created']);
        $this->assertTrue($report['real_steps']['commit_created']);
        $this->assertTrue($report['real_steps']['integration_lane_advanced']);
        $this->assertFalse($report['real_steps']['main_promoted']);
        $this->assertSame($candidateHead, $report['latest_lane_commit']);
    }

    public function test_main_promoted_stays_true_when_main_advances_after_lane_promotion(): void
    {
        $repo = $this->repo();
        $this->branch($repo, 'atlas/area-focus/agentic_engineering_os/live-proof-promoted');
        $this->commitFile($repo, 'docs/ap/live-proof-promoted.md', "# proof\n", 'Proof');
        $candidateHead = $this->gitOut(['git', 'rev-parse', 'HEAD'], $repo);
        $this->checkout($repo, 'main');

        $lane = app(StewardshipIntegrationLaneService::class);
        $lane->setStorageRootForTesting($this->tmp.'/storage/integration_lanes');
        $lane->integrate([
            'repo_root' => $repo,
            'base_ref' => 'main',
            'branch_ref' => 'atlas/area-focus/agentic_engineering_os/live-proof-promoted',
            'record' => true,
        ]);

        $this->runGit(['git', 'merge', '--ff-only', 'atlas/integration/agentic_engineering_os/main'], $repo);
        $this->commitFile($repo, 'docs/ap/after-promotion.md', "# after\n", 'After promotion');

        $report = $this->service()->audit(['repo_root' => $repo, 'base_ref' => 'main']);

        $this->assertSame($candidateHead, $report['latest_lane_commit']);
        $this->assertTrue($report['real_steps']['commit_created']);
        $this->assertTrue($report['real_steps']['main_promoted']);
        $this->assertFalse($report['promotion_ready']);
        $this->assertNotContains('main_promotion (AP-769/AP-772 ff-only) — not performed on base_ref', $report['not_yet_real']);
    }

    public function test_reliable_24h_loop_receipts_count_as_owner_runtime_and_provider_evidence(): void
    {
        $repo = $this->repo();
        $ledgerDir = $this->tmp.'/storage/reliable_24h_loop';
        File::ensureDirectoryExists($ledgerDir);
        File::append($ledgerDir.'/agentic_engineering_os__dev_forge.jsonl', json_encode([
            'schema_version' => 'atlas.software_company_stewardship.ap790_reliable_24h_loop_cycle.v1',
            'cycle_final_status' => 'cycle_completed',
            'session_status' => 'completed',
            'loop_receipt' => [
                'branch_ref' => 'atlas/area-focus/agentic_engineering_os/atlas_dev/example',
                'worktree_path' => $this->tmp.'/worktrees/example',
            ],
            'multi_agent_workcell' => [
                'provider_invoked' => true,
            ],
        ], JSON_UNESCAPED_SLASHES)."\n");

        $report = $this->service()->audit([
            'repo_root' => $repo,
            'base_ref' => 'main',
            'focus' => 'dev_forge',
        ]);

        $this->assertSame(1, $report['receipt_signals']['ap790_reliable_loop_records']);
        $this->assertTrue($report['real_steps']['branch_created']);
        $this->assertTrue($report['real_steps']['worktree_created']);
        $this->assertTrue($report['real_steps']['owner_runtime_executed']);
        $this->assertTrue($report['real_steps']['provider_invoked']);
    }

    public function test_dirty_repo_with_lane_ahead_is_blocked(): void
    {
        $repo = $this->repo();
        $this->branch($repo, 'atlas/area-focus/agentic_engineering_os/live-proof-dirty');
        $this->commitFile($repo, 'docs/ap/live-proof-dirty.md', "# proof\n", 'Proof');
        $this->checkout($repo, 'main');
        File::put($repo.'/operator-scratch.txt', "dirty\n");

        $lane = app(StewardshipIntegrationLaneService::class);
        $lane->setStorageRootForTesting($this->tmp.'/storage/integration_lanes');
        $lane->integrate([
            'repo_root' => $repo,
            'base_ref' => 'main',
            'branch_ref' => 'atlas/area-focus/agentic_engineering_os/live-proof-dirty',
        ]);

        $report = $this->service()->audit(['repo_root' => $repo, 'base_ref' => 'main']);

        $this->assertSame(StewardshipLiveCycleAuditService::STATUS_BLOCKED, $report['status']);
        $this->assertFalse($report['base_clean']);
        $this->assertContains('base_worktree_dirty', $report['blockers']);
        $this->assertFalse($report['promotion_ready']);
    }

    public function test_without_lane_is_partial_with_integrate_next_action(): void
    {
        $repo = $this->repo();
        $report = $this->service()->audit(['repo_root' => $repo, 'base_ref' => 'main']);

        $this->assertSame(StewardshipLiveCycleAuditService::STATUS_PARTIAL, $report['status']);
        $this->assertSame([], $report['integration_lanes']);
        $this->assertStringContainsString('first-live-branch-proof', $report['next_real_action']);
        $this->assertContains('no_proof_branch_or_integration_lane_detected', $report['blockers']);
    }

    public function test_proof_branch_without_integration_lane_is_partial(): void
    {
        $repo = $this->repo();
        $this->branch($repo, 'atlas/area-focus/agentic_engineering_os/live-proof-only');
        $this->commitFile($repo, 'docs/ap/live-proof-only.md', "# proof\n", 'Proof only');
        $this->checkout($repo, 'main');

        $proof = app(StewardshipFirstLiveBranchProofService::class);
        $proof->setStorageRootForTesting($this->tmp.'/storage/live_proofs');

        $report = $this->service()->audit(['repo_root' => $repo, 'base_ref' => 'main']);

        $this->assertSame(StewardshipLiveCycleAuditService::STATUS_PARTIAL, $report['status']);
        $this->assertNotSame([], $report['proof_branches']);
        $this->assertSame([], $report['integration_lanes']);
        $this->assertTrue($report['real_steps']['branch_created']);
        $this->assertTrue($report['real_steps']['commit_created']);
        $this->assertFalse($report['real_steps']['integration_lane_advanced']);
        $this->assertStringContainsString('integration-lane', $report['next_real_action']);
    }

    public function test_audit_does_not_mutate_refs(): void
    {
        $repo = $this->repo();
        $this->branch($repo, 'atlas/area-focus/agentic_engineering_os/live-proof-stable');
        $this->commitFile($repo, 'docs/ap/live-proof-stable.md', "# proof\n", 'Proof');
        $this->checkout($repo, 'main');

        $lane = app(StewardshipIntegrationLaneService::class);
        $lane->setStorageRootForTesting($this->tmp.'/storage/integration_lanes');
        $lane->integrate([
            'repo_root' => $repo,
            'base_ref' => 'main',
            'branch_ref' => 'atlas/area-focus/agentic_engineering_os/live-proof-stable',
        ]);

        $mainBefore = $this->gitOut(['git', 'rev-parse', 'main'], $repo);
        $laneBefore = $this->gitOut(['git', 'rev-parse', 'atlas/integration/agentic_engineering_os/main'], $repo);
        $proofBefore = $this->gitOut(['git', 'rev-parse', 'atlas/area-focus/agentic_engineering_os/live-proof-stable'], $repo);

        $this->service()->audit(['repo_root' => $repo, 'base_ref' => 'main']);
        $this->service()->audit(['repo_root' => $repo, 'base_ref' => 'main']);

        $this->assertSame($mainBefore, $this->gitOut(['git', 'rev-parse', 'main'], $repo));
        $this->assertSame($laneBefore, $this->gitOut(['git', 'rev-parse', 'atlas/integration/agentic_engineering_os/main'], $repo));
        $this->assertSame($proofBefore, $this->gitOut(['git', 'rev-parse', 'atlas/area-focus/agentic_engineering_os/live-proof-stable'], $repo));
    }

    public function test_deferred_stages_are_listed_not_marked_done(): void
    {
        $repo = $this->repo();
        $report = $this->service()->audit(['repo_root' => $repo, 'base_ref' => 'main']);

        $this->assertFalse($report['real_steps']['provider_invoked']);
        $this->assertFalse($report['real_steps']['owner_runtime_executed']);
        $this->assertNotEmpty($report['not_yet_real']);
        $this->assertFalse($report['claim_policy']['deferred_counted_as_done']);
    }

    private function repo(): string
    {
        $repo = $this->tmp.'/repo';
        File::ensureDirectoryExists($repo.'/docs/ap');
        File::ensureDirectoryExists($repo.'/app');
        $this->runGit(['git', 'init'], $repo);
        $this->runGit(['git', 'config', 'user.email', 'atlas@example.test'], $repo);
        $this->runGit(['git', 'config', 'user.name', 'Atlas Test'], $repo);
        File::put($repo.'/docs/a.md', "base\n");
        File::put($repo.'/app/Foo.php', "<?php\n\nfinal class Foo {}\n");
        $this->runGit(['git', 'add', '.'], $repo);
        $this->runGit(['git', 'commit', '-m', 'Initial commit'], $repo);
        $this->runGit(['git', 'branch', '-M', 'main'], $repo);

        return $repo;
    }

    private function branch(string $repo, string $name): void
    {
        $this->runGit(['git', 'checkout', '-b', $name], $repo);
    }

    private function checkout(string $repo, string $name): void
    {
        $this->runGit(['git', 'checkout', $name], $repo);
    }

    private function commitFile(string $repo, string $path, string $contents, string $message): void
    {
        File::ensureDirectoryExists(dirname($repo.'/'.$path));
        File::put($repo.'/'.$path, $contents);
        $this->runGit(['git', 'add', $path], $repo);
        $this->runGit(['git', 'commit', '-m', $message], $repo);
    }

    /**
     * @param  list<string>  $command
     */
    private function runGit(array $command, string $cwd): void
    {
        $process = new Process($command, $cwd);
        $process->setTimeout(60);
        $process->run();
        $this->assertTrue($process->isSuccessful(), implode(' ', $command)."\n".$process->getErrorOutput());
    }

    /**
     * @param  list<string>  $command
     */
    private function gitOut(array $command, string $cwd): string
    {
        $process = new Process($command, $cwd);
        $process->setTimeout(60);
        $process->mustRun();

        return trim($process->getOutput());
    }
}
