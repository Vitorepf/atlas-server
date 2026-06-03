<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasEvolutionFrozenJudge;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Proves the anti-fake DIFF-EARNED guard — the keystone of honest materialization for
 * framework-coupled code, where a test could pass on ambient state the diff did not
 * earn. The guard reverts the candidate's edits IN the grind workspace and requires the
 * baseline to go RED; a "green" that survives the revert is rejected as fake.
 */
final class AtlasEvolutionAntiFakeGuardTest extends TestCase
{
    /** @var list<string> */
    private array $workspaces = [];

    protected function tearDown(): void
    {
        foreach ($this->workspaces as $ws) {
            (new Process(['rm', '-rf', $ws]))->run();
        }
        parent::tearDown();
    }

    private function gitWorkspace(string $subjectBaseline, string $testBody): string
    {
        $ws = sys_get_temp_dir().'/atlas-antifake-'.bin2hex(random_bytes(4));
        @mkdir($ws.'/src', 0o755, true);
        @mkdir($ws.'/tests', 0o755, true);
        file_put_contents($ws.'/src/Subject.php', $subjectBaseline);
        file_put_contents($ws.'/tests/test.php', $testBody);
        foreach ([['git', 'init', '-q'], ['git', 'add', '-A'], ['git', '-c', 'user.email=a@b.c', '-c', 'user.name=a', '-c', 'commit.gpgsign=false', 'commit', '-q', '-m', 'base']] as $g) {
            (new Process($g, $ws, null, null, 60.0))->run();
        }
        $this->workspaces[] = $ws;

        return $ws;
    }

    private function acceptance(): array
    {
        return ['commands' => ['php tests/test.php'], 'allowed_globs' => ['src/**'], 'frozen_globs' => ['tests/**'], 'metric_kind' => 'gate', 'revert_recheck' => true];
    }

    public function test_rejects_a_fake_green_the_test_does_not_depend_on(): void
    {
        // A test that is green REGARDLESS of the target (asserts a tautology).
        $ws = $this->gitWorkspace(
            "<?php\nfunction subj(){ return 'x'; }\n",
            "<?php\nrequire __DIR__.'/../src/Subject.php';\nif (1 !== 1) { exit(1); }\necho 'ok';\n",
        );
        // Candidate makes a REAL but irrelevant edit (so there is a diff to stash).
        file_put_contents($ws.'/src/Subject.php', "<?php\n// touched by the loop\nfunction subj(){ return 'x'; }\n");

        $v = (new AtlasEvolutionFrozenJudge)->score($ws, $this->acceptance());

        $this->assertFalse($v['passed'], 'fake-green must be rejected: '.json_encode($v['details'] ?? $v));
        $this->assertSame('acceptance_not_diff_earned', $v['details']['reason']);
    }

    public function test_accepts_a_real_diff_the_test_genuinely_depends_on(): void
    {
        // RED on baseline (returns 'x'), GREEN only with the fix.
        $ws = $this->gitWorkspace(
            "<?php\nfunction subj(){ return 'x'; }\n",
            "<?php\nrequire __DIR__.'/../src/Subject.php';\nif (subj() !== 'fixed') { fwrite(STDERR,'red'); exit(1); }\necho 'ok';\n",
        );
        file_put_contents($ws.'/src/Subject.php', "<?php\nfunction subj(){ return 'fixed'; }\n");

        $v = (new AtlasEvolutionFrozenJudge)->score($ws, $this->acceptance());

        $this->assertTrue($v['passed'], 'a genuinely-earned fix must pass: '.json_encode($v['details'] ?? $v));
        $this->assertTrue($v['details']['diff_earned']);
    }

    public function test_rejects_a_noop_that_passes(): void
    {
        // The test is green with no change at all; the candidate edits nothing -> nothing to
        // stash -> cannot prove the diff earned it -> fail closed.
        $ws = $this->gitWorkspace(
            "<?php\nfunction subj(){ return 'x'; }\n",
            "<?php\necho 'ok';\n",
        );

        $v = (new AtlasEvolutionFrozenJudge)->score($ws, $this->acceptance());

        $this->assertFalse($v['passed']);
        $this->assertSame('acceptance_not_diff_earned', $v['details']['reason']);
    }

    public function test_rejects_a_fake_green_seeded_by_external_state_like_a_db_row(): void
    {
        // The operator's #1 vector: the candidate makes the test pass by SEEDING ambient
        // state during its run (a DB row, a cache entry, a /tmp marker) rather than by the
        // code. A `git stash` revert is workspace-only, so the external seed PERSISTS into
        // the re-check — and the green survives the code revert => fake => REJECT. (An
        // external marker is a faithful proxy for a leaked DB row; both are non-file,
        // out-of-tree state the scope guard cannot see.)
        $marker = sys_get_temp_dir().'/atlas-leak-marker-'.bin2hex(random_bytes(4));
        $ws = $this->gitWorkspace(
            "<?php\nfunction val(){ return 1; }\n",
            "<?php\nrequire __DIR__.'/../src/Subject.php';\n\$m = '".$marker."';\nif (val() === 2 || file_exists(\$m)) { echo 'ok'; exit(0); }\nfwrite(STDERR, 'red'); exit(1);\n",
        );
        // FAKE candidate: does NOT change val(); SEEDS the external marker as a load side effect.
        file_put_contents($ws.'/src/Subject.php', "<?php\n@file_put_contents('".$marker."', 'x');\nfunction val(){ return 1; }\n");

        $v = (new AtlasEvolutionFrozenJudge)->score($ws, $this->acceptance());
        if (is_file($marker)) {
            unlink($marker);
        }

        $this->assertFalse($v['passed'], 'external-state-seeded fake-green must be rejected: '.json_encode($v['details'] ?? $v));
        $this->assertSame('acceptance_not_diff_earned', $v['details']['reason']);
    }

    public function test_revert_recheck_is_off_by_default_preserving_proven_behaviour(): void
    {
        // Without revert_recheck, a passing candidate is accepted as before (no stash dance).
        $ws = $this->gitWorkspace(
            "<?php\nfunction subj(){ return 'x'; }\n",
            "<?php\nrequire __DIR__.'/../src/Subject.php';\nif (subj() !== 'fixed') { exit(1); }\necho 'ok';\n",
        );
        file_put_contents($ws.'/src/Subject.php', "<?php\nfunction subj(){ return 'fixed'; }\n");

        $acc = $this->acceptance();
        unset($acc['revert_recheck']);
        $v = (new AtlasEvolutionFrozenJudge)->score($ws, $acc);

        $this->assertTrue($v['passed']);
        $this->assertNull($v['details']['diff_earned']);
    }
}
