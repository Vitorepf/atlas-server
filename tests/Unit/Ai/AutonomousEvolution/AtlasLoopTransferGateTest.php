<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasEvolutionFrozenJudge;
use App\Services\Ai\AutonomousEvolution\AtlasLoopTransferGate;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * ARBOR-GRAFT J2 — the transfer-slice holdout: a change must pass its own frozen acceptance AND not regress
 * a held-out task of a different type. Wraps the pétreo FrozenJudge (never edits it); conjunctive, fail-closed.
 */
final class AtlasLoopTransferGateTest extends TestCase
{
    private string $ws;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('atlas.loop.transfer_gate_enabled', true);
        $this->ws = sys_get_temp_dir().'/atlas-transfer-'.bin2hex(random_bytes(4));
        mkdir($this->ws.'/src', 0o755, true);
        mkdir($this->ws.'/tests', 0o755, true);
        // MAIN target (loop may fix it) — starts WRONG.
        file_put_contents($this->ws.'/src/Subject.php', "<?php\nfunction greet(){ return 'helo'; }\n");
        // TRANSFER target (a DIFFERENT task) — starts CORRECT (the held-out behavior must stay green).
        file_put_contents($this->ws.'/src/Other.php', "<?php\nfunction add(\$a,\$b){ return \$a + \$b; }\n");
        file_put_contents($this->ws.'/tests/subject_test.php', "<?php\nrequire __DIR__.'/../src/Subject.php';\nif (greet() !== 'hello') { exit(1);} echo 'ok';\n");
        file_put_contents($this->ws.'/tests/other_test.php', "<?php\nrequire __DIR__.'/../src/Other.php';\nif (add(2,3) !== 5) { exit(1);} echo 'ok';\n");
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

    private function main(): array
    {
        return ['commands' => ['php tests/subject_test.php'], 'allowed_globs' => ['src/**'], 'frozen_globs' => ['tests/**'], 'metric_kind' => AtlasEvolutionFrozenJudge::METRIC_GATE];
    }

    private function transfer(): array
    {
        return ['commands' => ['php tests/other_test.php'], 'allowed_globs' => ['src/**'], 'frozen_globs' => ['tests/**'], 'metric_kind' => AtlasEvolutionFrozenJudge::METRIC_GATE];
    }

    public function test_main_pass_and_transfer_holds_is_accepted(): void
    {
        file_put_contents($this->ws.'/src/Subject.php', "<?php\nfunction greet(){ return 'hello'; }\n"); // fix main; leave Other intact
        $v = (new AtlasLoopTransferGate(new AtlasEvolutionFrozenJudge))->verify($this->ws, $this->main(), $this->transfer());
        $this->assertTrue($v['ok'], json_encode($v));
        $this->assertSame('transfer_held', $v['reason']);
    }

    public function test_main_pass_but_transfer_regresses_is_rejected(): void
    {
        // fix main AND break the held-out transfer task (in-scope edit, so no tamper) => must be rejected.
        file_put_contents($this->ws.'/src/Subject.php', "<?php\nfunction greet(){ return 'hello'; }\n");
        file_put_contents($this->ws.'/src/Other.php', "<?php\nfunction add(\$a,\$b){ return \$a - \$b; }\n");
        $v = (new AtlasLoopTransferGate(new AtlasEvolutionFrozenJudge))->verify($this->ws, $this->main(), $this->transfer());
        $this->assertFalse($v['ok'], json_encode($v));
        $this->assertSame('transfer_regressed', $v['reason']);
    }

    public function test_main_failure_is_rejected_before_transfer(): void
    {
        // do not fix main => main fails, transfer not even consulted.
        $v = (new AtlasLoopTransferGate(new AtlasEvolutionFrozenJudge))->verify($this->ws, $this->main(), $this->transfer());
        $this->assertFalse($v['ok']);
        $this->assertSame('main_failed', $v['reason']);
        $this->assertArrayNotHasKey('transfer', $v);
    }

    public function test_flag_off_or_no_transfer_is_byte_identical(): void
    {
        file_put_contents($this->ws.'/src/Subject.php', "<?php\nfunction greet(){ return 'hello'; }\n");
        // no transfer acceptance => main stands alone
        $v = (new AtlasLoopTransferGate(new AtlasEvolutionFrozenJudge))->verify($this->ws, $this->main(), null);
        $this->assertTrue($v['ok']);
        $this->assertSame('main_passed_no_transfer', $v['reason']);

        // flag OFF + transfer declared => still skips the transfer slice
        config()->set('atlas.loop.transfer_gate_enabled', false);
        $v2 = (new AtlasLoopTransferGate(new AtlasEvolutionFrozenJudge))->verify($this->ws, $this->main(), $this->transfer());
        $this->assertTrue($v2['ok']);
        $this->assertSame('main_passed_no_transfer', $v2['reason']);
    }
}
