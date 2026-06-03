<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasEvolutionFrozenJudge;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class AtlasEvolutionFrozenJudgeTest extends TestCase
{
    private string $ws;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ws = sys_get_temp_dir().'/atlas-judge-'.bin2hex(random_bytes(4));
        mkdir($this->ws.'/src', 0o755, true);
        mkdir($this->ws.'/tests', 0o755, true);
        // target (the loop is allowed to edit this) — starts WRONG
        file_put_contents($this->ws.'/src/Subject.php', "<?php\nfunction greet(){ return 'helo'; }\n");
        // FROZEN test (the loop must NEVER edit this) — the acceptance
        file_put_contents($this->ws.'/tests/subject_test.php', "<?php\nrequire __DIR__.'/../src/Subject.php';\nif (greet() !== 'hello') { fwrite(STDERR,'red'); exit(1);} echo 'green';\n");
        $this->git(['git', 'init', '-q']);
        $this->git(['git', 'add', '-A']);
        $this->git(['git', '-c', 'user.email=t@t', '-c', 'user.name=t', '-c', 'commit.gpgsign=false', 'commit', '-q', '-m', 'baseline']);
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->ws]))->run();
        parent::tearDown();
    }

    private function git(array $argv): void
    {
        (new Process($argv, $this->ws))->run();
    }

    private function acceptance(): array
    {
        return [
            'commands' => ['php tests/subject_test.php'],
            'allowed_globs' => ['src/**'],
            'frozen_globs' => ['tests/**'],
            'metric_kind' => AtlasEvolutionFrozenJudge::METRIC_GATE,
        ];
    }

    public function test_accepts_a_passing_in_scope_candidate(): void
    {
        // candidate fixes the target correctly, only touches allowed src/
        file_put_contents($this->ws.'/src/Subject.php', "<?php\nfunction greet(){ return 'hello'; }\n");

        $v = (new AtlasEvolutionFrozenJudge)->score($this->ws, $this->acceptance());

        $this->assertTrue($v['passed'], json_encode($v['details']));
        $this->assertSame(1.0, $v['metric']);
        $this->assertSame('accepted', $v['details']['reason']);
        $this->assertSame(['src/Subject.php'], $v['details']['changed_files']);
    }

    public function test_rejects_tampering_with_a_frozen_test(): void
    {
        // candidate "passes" by weakening the FROZEN test — the classic Goodhart cheat
        file_put_contents($this->ws.'/tests/subject_test.php', "<?php\necho 'green';\n");

        $v = (new AtlasEvolutionFrozenJudge)->score($this->ws, $this->acceptance());

        $this->assertFalse($v['passed']);
        $this->assertSame('frozen_path_tampered', $v['details']['reason']);
        $this->assertSame(['tests/subject_test.php'], $v['details']['tampered_files']);
    }

    public function test_rejects_an_out_of_scope_change(): void
    {
        file_put_contents($this->ws.'/src/Subject.php', "<?php\nfunction greet(){ return 'hello'; }\n");
        file_put_contents($this->ws.'/sneaky.php', "<?php // touched a file outside src/\n");

        $v = (new AtlasEvolutionFrozenJudge)->score($this->ws, $this->acceptance());

        $this->assertFalse($v['passed']);
        $this->assertSame('out_of_scope_change', $v['details']['reason']);
        $this->assertContains('sneaky.php', $v['details']['out_of_scope_files']);
    }

    public function test_rejects_a_candidate_that_does_not_fix_the_target(): void
    {
        // candidate edits the target but still wrong
        file_put_contents($this->ws.'/src/Subject.php', "<?php\nfunction greet(){ return 'hi'; }\n");

        $v = (new AtlasEvolutionFrozenJudge)->score($this->ws, $this->acceptance());

        $this->assertFalse($v['passed']);
        $this->assertSame('acceptance_command_failed', $v['details']['reason']);
        $this->assertSame(0.0, $v['metric']);
    }

    public function test_strictly_better_prefers_pass_over_fail_and_breaks_no_ties_on_gate(): void
    {
        $judge = new AtlasEvolutionFrozenJudge;
        $pass = ['passed' => true, 'metric' => 1.0];
        $fail = ['passed' => false, 'metric' => 0.0];

        $this->assertTrue($judge->isStrictlyBetter($pass, $fail));
        $this->assertFalse($judge->isStrictlyBetter($fail, $pass));
        // two gate-passes tie (caller breaks ties, e.g. by smaller diff)
        $this->assertFalse($judge->isStrictlyBetter($pass, ['passed' => true, 'metric' => 1.0]));
        // minimize: lower wins
        $this->assertTrue($judge->isStrictlyBetter(
            ['passed' => true, 'metric' => 3.0],
            ['passed' => true, 'metric' => 5.0],
            AtlasEvolutionFrozenJudge::METRIC_MINIMIZE,
        ));
    }
}
