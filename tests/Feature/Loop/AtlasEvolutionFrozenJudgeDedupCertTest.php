<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasEvolutionFrozenJudge;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * GOVERNED DEDUP-CERT (Guard 4d) — the FROZEN PROOF that the judge certifies a clone-unification ONLY when
 * behavior is preserved (the frozen per-member sibling tests stay GREEN — the loop can never edit tests/**) AND
 * the targeted clone duplication is genuinely REMOVED (a count-drop measured by the judge's OWN parser, never a
 * provider number), and is BYTE-IDENTICAL to today when the dedup contract / flag is absent.
 *
 * Load-bearing: the no-op case — a "dedup" that adds a shared home but LEAVES both clone bodies (so the sibling
 * tests still pass) MUST be rejected with reason 'dedup_not_earned'. Behavior-preservation alone (Guard 3)
 * cannot catch it; only the count-drop does.
 */
final class AtlasEvolutionFrozenJudgeDedupCertTest extends TestCase
{
    private string $ws;

    /** The duplicated method body (byte-identical across A, B and the shared home). */
    private function body(): string
    {
        return "        \$sum = 0;\n        foreach (\$xs as \$x) {\n            if (\$x > 0) {\n                \$sum += \$x;\n            }\n        }\n\n        return \$sum;";
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['atlas.loop.refactor_dedup_proof' => true]);

        $this->ws = sys_get_temp_dir().'/atlas-dedup-judge-'.bin2hex(random_bytes(4));
        mkdir($this->ws.'/src', 0o755, true);
        mkdir($this->ws.'/tests', 0o755, true);

        // BASELINE: A and B each carry the duplicated body (the clone, count=2); Shared is the (already
        // present) consolidation home carrying the same body — but only A and B are clone_target members.
        $b = $this->body();
        file_put_contents($this->ws.'/src/Shared.php', "<?php\nclass Shared {\n    public static function calc(array \$xs): int\n    {\n{$b}\n    }\n}\n");
        file_put_contents($this->ws.'/src/A.php', "<?php\nrequire_once __DIR__.'/Shared.php';\nclass A {\n    public function calc(array \$xs): int\n    {\n{$b}\n    }\n}\n");
        file_put_contents($this->ws.'/src/B.php', "<?php\nrequire_once __DIR__.'/Shared.php';\nclass B {\n    public function calc(array \$xs): int\n    {\n{$b}\n    }\n}\n");

        // FROZEN per-member sibling tests (tests/** — the loop can NEVER edit them), each asserting real behavior.
        file_put_contents($this->ws.'/tests/a_test.php', "<?php\nrequire __DIR__.'/../src/A.php';\nif ((new A)->calc([1, -2, 3]) !== 4) { fwrite(STDERR, 'red'); exit(1); }\necho 'green';\n");
        file_put_contents($this->ws.'/tests/b_test.php', "<?php\nrequire __DIR__.'/../src/B.php';\nif ((new B)->calc([1, -2, 3]) !== 4) { fwrite(STDERR, 'red'); exit(1); }\necho 'green';\n");

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

    private function dedupAcceptance(): array
    {
        return [
            'commands' => ['php tests/a_test.php', 'php tests/b_test.php'],
            'allowed_globs' => ['src/**'],
            'frozen_globs' => ['tests/**'],
            'metric_kind' => AtlasEvolutionFrozenJudge::METRIC_MINIMIZE,
            'dedup_proof' => true,
            'clone_target' => ['members' => [['path' => 'src/A.php'], ['path' => 'src/B.php']]],
            'revert_recheck' => false,
        ];
    }

    /** Replace A's and B's bodies with a delegation to the shared home (the genuine unification). */
    private function applyUnification(): void
    {
        file_put_contents($this->ws.'/src/A.php', "<?php\nrequire_once __DIR__.'/Shared.php';\nclass A {\n    public function calc(array \$xs): int\n    {\n        return Shared::calc(\$xs);\n    }\n}\n");
        file_put_contents($this->ws.'/src/B.php', "<?php\nrequire_once __DIR__.'/Shared.php';\nclass B {\n    public function calc(array \$xs): int\n    {\n        return Shared::calc(\$xs);\n    }\n}\n");
    }

    public function test_certifies_a_genuine_unification_that_removes_both_clone_bodies(): void
    {
        $this->applyUnification();

        $v = (new AtlasEvolutionFrozenJudge)->score($this->ws, $this->dedupAcceptance());

        $this->assertTrue($v['passed'], json_encode($v['details']));
        $this->assertSame('accepted', $v['details']['reason']);
    }

    public function test_rejects_a_no_op_dedup_that_keeps_both_clone_bodies(): void
    {
        // The anti-farm worst-failure: a real diff (touch A cosmetically) but the clone bodies remain — both
        // sibling tests still pass (Guard 3 green), yet the duplication was NOT removed (count stays 2).
        file_put_contents(
            $this->ws.'/src/A.php',
            "<?php\nrequire_once __DIR__.'/Shared.php';\nclass A {\n    // a cosmetic touch, but the clone body stays\n    public function calc(array \$xs): int\n    {\n{$this->body()}\n    }\n}\n",
        );

        $v = (new AtlasEvolutionFrozenJudge)->score($this->ws, $this->dedupAcceptance());

        $this->assertFalse($v['passed'], 'a no-op dedup (bodies intact) must be rejected by the count-drop, not Guard 3');
        $this->assertSame('dedup_not_earned', $v['details']['reason']);
        $this->assertIsArray($v['details']['dedup_proof']);
        $this->assertFalse($v['details']['dedup_proof']['removed']);
    }

    public function test_flag_off_is_byte_identical_a_no_op_passes_as_a_plain_gate(): void
    {
        // With the operator flag OFF, even a dedup contract must NOT enter Guard 4d: the no-op (bodies intact)
        // passes purely on the frozen tests going green, exactly as a plain gate would today.
        config(['atlas.loop.refactor_dedup_proof' => false]);
        file_put_contents(
            $this->ws.'/src/A.php',
            "<?php\nrequire_once __DIR__.'/Shared.php';\nclass A {\n    // touched\n    public function calc(array \$xs): int\n    {\n{$this->body()}\n    }\n}\n",
        );

        $v = (new AtlasEvolutionFrozenJudge)->score($this->ws, $this->dedupAcceptance());

        $this->assertTrue($v['passed'], 'flag OFF => no Guard 4d => the green candidate passes like a plain gate');
        $this->assertSame('accepted', $v['details']['reason']);
    }
}
