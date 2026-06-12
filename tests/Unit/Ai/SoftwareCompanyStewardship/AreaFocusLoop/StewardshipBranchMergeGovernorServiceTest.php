<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipBranchMergeGovernorService;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class StewardshipBranchMergeGovernorServiceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_ap769_'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);

        $git = new Process(['git', '--version']);
        $git->run();
        if (! $git->isSuccessful()) {
            $this->markTestSkipped('git binary is required for AP-769 merge governor tests.');
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function service(): StewardshipBranchMergeGovernorService
    {
        $service = app(StewardshipBranchMergeGovernorService::class);
        $service->setStorageRootForTesting($this->tmp.'/governor');

        return $service;
    }

    private function repo(): string
    {
        $repo = $this->tmp.'/repo';
        File::ensureDirectoryExists($repo.'/docs');
        File::ensureDirectoryExists($repo.'/app');
        $this->runGit(['git', 'init'], $repo);
        $this->runGit(['git', 'config', 'user.email', 'atlas@example.test'], $repo);
        $this->runGit(['git', 'config', 'user.name', 'Atlas Test'], $repo);
        file_put_contents($repo.'/docs/README.md', "base docs\n");
        file_put_contents($repo.'/app/Foo.php', "<?php\n\nfinal class Foo {}\n");
        $this->runGit(['git', 'add', '.'], $repo);
        $this->runGit(['git', 'commit', '-m', 'Initial commit'], $repo);
        $this->runGit(['git', 'branch', '-M', 'main'], $repo);

        return $repo;
    }

    /**
     * @param  list<string>  $command
     */
    private function runGit(array $command, string $cwd): void
    {
        $process = new Process($command, $cwd);
        $process->setTimeout(30);
        $process->run();

        $this->assertTrue($process->isSuccessful(), implode(' ', $command)."\n".$process->getErrorOutput().$process->getOutput());
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
        file_put_contents($repo.'/'.$path, $contents);
        $this->runGit(['git', 'add', $path], $repo);
        $this->runGit(['git', 'commit', '-m', $message], $repo);
    }

    public function test_gitkraken_surface_links_cycle_traceability_metadata(): void
    {
        $repo = $this->repo();
        $this->branch($repo, 'atlas/area-focus/traceable');
        $this->commitFile($repo, 'docs/README.md', "base docs\ntraceable\n", 'Traceable docs');
        $this->checkout($repo, 'main');

        $report = $this->service()->evaluate([
            'repo_root' => $repo,
            'base_ref' => 'main',
            'branch_ref' => 'atlas/area-focus/traceable',
            'finding_id' => 'finding_001',
            'spec_id' => 'spec_001',
            'receipt_id' => 'receipt_001',
        ]);

        $this->assertSame('main', $report['gitkraken_review_surface']['visible_base_ref']);
        $this->assertSame('atlas/area-focus/traceable', $report['gitkraken_review_surface']['visible_branch_ref']);
        $this->assertSame('finding_001', $report['gitkraken_review_surface']['cycle_traceability']['finding_id']);
        $this->assertSame('spec_001', $report['gitkraken_review_surface']['cycle_traceability']['spec_id']);
        $this->assertSame('receipt_001', $report['gitkraken_review_surface']['cycle_traceability']['receipt_id']);
    }

    public function test_docs_only_branch_is_auto_merge_eligible_and_gitkraken_visible(): void
    {
        $repo = $this->repo();
        $this->branch($repo, 'atlas/area-focus/docs-safe');
        $this->commitFile($repo, 'docs/README.md', "base docs\nsafe update\n", 'Docs safe update');
        $this->checkout($repo, 'main');

        $report = $this->service()->evaluate([
            'repo_root' => $repo,
            'base_ref' => 'main',
            'branch_ref' => 'atlas/area-focus/docs-safe',
        ]);

        $this->assertSame(StewardshipBranchMergeGovernorService::STATUS_AUTO_MERGE_ELIGIBLE, $report['status']);
        $this->assertTrue($report['auto_merge_policy']['eligible']);
        $this->assertSame('documentation_only', $report['classification']['kind']);
        $this->assertSame('branch_on_top_of_base', $report['gitkraken_review_surface']['graph_shape']);
        $this->assertSame(['docs/README.md'], $report['gitkraken_review_surface']['changed_files']);
        $this->assertFalse($report['claim_policy']['merge_performed']);
    }

    /**
     * L3-9 #4: execute_merge=false must DURABLY prevent the merge across cycles. The
     * eligible branch is NOT placed in the auto-merge retry queue (which is a merge
     * executor that would land it on the next iteration), so the operator's `false`
     * decision actually holds. The branch is still reported AUTO_MERGE_ELIGIBLE/ready.
     */
    public function test_execute_merge_false_does_not_enqueue_for_later_auto_merge(): void
    {
        $repo = $this->repo();
        $this->branch($repo, 'atlas/area-focus/hold-no-merge');
        $this->commitFile($repo, 'docs/README.md', "base docs\nheld update\n", 'Docs held update');
        $this->checkout($repo, 'main');

        $service = $this->service();
        $report = $service->evaluate([
            'repo_root' => $repo,
            'base_ref' => 'main',
            'branch_ref' => 'atlas/area-focus/hold-no-merge',
            // The decisive operator decision: eligible by policy, but explicitly NOT merge.
            'auto_merge' => true,
            'execute_merge' => false,
        ]);

        // Eligible/ready but NOT merged and NOT queued for a later auto-merge.
        $this->assertSame(StewardshipBranchMergeGovernorService::STATUS_AUTO_MERGE_ELIGIBLE, $report['status']);
        $this->assertFalse($report['claim_policy']['merge_performed']);
        $this->assertFalse($report['merge_retry_queue_enqueued']);
        $this->assertSame('execute_merge_false_not_enqueued', $report['merge_retry_queue_reason']);

        // Durable proof: the retry queue (the cross-cycle merge executor) is empty, so
        // a subsequent processQueue() cannot resurrect the merge the operator vetoed.
        $queue = new \App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopMergeRetryQueueService;
        $queue->setStorageRootForTesting($this->tmp.'/governor/merge_retry_queue');
        $this->assertFalse($queue->hasPending(), 'execute_merge=false must not leave a pending auto-merge item');

        // main must remain untouched (no merge happened).
        $mainHead = trim((new Process(['git', 'rev-parse', 'main'], $repo))->mustRun()->getOutput());
        $branchHead = trim((new Process(['git', 'rev-parse', 'atlas/area-focus/hold-no-merge'], $repo))->mustRun()->getOutput());
        $this->assertNotSame($branchHead, $mainHead, 'main must not have advanced to the branch head');
    }

    public function test_atlas_governance_artifacts_do_not_false_block_docs_only_auto_merge(): void
    {
        $repo = $this->repo();
        $this->branch($repo, 'atlas/area-focus/docs-with-governance');
        $this->commitFile($repo, 'docs/README.md', "base docs\nsafe update\n", 'Docs safe update');
        File::ensureDirectoryExists($repo.'/.atlas/provider-prompts/cursor-cli');
        $this->commitFile(
            $repo,
            '.atlas/provider-prompts/cursor-cli/cursor-dev.json',
            '{"schema_version":"atlas.provider.cursor_cli.prompt.v1"}'."\n",
            'Record Atlas Dev provider prompt receipt',
        );
        $this->checkout($repo, 'main');

        $report = $this->service()->evaluate([
            'repo_root' => $repo,
            'base_ref' => 'main',
            'branch_ref' => 'atlas/area-focus/docs-with-governance',
        ]);

        $this->assertSame(StewardshipBranchMergeGovernorService::STATUS_AUTO_MERGE_ELIGIBLE, $report['status']);
        $this->assertTrue($report['auto_merge_policy']['eligible']);
        $this->assertSame('documentation_only', $report['classification']['kind']);
        $this->assertSame(
            ['.atlas/provider-prompts/cursor-cli/cursor-dev.json'],
            $report['classification']['governance_files'],
        );
        $this->assertNotContains('change_class_requires_operator_review', $report['auto_merge_policy']['reasons']);
        $this->assertTrue($report['throughput_evidence']['governance_artifacts_excluded_from_auto_merge_policy']);
        $this->assertSame(1, $report['throughput_evidence']['policy_changed_file_count']);
        $this->assertSame(2, $report['throughput_evidence']['full_changed_file_count']);
        $this->assertSame(
            ['.atlas/provider-prompts/cursor-cli/cursor-dev.json'],
            $report['throughput_evidence']['excluded_governance_paths'],
        );
        $this->assertSame('documentation_only', $report['throughput_evidence']['policy_classification_kind']);
        $this->assertSame('documentation_only', $report['throughput_evidence']['autonomy_classification_kind']);
        $this->assertTrue($report['throughput_evidence']['autonomy_uses_policy_surface']);
        $this->assertSame('documentation_only', $report['auto_merge_policy']['class']);
    }

    public function test_tests_only_branch_with_governance_artifacts_does_not_false_block_auto_merge(): void
    {
        $repo = $this->repo();
        $this->branch($repo, 'atlas/area-focus/tests-with-governance');
        $this->commitFile(
            $repo,
            'tests/Unit/FooTest.php',
            "<?php\n\nit('works', fn () => expect(true)->toBeTrue());\n",
            'Add focused test only',
        );
        File::ensureDirectoryExists($repo.'/.atlas/provider-prompts/cursor-cli');
        $this->commitFile(
            $repo,
            '.atlas/provider-prompts/cursor-cli/cursor-tests-only.json',
            '{"schema_version":"atlas.provider.cursor_cli.prompt.v1"}'."\n",
            'Record Atlas Dev provider prompt receipt',
        );
        $this->checkout($repo, 'main');

        $report = $this->service()->evaluate([
            'repo_root' => $repo,
            'base_ref' => 'main',
            'branch_ref' => 'atlas/area-focus/tests-with-governance',
        ]);

        $this->assertSame(StewardshipBranchMergeGovernorService::STATUS_AUTO_MERGE_ELIGIBLE, $report['status']);
        $this->assertTrue($report['auto_merge_policy']['eligible']);
        $this->assertSame('tests_only', $report['classification']['kind']);
        $this->assertSame('tests_only', $report['auto_merge_policy']['class']);
        $this->assertNotContains('change_class_requires_operator_review', $report['auto_merge_policy']['reasons']);
        $this->assertSame(1, $report['throughput_evidence']['policy_changed_file_count']);
        $this->assertSame(2, $report['throughput_evidence']['full_changed_file_count']);
        $this->assertSame('tests_only', $report['throughput_evidence']['policy_classification_kind']);
        $this->assertSame('tests_only', $report['throughput_evidence']['autonomy_classification_kind']);
    }

    public function test_governance_only_branch_stays_auto_merge_eligible_with_policy_surface_evidence(): void
    {
        $repo = $this->repo();
        $this->branch($repo, 'atlas/area-focus/governance-only');
        File::ensureDirectoryExists($repo.'/.atlas/provider-prompts/cursor-cli');
        $this->commitFile(
            $repo,
            '.atlas/provider-prompts/cursor-cli/cursor-governance-only.json',
            '{"schema_version":"atlas.provider.cursor_cli.prompt.v1"}'."\n",
            'Record governance-only provider receipt',
        );
        $this->checkout($repo, 'main');

        $report = $this->service()->evaluate([
            'repo_root' => $repo,
            'base_ref' => 'main',
            'branch_ref' => 'atlas/area-focus/governance-only',
        ]);

        $this->assertSame(StewardshipBranchMergeGovernorService::STATUS_AUTO_MERGE_ELIGIBLE, $report['status']);
        $this->assertTrue($report['auto_merge_policy']['eligible']);
        $this->assertTrue($report['classification']['governance_metadata_only']);
        $this->assertSame('documentation_only', $report['auto_merge_policy']['class']);
        $this->assertSame('empty', $report['throughput_evidence']['policy_classification_kind']);
        $this->assertSame('documentation_only', $report['throughput_evidence']['autonomy_classification_kind']);
        $this->assertSame(0, $report['throughput_evidence']['policy_changed_file_count']);
        $this->assertTrue($report['throughput_evidence']['autonomy_uses_policy_surface']);
    }

    public function test_six_governance_receipts_do_not_inflate_policy_changed_file_count(): void
    {
        $repo = $this->repo();
        $this->branch($repo, 'atlas/area-focus/docs-with-many-governance');
        $this->commitFile($repo, 'docs/README.md', "base docs\nsafe update\n", 'Docs safe update');
        File::ensureDirectoryExists($repo.'/.atlas/provider-prompts/cursor-cli');
        foreach (range(1, 6) as $index) {
            file_put_contents(
                $repo.'/.atlas/provider-prompts/cursor-cli/cursor-receipt-'.$index.'.json',
                '{"schema_version":"atlas.provider.cursor_cli.prompt.v1","receipt":'.$index.'}'."\n",
            );
        }
        $this->runGit(['git', 'add', '.atlas'], $repo);
        $this->runGit(['git', 'commit', '-m', 'Record six Atlas Dev provider prompt receipts'], $repo);
        $this->checkout($repo, 'main');

        $report = $this->service()->evaluate([
            'repo_root' => $repo,
            'base_ref' => 'main',
            'branch_ref' => 'atlas/area-focus/docs-with-many-governance',
        ]);

        $this->assertSame(StewardshipBranchMergeGovernorService::STATUS_AUTO_MERGE_ELIGIBLE, $report['status']);
        $this->assertTrue($report['auto_merge_policy']['eligible']);
        $this->assertSame(1, $report['throughput_evidence']['policy_changed_file_count']);
        $this->assertSame(7, $report['throughput_evidence']['full_changed_file_count']);
        $this->assertCount(6, $report['throughput_evidence']['excluded_governance_paths']);
        $this->assertNotContains('changed_file_count_exceeds_policy', $report['auto_merge_policy']['reasons']);
        $this->assertNotContains('risk_class_blocks_auto_merge', $report['auto_merge_policy']['reasons']);
    }

    public function test_factory_scoped_code_with_governance_artifact_can_auto_merge_after_green_validation(): void
    {
        $repo = $this->repo();
        $this->branch($repo, 'atlas/area-focus/factory-with-governance');
        File::ensureDirectoryExists(dirname($repo.'/app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipBranchMergeGovernorService.php'));
        File::ensureDirectoryExists(dirname($repo.'/tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipBranchMergeGovernorServiceTest.php'));
        File::ensureDirectoryExists($repo.'/.atlas/provider-prompts/cursor-cli');
        file_put_contents(
            $repo.'/app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipBranchMergeGovernorService.php',
            "<?php\n\n// focused factory patch\n",
        );
        file_put_contents(
            $repo.'/tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipBranchMergeGovernorServiceTest.php',
            "<?php\n\n// focused factory test\n",
        );
        file_put_contents(
            $repo.'/.atlas/provider-prompts/cursor-cli/cursor-factory.json',
            '{"schema_version":"atlas.provider.cursor_cli.prompt.v1"}'."\n",
        );
        $this->runGit(['git', 'add', '.'], $repo);
        $this->runGit(['git', 'commit', '-m', 'Factory patch with governance receipt'], $repo);
        $branchHead = trim((new Process(['git', 'rev-parse', '--short', 'HEAD'], $repo))->mustRun()->getOutput());
        $this->checkout($repo, 'main');

        $report = $this->service()->evaluate([
            'repo_root' => $repo,
            'base_ref' => 'main',
            'branch_ref' => 'atlas/area-focus/factory-with-governance',
            'allow_code_auto_merge' => true,
            'run_validation' => true,
            'test_commands' => ['true'],
            'auto_merge' => true,
            'execute_merge' => true,
        ]);

        $mainHead = trim((new Process(['git', 'rev-parse', '--short', 'main'], $repo))->mustRun()->getOutput());

        $this->assertSame(
            StewardshipBranchMergeGovernorService::STATUS_MERGED,
            $report['status'],
            json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '',
        );
        $this->assertSame($branchHead, $mainHead);
        $this->assertTrue($report['auto_merge_policy']['eligible']);
        $this->assertTrue($report['auto_merge_policy']['factory_scoped_code_auto_merge_authorized']);
        $this->assertSame(
            ['.atlas/provider-prompts/cursor-cli/cursor-factory.json'],
            $report['classification']['governance_files'],
        );
        $this->assertTrue($report['throughput_evidence']['governance_artifacts_excluded_from_auto_merge_policy']);
        $this->assertSame(2, $report['throughput_evidence']['policy_changed_file_count']);
    }

    public function test_phpunit_revalidation_in_worktree_uses_worktree_scoped_bootstrap(): void
    {
        // Regression: a sandbox worktree symlinks vendor/ to main, so phpunit there resolves
        // App\ to MAIN's app/ and a NEWLY CREATED class is "class not found" → false
        // validation_failed → new-class build slices could never merge. The governor must run
        // phpunit against a worktree-scoped bootstrap that maps App\ -> <worktree>/app.
        $repo = $this->repo();
        $this->branch($repo, 'atlas/area-focus/new-class-slice');
        $this->checkout($repo, 'main');

        $worktree = $this->tmp.'/wt_'.uniqid('', false);
        File::ensureDirectoryExists($worktree.'/vendor/bin');
        file_put_contents($worktree.'/vendor/autoload.php', "<?php\n");
        File::ensureDirectoryExists($worktree.'/app/Services/Ai/Aaeos');
        file_put_contents(
            $worktree.'/app/Services/Ai/Aaeos/NewSvc.php',
            "<?php\n\nnamespace App\\Services\\Ai\\Aaeos;\n\nclass NewSvc {}\n",
        );

        $report = $this->service()->evaluate([
            'repo_root' => $repo,
            'base_ref' => 'main',
            'branch_ref' => 'atlas/area-focus/new-class-slice',
            'worktree_path' => $worktree,
            'run_validation' => true,
            'test_commands' => ['./vendor/bin/phpunit tests/Unit/Ai/Aaeos/NewSvcTest.php'],
        ]);

        $phpunit = null;
        foreach (($report['validation']['results'] ?? []) as $r) {
            if (str_contains((string) ($r['command'] ?? ''), 'phpunit')) {
                $phpunit = $r;
                break;
            }
        }
        $this->assertNotNull($phpunit, 'expected a phpunit validation result');
        $this->assertStringContainsString('--bootstrap=', (string) $phpunit['command']);

        // The injected bootstrap maps App\ to THIS worktree's app/ (not main's).
        if (preg_match("/--bootstrap='([^']+)'/", (string) $phpunit['command'], $m) === 1) {
            $this->assertFileExists($m[1]);
            $this->assertStringContainsString($worktree.'/app', (string) file_get_contents($m[1]));
        } else {
            $this->fail('could not extract --bootstrap path from command');
        }

        // Control: a repo-root run (no separate worktree) gets NO bootstrap injection — the
        // default autoloader is already correct, so existing behaviour is untouched.
        $rootReport = $this->service()->evaluate([
            'repo_root' => $repo,
            'base_ref' => 'main',
            'branch_ref' => 'atlas/area-focus/new-class-slice',
            'run_validation' => true,
            'test_commands' => ['./vendor/bin/phpunit foo'],
        ]);
        foreach (($rootReport['validation']['results'] ?? []) as $r) {
            $this->assertStringNotContainsString('--bootstrap=', (string) ($r['command'] ?? ''));
        }
    }

    public function test_dirty_base_worktree_does_not_block_review_only_eligibility(): void
    {
        $repo = $this->repo();
        $this->branch($repo, 'atlas/area-focus/docs-safe-dirty-base');
        $this->commitFile($repo, 'docs/README.md', "base docs\nsafe update\n", 'Docs safe update');
        $this->checkout($repo, 'main');
        file_put_contents($repo.'/docs/uncommitted.md', "operator scratch\n");

        $report = $this->service()->evaluate([
            'repo_root' => $repo,
            'base_ref' => 'main',
            'branch_ref' => 'atlas/area-focus/docs-safe-dirty-base',
        ]);

        $this->assertSame(StewardshipBranchMergeGovernorService::STATUS_AUTO_MERGE_ELIGIBLE, $report['status']);
        $this->assertTrue($report['auto_merge_policy']['eligible']);
        $this->assertFalse($report['repo']['working_tree_clean']);
        $this->assertNotContains('base_worktree_dirty', $report['blockers']);
        $this->assertFalse($report['claim_policy']['dirty_base_blocks_review_only']);
        $this->assertTrue($report['claim_policy']['dirty_base_blocks_execute_merge']);
    }

    public function test_dirty_base_worktree_still_blocks_execute_merge(): void
    {
        $repo = $this->repo();
        $this->branch($repo, 'atlas/area-focus/docs-auto-dirty-base');
        $this->commitFile($repo, 'docs/README.md', "base docs\nauto merge\n", 'Docs auto merge');
        $this->checkout($repo, 'main');
        file_put_contents($repo.'/docs/uncommitted.md', "operator scratch\n");

        $service = $this->service();
        $report = $service->evaluate([
            'repo_root' => $repo,
            'base_ref' => 'main',
            'branch_ref' => 'atlas/area-focus/docs-auto-dirty-base',
            'auto_merge' => true,
            'execute_merge' => true,
        ]);

        $this->assertSame(StewardshipBranchMergeGovernorService::STATUS_BLOCKED, $report['status']);
        $this->assertContains('base_worktree_dirty', $report['blockers']);
        $this->assertFalse($report['claim_policy']['merge_performed']);
        $this->assertTrue($report['merge_retry_queue_enqueued']);
        $this->assertSame('base_worktree_dirty', $report['merge_retry_queue_reason']);

        $queuePath = $this->tmp.'/governor/merge_retry_queue/merge_retry_queue.jsonl';
        $this->assertFileExists($queuePath);
        $queue = array_values(array_filter(explode("\n", trim((string) file_get_contents($queuePath)))));
        $this->assertCount(1, $queue);
        $item = json_decode($queue[0], true);
        $this->assertSame('atlas/area-focus/docs-auto-dirty-base', $item['branch'] ?? null);
        $this->assertSame('base_worktree_dirty', $item['merge_attempt_reason'] ?? null);
    }

    public function test_code_branch_requires_operator_review_without_validation_and_code_authorization(): void
    {
        $repo = $this->repo();
        $this->branch($repo, 'atlas/area-focus/code-review');
        $this->commitFile($repo, 'app/Foo.php', "<?php\n\nfinal class Foo { public function ok(): bool { return true; } }\n", 'Code change');
        $this->checkout($repo, 'main');

        $report = $this->service()->evaluate([
            'repo_root' => $repo,
            'base_ref' => 'main',
            'branch_ref' => 'atlas/area-focus/code-review',
        ]);

        $this->assertSame(StewardshipBranchMergeGovernorService::STATUS_REVIEW_REQUIRED, $report['status']);
        $this->assertFalse($report['auto_merge_policy']['eligible']);
        $this->assertContains('change_class_requires_operator_review', $report['auto_merge_policy']['reasons']);
        $this->assertSame('code_or_mixed', $report['classification']['kind']);
    }

    public function test_authorized_focused_test_finding_with_code_fix_can_auto_merge_after_green_validation(): void
    {
        $repo = $this->repo();
        $this->branch($repo, 'atlas/area-focus/test-plus-code');
        $this->commitFile($repo, 'app/Foo.php', "<?php\n\nfinal class Foo { public function ok(): bool { return true; } }\n", 'Add focused test with runtime fix');
        $this->commitFile($repo, 'tests/Unit/FooTest.php', "<?php\n\nit('works', fn () => expect(true)->toBeTrue());\n", 'Add focused test');
        $branchHead = trim((new Process(['git', 'rev-parse', '--short', 'HEAD'], $repo))->mustRun()->getOutput());
        $this->checkout($repo, 'main');

        $report = $this->service()->evaluate([
            'repo_root' => $repo,
            'base_ref' => 'main',
            'branch_ref' => 'atlas/area-focus/test-plus-code',
            'auto_merge_class' => 'test',
            'allow_code_auto_merge' => true,
            'run_validation' => true,
            'test_commands' => ['true'],
            'auto_merge' => true,
            'execute_merge' => true,
        ]);

        $mainHead = trim((new Process(['git', 'rev-parse', '--short', 'main'], $repo))->mustRun()->getOutput());

        $this->assertSame(
            StewardshipBranchMergeGovernorService::STATUS_MERGED,
            $report['status'],
            json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '',
        );
        $this->assertSame($branchHead, $mainHead);
        $this->assertTrue($report['auto_merge_policy']['eligible']);
        $this->assertTrue($report['auto_merge_policy']['code_auto_merge_authorized']);
        $this->assertSame('p2_code_review_boundary', $report['auto_merge_policy']['risk_class']);
        $this->assertTrue($report['claim_policy']['merge_performed']);
    }

    public function test_artisan_test_validation_is_normalized_to_worktree_phpunit(): void
    {
        $repo = $this->repo();
        File::ensureDirectoryExists($repo.'/vendor/bin');
        file_put_contents(
            $repo.'/vendor/bin/phpunit',
            "#!/usr/bin/env php\n<?php\necho implode(' ', \$argv);\nexit(0);\n",
        );
        chmod($repo.'/vendor/bin/phpunit', 0755);
        file_put_contents($repo.'/phpunit.xml', "<phpunit />\n");
        file_put_contents($repo.'/artisan', "<?php\nexit(42);\n");
        $this->runGit(['git', 'add', 'vendor/bin/phpunit', 'phpunit.xml', 'artisan'], $repo);
        $this->runGit(['git', 'commit', '-m', 'Add base validation harness'], $repo);

        $this->branch($repo, 'atlas/area-focus/artisan-test-normalized');
        $this->commitFile($repo, 'app/Foo.php', "<?php\n\nfinal class Foo { public function ok(): bool { return true; } }\n", 'Add focused runtime patch');
        $branchHead = trim((new Process(['git', 'rev-parse', '--short', 'HEAD'], $repo))->mustRun()->getOutput());
        $this->checkout($repo, 'main');

        $report = $this->service()->evaluate([
            'repo_root' => $repo,
            'base_ref' => 'main',
            'branch_ref' => 'atlas/area-focus/artisan-test-normalized',
            'auto_merge_class' => 'test',
            'allow_code_auto_merge' => true,
            'run_validation' => true,
            'test_commands' => ['php artisan test tests/Unit/FooTest.php'],
            'auto_merge' => true,
            'execute_merge' => true,
        ]);

        $mainHead = trim((new Process(['git', 'rev-parse', '--short', 'main'], $repo))->mustRun()->getOutput());

        $this->assertSame(
            StewardshipBranchMergeGovernorService::STATUS_MERGED,
            $report['status'],
            json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '',
        );
        $this->assertSame($branchHead, $mainHead);
        $this->assertSame(
            './vendor/bin/phpunit --configuration=phpunit.xml tests/Unit/FooTest.php',
            $report['validation']['results'][0]['command'],
        );
        $this->assertSame(
            'php artisan test tests/Unit/FooTest.php',
            $report['validation']['results'][0]['requested_command'],
        );
        $this->assertStringContainsString('tests/Unit/FooTest.php', $report['validation']['results'][0]['output_excerpt']);
    }

    public function test_conflict_blocks_before_merge(): void
    {
        $repo = $this->repo();
        $this->branch($repo, 'atlas/area-focus/conflict');
        $this->commitFile($repo, 'docs/README.md', "branch edit\n", 'Branch edit');
        $this->checkout($repo, 'main');
        $this->commitFile($repo, 'docs/README.md', "main edit\n", 'Main edit');

        $report = $this->service()->evaluate([
            'repo_root' => $repo,
            'base_ref' => 'main',
            'branch_ref' => 'atlas/area-focus/conflict',
        ]);

        $this->assertSame(StewardshipBranchMergeGovernorService::STATUS_BLOCKED, $report['status']);
        $this->assertContains('branch_not_rebased_on_current_base', $report['blockers']);
        $this->assertContains('merge_conflict_detected', $report['blockers']);
        $this->assertFalse($report['merge_conflict_check']['clean']);
    }

    public function test_execute_merge_fast_forwards_only_when_policy_allows(): void
    {
        $repo = $this->repo();
        $this->branch($repo, 'atlas/area-focus/docs-auto');
        $this->commitFile($repo, 'docs/README.md', "base docs\nauto merge\n", 'Docs auto merge');
        $branchHead = trim((new Process(['git', 'rev-parse', '--short', 'HEAD'], $repo))->mustRun()->getOutput());
        $this->checkout($repo, 'main');

        $report = $this->service()->evaluate([
            'repo_root' => $repo,
            'base_ref' => 'main',
            'branch_ref' => 'atlas/area-focus/docs-auto',
            'auto_merge' => true,
            'execute_merge' => true,
            'record_governance' => true,
        ]);

        $mainHead = trim((new Process(['git', 'rev-parse', '--short', 'main'], $repo))->mustRun()->getOutput());
        $mainHeadFull = trim((new Process(['git', 'rev-parse', 'main'], $repo))->mustRun()->getOutput());

        $this->assertSame(StewardshipBranchMergeGovernorService::STATUS_MERGED, $report['status']);
        $this->assertSame($branchHead, $mainHead);
        // The recorded merge hash MUST be the real post-merge head, never a
        // stale/base commit (regression: a cached rev-parse recorded the base
        // commit as the merge hash, producing false merges).
        $this->assertSame($mainHeadFull, $report['merge_result']['new_head']);
        $this->assertNotSame($report['merge_result']['base_head'], $report['merge_result']['new_head']);
        $this->assertTrue($report['claim_policy']['merge_performed']);
        $this->assertSame('recorded', $report['governance_storage_status']);
        $this->assertFileExists($this->service()->recordPath('agentic_engineering_os'));
    }

    public function test_rebase_diverged_before_evaluation_opts_out_by_default(): void
    {
        $repo = $this->repo();
        $this->branch($repo, 'atlas/area-focus/diverged-no-rebase');
        $this->commitFile($repo, 'docs/feature.md', "feature\n", 'Feature docs');
        $this->checkout($repo, 'main');
        $this->commitFile($repo, 'docs/roadmap.md', "roadmap\n", 'Main advances');

        // Default: no rebase_diverged_before_evaluation — branch is blocked
        $report = $this->service()->evaluate([
            'repo_root' => $repo,
            'base_ref' => 'main',
            'branch_ref' => 'atlas/area-focus/diverged-no-rebase',
            'auto_merge' => true,
            'execute_merge' => true,
        ]);

        $this->assertSame(StewardshipBranchMergeGovernorService::STATUS_BLOCKED, $report['status']);
        $this->assertContains('branch_not_rebased_on_current_base', $report['blockers']);
        $this->assertNull($report['rebase_attempt']);
        $this->assertFalse($report['claim_policy']['rebase_performed']);
    }

    public function test_rebase_diverged_before_evaluation_rebases_and_merges_non_conflicting_branch(): void
    {
        $repo = $this->repo();

        // Create branch and add a docs commit.
        $this->branch($repo, 'atlas/area-focus/diverged-rebase');
        $this->commitFile($repo, 'docs/feature.md', "feature docs\n", 'Feature docs');
        $this->checkout($repo, 'main');

        // Advance main with a separate file (no conflict with branch).
        $this->commitFile($repo, 'docs/roadmap.md', "roadmap\n", 'Main advances');

        // Create a worktree for the branch so the governor can rebase inside it.
        $worktree = $this->tmp.'/wt_diverged';
        $this->runGit(['git', 'worktree', 'add', $worktree, 'atlas/area-focus/diverged-rebase'], $repo);

        $report = $this->service()->evaluate([
            'repo_root' => $repo,
            'base_ref' => 'main',
            'branch_ref' => 'atlas/area-focus/diverged-rebase',
            'worktree_path' => $worktree,
            'rebase_diverged_before_evaluation' => true,
            'auto_merge' => true,
            'execute_merge' => true,
        ]);

        // Rebase must have succeeded and merge must have landed.
        $this->assertSame(StewardshipBranchMergeGovernorService::STATUS_MERGED, $report['status']);
        $this->assertEmpty($report['blockers']);
        $this->assertNotNull($report['rebase_attempt']);
        $this->assertTrue($report['rebase_attempt']['ok']);
        $this->assertTrue($report['claim_policy']['rebase_performed']);
        $this->assertTrue($report['claim_policy']['merge_performed']);
    }

    public function test_rebase_diverged_before_evaluation_aborts_cleanly_on_conflict(): void
    {
        $repo = $this->repo();

        // Both branch and main edit the same file — rebase will conflict.
        $this->branch($repo, 'atlas/area-focus/conflict-rebase');
        $this->commitFile($repo, 'docs/README.md', "branch edit\n", 'Branch edit');
        $this->checkout($repo, 'main');
        $this->commitFile($repo, 'docs/README.md', "main edit\n", 'Main edit');

        $worktree = $this->tmp.'/wt_conflict';
        $this->runGit(['git', 'worktree', 'add', $worktree, 'atlas/area-focus/conflict-rebase'], $repo);

        $report = $this->service()->evaluate([
            'repo_root' => $repo,
            'base_ref' => 'main',
            'branch_ref' => 'atlas/area-focus/conflict-rebase',
            'worktree_path' => $worktree,
            'rebase_diverged_before_evaluation' => true,
            'auto_merge' => true,
            'execute_merge' => true,
        ]);

        // Rebase failed → governor falls back to normal divergence evaluation.
        $this->assertSame(StewardshipBranchMergeGovernorService::STATUS_BLOCKED, $report['status']);
        $this->assertNotNull($report['rebase_attempt']);
        $this->assertFalse($report['rebase_attempt']['ok']);
        $this->assertFalse($report['claim_policy']['rebase_performed']);
        // After abort the worktree must be clean (no in-progress rebase).
        $status = (new Process(['git', 'status', '--porcelain'], $worktree))->mustRun()->getOutput();
        $this->assertSame('', trim($status));
    }
}
