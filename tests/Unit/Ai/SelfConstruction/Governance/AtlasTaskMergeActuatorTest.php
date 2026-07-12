<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Governance;

use App\Services\Ai\EngineeringKernel\AuthorizedMergeAction;
use App\Services\Ai\SelfConstruction\Governance\AtlasTaskMergeActuator;
use App\Services\Ai\SelfConstruction\MergeGovernor\AtlasMergeGovernorReleaseDecisionLedger;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Governor revert leg — proves the actuator in a THROWAWAY temp-dir git repo (never the
 * real one, per the live-repo test guard the loop's sandbox floor requires).
 */
final class AtlasTaskMergeActuatorTest extends TestCase
{
    private string $repo = '';

    private string $ledgerPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = sys_get_temp_dir().'/atlas-revert-'.bin2hex(random_bytes(5));
        @mkdir($this->repo, 0775, true);
        $this->git(['init', '-q']);
        $this->git(['config', 'user.email', 'test@atlas.local']);
        $this->git(['config', 'user.name', 'Atlas Test']);
        $this->writeFile('README.md', "seed\n");
        $this->git(['add', 'README.md']);
        $this->git(['commit', '-q', '-m', 'seed']);

        $this->ledgerPath = sys_get_temp_dir().'/atlas-revert-ledger-'.bin2hex(random_bytes(5)).'.jsonl';
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->repo);
        @unlink($this->ledgerPath);
        parent::tearDown();
    }

    /** Lands a scoped commit exactly the way AtlasTaskScopedCommitter would, with its marker line. */
    private function landScopedCommit(string $taskPacketId, array $files, string $clientId = 'client-x'): string
    {
        foreach ($files as $rel => $content) {
            $this->writeFile($rel, $content);
        }
        $this->git(array_merge(['add', '--'], array_keys($files)));
        $message = "atlas-task {$taskPacketId}: do work\n\nAtlas-Task: {$taskPacketId}\nResolved-by: {$clientId}";
        $this->git(['commit', '-q', '-m', $message]);

        return trim($this->git(['rev-parse', 'HEAD'])['out']);
    }

    private function actuator(array $allowedFilesByTask, ?string $ledgerPath = null): AtlasTaskMergeActuator
    {
        $ledger = new AtlasMergeGovernorReleaseDecisionLedger($ledgerPath ?? $this->ledgerPath);
        $resolver = static fn (string $taskPacketId): array => $allowedFilesByTask[$taskPacketId] ?? [];

        return new AtlasTaskMergeActuator(null, $this->repo, $ledger, $resolver);
    }

    // ── (a) V1 translator cannot act without canonical release authority ─────

    public function test_v1_dry_run_translates_but_cannot_act_without_canonical_authority(): void
    {
        $sha = $this->landScopedCommit('task-a', ['app/A/Alpha.php' => "<?php // A\n"]);
        $headBefore = trim($this->git(['rev-parse', 'HEAD'])['out']);

        $r = $this->actuator(['task-a' => ['app/A/Alpha.php']])->revert('task-a');

        $this->assertTrue($r['dry_run']);
        $this->assertTrue($r['zero_effect']);
        $this->assertSame(AtlasTaskMergeActuator::REASON_AUTHORITY_NOT_PERSISTED, $r['reason']);

        $headAfter = trim($this->git(['rev-parse', 'HEAD'])['out']);
        $this->assertSame($headBefore, $headAfter, 'dry-run must never mutate git state');

        $ledgerRows = (new AtlasMergeGovernorReleaseDecisionLedger($this->ledgerPath))->all();
        $this->assertNotEmpty($ledgerRows, 'a receipt must be appended even for a dry-run plan');
    }

    // ── (b) V1 translator cannot mutate without canonical release authority ──

    public function test_v1_live_revert_is_zero_effect_without_canonical_authority(): void
    {
        $sha = $this->landScopedCommit('task-b', ['app/B/Beta.php' => "<?php // B\n"]);

        $r = $this->actuator(['task-b' => ['app/B/Beta.php']])->revert('task-b', dryRun: false);

        $this->assertTrue($r['zero_effect']);
        $this->assertFalse($r['reverted']);
        $this->assertSame(AtlasTaskMergeActuator::REASON_AUTHORITY_NOT_PERSISTED, $r['reason']);
        $this->assertFileExists($this->repo.'/app/B/Beta.php');

        $log = $this->git(['log', '--oneline'])['out'];
        $this->assertStringNotContainsString('Revert', $log);

        $ledgerRows = (new AtlasMergeGovernorReleaseDecisionLedger($this->ledgerPath))->all();
        $this->assertNotEmpty($ledgerRows);
        $taskIds = array_column($ledgerRows, 'task_packet_id');
        $this->assertContains('task-b', $taskIds);
    }

    // ── (c) each refusal case: distinct reason + receipt ──────────────────────

    public function test_ambiguous_sha_zero_candidates_is_refused(): void
    {
        $r = $this->actuator([])->revert('task-does-not-exist');

        $this->assertTrue($r['refused']);
        $this->assertSame(AtlasTaskMergeActuator::REASON_AMBIGUOUS_SHA, $r['reason']);
        $this->assertSame(0, $r['candidate_count']);

        $this->assertLedgerHasRejectedReason('task-does-not-exist', AtlasTaskMergeActuator::REASON_AMBIGUOUS_SHA);
    }

    public function test_ambiguous_sha_multiple_candidates_is_refused(): void
    {
        // Two separate commits both carry the SAME marker (should never happen, but must fail-closed).
        $this->landScopedCommit('task-dup', ['app/D1.php' => "<?php // 1\n"]);
        $this->landScopedCommit('task-dup', ['app/D2.php' => "<?php // 2\n"]);

        $r = $this->actuator(['task-dup' => ['app/D1.php', 'app/D2.php']])->revert('task-dup');

        $this->assertTrue($r['refused']);
        $this->assertSame(AtlasTaskMergeActuator::REASON_AMBIGUOUS_SHA, $r['reason']);
        $this->assertSame(2, $r['candidate_count']);
    }

    public function test_out_of_scope_file_is_refused(): void
    {
        $this->landScopedCommit('task-scope', ['app/Scope/InScope.php' => "<?php\n"]);

        // Recorded allowed scope does NOT include the file the commit actually touched.
        $r = $this->actuator(['task-scope' => ['app/Scope/SomethingElse.php']])->revert('task-scope');

        $this->assertTrue($r['refused']);
        $this->assertSame(AtlasTaskMergeActuator::REASON_OUT_OF_SCOPE_FILE, $r['reason']);
        $this->assertContains('app/Scope/InScope.php', $r['files']);

        $this->assertLedgerHasRejectedReason('task-scope', AtlasTaskMergeActuator::REASON_OUT_OF_SCOPE_FILE);
    }

    public function test_dirty_working_tree_file_is_refused(): void
    {
        $this->landScopedCommit('task-dirty', ['app/Dirty/File.php' => "<?php // v1\n"]);

        // Another worker is mid-task: the same file now has uncommitted changes.
        $this->writeFile('app/Dirty/File.php', "<?php // v2 uncommitted\n");

        $r = $this->actuator(['task-dirty' => ['app/Dirty/File.php']])->revert('task-dirty');

        $this->assertTrue($r['refused']);
        $this->assertSame(AtlasTaskMergeActuator::REASON_DIRTY_WORKING_TREE_FILE, $r['reason']);
        $this->assertContains('app/Dirty/File.php', $r['files']);

        $this->assertLedgerHasRejectedReason('task-dirty', AtlasTaskMergeActuator::REASON_DIRTY_WORKING_TREE_FILE);
    }

    public function test_petreo_forbidden_self_target_is_refused(): void
    {
        $forbidden = 'app/Services/Ai/AutonomousEvolution/AtlasLoopAutoMergeService.php';
        $this->landScopedCommit('task-petreo', [$forbidden => "<?php // tampering\n"]);

        $r = $this->actuator(['task-petreo' => [$forbidden]])->revert('task-petreo');

        $this->assertTrue($r['refused']);
        $this->assertSame(AtlasTaskMergeActuator::REASON_FORBIDDEN_SELF_TARGET, $r['reason']);
        $this->assertSame($forbidden, $r['path']);

        $this->assertLedgerHasRejectedReason('task-petreo', AtlasTaskMergeActuator::REASON_FORBIDDEN_SELF_TARGET);
    }

    public function test_ledger_unavailable_before_authority_produces_zero_effect(): void
    {
        $sha = $this->landScopedCommit('task-ledger-down', ['app/Ledger/Down.php' => "<?php // down\n"]);
        $headBefore = trim($this->git(['rev-parse', 'HEAD'])['out']);
        $blockedParent = sys_get_temp_dir().'/atlas-release-ledger-blocker-'.bin2hex(random_bytes(4));
        file_put_contents($blockedParent, 'not-a-directory');

        try {
            $r = $this->actuator(
                ['task-ledger-down' => ['app/Ledger/Down.php']],
                $blockedParent.'/release.jsonl',
            )->revert('task-ledger-down', dryRun: false);

            $this->assertTrue($r['zero_effect']);
            $this->assertFalse($r['reverted']);
            $this->assertSame(AtlasTaskMergeActuator::REASON_RELEASE_LEDGER_UNAVAILABLE, $r['reason']);
            $this->assertSame($sha, trim($this->git(['rev-parse', 'HEAD'])['out']));
            $this->assertSame($headBefore, trim($this->git(['rev-parse', 'HEAD'])['out']));
        } finally {
            @unlink($blockedParent);
        }
    }

    public function test_stale_and_revoked_authorities_produce_zero_effect(): void
    {
        $sha = $this->landScopedCommit('task-stale', ['app/Stale/File.php' => "<?php // stale\n"]);
        $actuator = $this->actuator(['task-stale' => ['app/Stale/File.php']]);
        $prepared = $actuator->prepareAuthorizedRevert('task-stale', dryRun: false);
        $action = AuthorizedMergeAction::fromArray($prepared['authorized_merge_action']);

        $stale = $actuator->act($action->withExpiresAt('2000-01-01T00:00:00+00:00'));
        $this->assertTrue($stale['zero_effect']);
        $this->assertSame(AtlasTaskMergeActuator::REASON_AUTHORITY_STALE, $stale['reason']);
        $this->assertSame($sha, trim($this->git(['rev-parse', 'HEAD'])['out']));

        $revoked = $actuator->act($action->withRevoked());
        $this->assertTrue($revoked['zero_effect']);
        $this->assertSame(AtlasTaskMergeActuator::REASON_AUTHORITY_REVOKED, $revoked['reason']);
        $this->assertSame($sha, trim($this->git(['rev-parse', 'HEAD'])['out']));
    }

    public function test_candidate_hash_tamper_produces_zero_effect(): void
    {
        $sha = $this->landScopedCommit('task-tamper', ['app/Tamper/File.php' => "<?php // tamper\n"]);
        $actuator = $this->actuator(['task-tamper' => ['app/Tamper/File.php']]);
        $prepared = $actuator->prepareAuthorizedRevert('task-tamper', dryRun: false);
        $payload = $prepared['authorized_merge_action'];
        $payload['candidate_hash'] = str_repeat('a', 64);
        $payload['authority_hash'] = '';

        $r = $actuator->act(AuthorizedMergeAction::fromArray($payload));

        $this->assertTrue($r['zero_effect']);
        $this->assertSame(AtlasTaskMergeActuator::REASON_CANDIDATE_HASH_TAMPERED, $r['reason']);
        $this->assertSame($sha, trim($this->git(['rev-parse', 'HEAD'])['out']));
    }

    public function test_missing_rollback_posture_produces_zero_effect(): void
    {
        $sha = $this->landScopedCommit('task-rollback-missing', ['app/Rollback/File.php' => "<?php // rollback\n"]);
        $actuator = $this->actuator(['task-rollback-missing' => ['app/Rollback/File.php']]);
        $prepared = $actuator->prepareAuthorizedRevert('task-rollback-missing', dryRun: false);

        $rows = (new AtlasMergeGovernorReleaseDecisionLedger($this->ledgerPath))->all();
        unset($rows[0]['rollback_posture']);
        file_put_contents($this->ledgerPath, json_encode($rows[0], JSON_UNESCAPED_SLASHES).PHP_EOL);

        $r = $actuator->act(AuthorizedMergeAction::fromArray($prepared['authorized_merge_action']));

        $this->assertTrue($r['zero_effect']);
        $this->assertSame(AtlasTaskMergeActuator::REASON_ROLLBACK_POSTURE_MISSING, $r['reason']);
        $this->assertSame($sha, trim($this->git(['rev-parse', 'HEAD'])['out']));
    }

    public function test_legacy_action_cannot_reach_post_effect_without_canonical_authority(): void
    {
        $this->landScopedCommit('task-uncertain', ['app/Uncertain/File.php' => "<?php // uncertain\n"]);
        $actuator = $this->actuator(['task-uncertain' => ['app/Uncertain/File.php']]);
        $prepared = $actuator->prepareAuthorizedRevert('task-uncertain', dryRun: false);

        $blockedParent = sys_get_temp_dir().'/atlas-settlement-ledger-blocker-'.bin2hex(random_bytes(4));
        file_put_contents($blockedParent, 'not-a-directory');

        try {
            $action = AuthorizedMergeAction::fromArray($prepared['authorized_merge_action'])
                ->withSettlementLedgerPath($blockedParent.'/settle.jsonl');

            $r = $actuator->act($action);

            $this->assertTrue($r['zero_effect']);
            $this->assertFalse($r['reverted']);
            $this->assertSame(AtlasTaskMergeActuator::REASON_AUTHORITY_NOT_PERSISTED, $r['reason']);
            $this->assertFileExists($this->repo.'/app/Uncertain/File.php');
        } finally {
            @unlink($blockedParent);
        }
    }

    private function assertLedgerHasRejectedReason(string $taskPacketId, string $reason): void
    {
        $rows = (new AtlasMergeGovernorReleaseDecisionLedger($this->ledgerPath))->all();
        $matching = array_values(array_filter(
            $rows,
            static fn (array $r): bool => ($r['task_packet_id'] ?? '') === $taskPacketId
                && in_array($reason, (array) ($r['reasons'] ?? []), true),
        ));
        $this->assertNotEmpty($matching, "expected a ledger receipt for {$taskPacketId} with reason {$reason}");
    }

    private function writeFile(string $rel, string $content): void
    {
        $path = $this->repo.'/'.$rel;
        @mkdir(\dirname($path), 0775, true);
        @file_put_contents($path, $content);
    }

    /** @return array{code:int,out:string,err:string} */
    private function git(array $args): array
    {
        $p = new Process(array_merge(['git'], $args), $this->repo);
        $p->run();

        return ['code' => (int) $p->getExitCode(), 'out' => $p->getOutput(), 'err' => $p->getErrorOutput()];
    }

}
