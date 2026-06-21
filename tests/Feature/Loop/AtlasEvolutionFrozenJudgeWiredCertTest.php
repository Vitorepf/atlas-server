<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasEvolutionFrozenJudge;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * GOVERNED WIRING-CERT (Guard 4e) — the FROZEN PROOF that activating a former ORPHAN certifies ONLY when it is
 * MEANINGFULLY load-bearing, and is BYTE-IDENTICAL when the contract/flag is absent.
 *
 * The load-bearing cases are the anti-farm ones (the failure I caught + reverted in the naive
 * wiredEarned+revert_recheck version): a cosmetic `new Orphan()` + a hardcoded test value MUST be REJECTED —
 * neutralizing the orphan's methods leaves the test green (the orphan is never invoked), so it is not killed.
 */
final class AtlasEvolutionFrozenJudgeWiredCertTest extends TestCase
{
    private string $ws;

    protected function setUp(): void
    {
        parent::setUp();
        config(['atlas.loop.refactor_wired_proof' => true]);

        $this->ws = sys_get_temp_dir().'/atlas-wired-judge-'.bin2hex(random_bytes(4));
        mkdir($this->ws.'/app', 0o755, true);
        mkdir($this->ws.'/tests', 0o755, true);

        // BASELINE: Orphan is a real orphan (Consumer does NOT reference it); the frozen test wants total()==20.
        file_put_contents($this->ws.'/app/Orphan.php', "<?php\nclass Orphan\n{\n    public function contribute(int \$n): int { return \$n * 10; }\n}\n");
        file_put_contents($this->ws.'/app/Consumer.php', "<?php\nclass Consumer\n{\n    public function total(int \$n): int { return \$n; }\n}\n");
        file_put_contents($this->ws.'/tests/consumer_test.php', "<?php\nrequire __DIR__.'/../app/Consumer.php';\nif ((new Consumer)->total(2) !== 20) { fwrite(STDERR, 'red'); exit(1); }\necho 'green';\n");

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

    private function wiredAcceptance(): array
    {
        return [
            'commands' => ['php tests/consumer_test.php'],
            'allowed_globs' => ['app/**'],
            'frozen_globs' => ['tests/**'],
            'metric_kind' => AtlasEvolutionFrozenJudge::METRIC_GATE,
            'wired_proof' => true,
            'wired_target' => ['orphan_path' => 'app/Orphan.php'],
        ];
    }

    private function setConsumer(string $body): void
    {
        file_put_contents($this->ws.'/app/Consumer.php', $body);
    }

    public function test_certifies_a_genuine_wiring_that_invokes_the_orphan(): void
    {
        // Consumer.total now DELEGATES to the orphan's behavior => test green AND neutralizing Orphan kills it.
        $this->setConsumer("<?php\nrequire_once __DIR__.'/Orphan.php';\nclass Consumer\n{\n    public function total(int \$n): int { return (new Orphan)->contribute(\$n); }\n}\n");

        $v = (new AtlasEvolutionFrozenJudge)->score($this->ws, $this->wiredAcceptance());

        $this->assertTrue($v['passed'], json_encode($v['details']));
        $this->assertSame('accepted', $v['details']['reason']);
    }

    public function test_rejects_a_cosmetic_instantiation_with_a_hardcoded_value(): void
    {
        // THE FARM: instantiate Orphan (so it has a caller) but HARDCODE the result. Test is green; Orphan has a
        // caller — yet neutralizing Orphan's methods leaves the test GREEN (no method is invoked) => not killed.
        $this->setConsumer("<?php\nrequire_once __DIR__.'/Orphan.php';\nclass Consumer\n{\n    public function total(int \$n): int { \$unused = new Orphan(); return 20; }\n}\n");

        $v = (new AtlasEvolutionFrozenJudge)->score($this->ws, $this->wiredAcceptance());

        $this->assertFalse($v['passed'], 'a cosmetic new Orphan() + hardcoded value must be REJECTED');
        $this->assertSame('wiring_not_earned', $v['details']['reason']);
        $this->assertFalse($v['details']['wired_proof']['method_kills'], 'neutralizing the orphan did not break the test => not load-bearing');
    }

    public function test_rejects_when_the_orphan_is_not_wired_at_all(): void
    {
        // Hardcode the value with NO orphan reference: test green, but the orphan still has 0 callers.
        $this->setConsumer("<?php\nclass Consumer\n{\n    public function total(int \$n): int { return 20; }\n}\n");

        $v = (new AtlasEvolutionFrozenJudge)->score($this->ws, $this->wiredAcceptance());

        $this->assertFalse($v['passed'], 'no wiring => the orphan stays dead => rejected');
        $this->assertSame('wiring_not_earned', $v['details']['reason']);
        $this->assertSame(0, $v['details']['wired_proof']['candidate_callers']);
    }

    public function test_flag_off_is_byte_identical_a_cosmetic_wiring_passes_as_a_plain_gate(): void
    {
        config(['atlas.loop.refactor_wired_proof' => false]);
        $this->setConsumer("<?php\nrequire_once __DIR__.'/Orphan.php';\nclass Consumer\n{\n    public function total(int \$n): int { \$unused = new Orphan(); return 20; }\n}\n");

        $v = (new AtlasEvolutionFrozenJudge)->score($this->ws, $this->wiredAcceptance());

        $this->assertTrue($v['passed'], 'flag OFF => no Guard 4e => the green candidate passes like a plain gate');
        $this->assertSame('accepted', $v['details']['reason']);
    }
}
