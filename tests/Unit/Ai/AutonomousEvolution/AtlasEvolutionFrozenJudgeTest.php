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

    /**
     * Sweep O-1: acceptance SEM frozen_globs dava zero proteção de tamper — o candidato
     * editava o próprio teste e passava. Os arquivos referenciados pelos commands viram
     * congelados implícitos quando o contrato não declara nenhum.
     */
    public function test_empty_frozen_globs_implicitly_freezes_the_command_referenced_test(): void
    {
        file_put_contents($this->ws.'/tests/subject_test.php', "<?php\necho 'green';\n");

        $acceptance = $this->acceptance();
        unset($acceptance['frozen_globs']);
        $v = (new AtlasEvolutionFrozenJudge)->score($this->ws, $acceptance);

        $this->assertFalse($v['passed'], 'editar o teste referenciado pelos commands tem que reprovar mesmo sem frozen_globs');
        $this->assertSame('frozen_path_tampered', $v['details']['reason']);
    }

    /**
     * Sweep O-1: um .gitignore auto-autorado (que se auto-ignora) escondia arquivos do
     * censo de scope/tamper — lógica real podia viver num sibling invisível. Arquivos de
     * regra de ignore entram SEMPRE no censo.
     */
    public function test_a_self_authored_gitignore_cannot_hide_files_from_the_census(): void
    {
        file_put_contents($this->ws.'/src/Subject.php', "<?php\nrequire __DIR__.'/../evil.php';\nfunction greet(){ return EVIL; }\n");
        file_put_contents($this->ws.'/evil.php', "<?php\nconst EVIL = 'hello';\n");
        file_put_contents($this->ws.'/.gitignore', "evil.php\n.gitignore\n");

        $v = (new AtlasEvolutionFrozenJudge)->score($this->ws, $this->acceptance());

        $this->assertFalse($v['passed'], 'o .gitignore auto-autorado tem que aparecer no censo e reprovar por scope');
        $this->assertContains('.gitignore', $v['details']['changed_files'] ?? $v['details']['out_of_scope_files'] ?? []);
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

    public function test_a_staged_out_of_scope_change_cannot_hide_from_the_census(): void
    {
        // REGRESSION (#7): `git add` removes a file from BOTH the unstaged diff and the untracked set,
        // so a provider that STAGES an out-of-scope edit was invisible to the scope/tamper census. The
        // census now also reads `git diff --cached`, so a staged sneaky file is still caught.
        file_put_contents($this->ws.'/src/Subject.php', "<?php\nfunction greet(){ return 'hello'; }\n");
        file_put_contents($this->ws.'/sneaky.php', "<?php // staged out-of-scope edit\n");
        $this->git(['git', 'add', '-A']); // STAGE everything — sneaky.php is now invisible to a plain `git diff`

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
