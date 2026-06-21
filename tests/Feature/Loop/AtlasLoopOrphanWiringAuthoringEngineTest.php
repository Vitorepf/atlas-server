<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasEvolutionFrozenJudge;
use App\Services\Ai\AutonomousEvolution\AtlasLoopOrphanWiringAuthoringEngine;
use App\Services\Ai\AutonomousEvolution\AtlasLoopOrphanWiringExecutionAdapter;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * §5.6 · ORPHAN-WIRING — the §9 authoring engine's DETERMINISTIC half: the strict parse + apply produce callables
 * the executor certifies (with a fixture completion double, ZERO spend). The cert stays author-blind: a fixture
 * that authors a COSMETIC wiring is rejected; a malformed/out-of-fence response yields no authoring.
 */
final class AtlasLoopOrphanWiringAuthoringEngineTest extends TestCase
{
    private string $ws;

    protected function setUp(): void
    {
        parent::setUp();
        config(['atlas.loop.refactor_wired_proof' => true]);
        $this->ws = sys_get_temp_dir().'/atlas-authoring-'.bin2hex(random_bytes(4));
        mkdir($this->ws.'/app', 0o755, true);
        file_put_contents($this->ws.'/app/Orphan.php', "<?php\nclass Orphan { public function contribute(int \$n): int { return \$n * 10; } }\n");
        file_put_contents($this->ws.'/app/Consumer.php', "<?php\nclass Consumer { public function total(int \$n): int { return \$n; } }\n");
        $this->git(['git', 'init', '-q']);
        $this->git(['git', 'add', '-A']);
        $this->git(['git', '-c', 'user.email=t@t', '-c', 'user.name=t', '-c', 'commit.gpgsign=false', 'commit', '-q', '-m', 'b']);
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

    private function payload(): array
    {
        return ['orphan_fqcn' => 'Orphan', 'orphan_path' => 'app/Orphan.php', 'public_methods' => ['contribute'], 'sibling_test' => 'tests/Unit/OrphanTest.php'];
    }

    private function response(string $wiringContent): string
    {
        return implode("\n", [
            '<<<TEST_REL>>>', 'tests/wiring_test.php',
            '<<<TEST_COMMAND>>>', 'php tests/wiring_test.php',
            '<<<TEST_CONTENT>>>', "<?php\nrequire __DIR__.'/../app/Consumer.php';\nif ((new Consumer)->total(2) !== 20) { fwrite(STDERR, 'red'); exit(1); }\necho 'green';",
            '<<<WIRING_REL>>>', 'app/Consumer.php',
            '<<<WIRING_CONTENT>>>', $wiringContent,
            '<<<END>>>',
        ]);
    }

    public function test_authored_genuine_wiring_certifies_through_the_executor(): void
    {
        $genuine = "<?php\nrequire_once __DIR__.'/Orphan.php';\nclass Consumer { public function total(int \$n): int { return (new Orphan)->contribute(\$n); } }";
        $engine = new AtlasLoopOrphanWiringAuthoringEngine(fn (string $p, string $pr): string => $this->response($genuine));

        $authoring = $engine->author($this->payload(), $this->ws);
        $this->assertIsArray($authoring);

        $result = (new AtlasLoopOrphanWiringExecutionAdapter)->execute('app/Orphan.php', $this->ws, $authoring['author_test'], $authoring['author_wiring']);
        $this->assertTrue($result['certified'], json_encode($result['verdict']['details'] ?? $result));
    }

    public function test_authored_cosmetic_wiring_is_rejected_author_blind(): void
    {
        $cosmetic = "<?php\nrequire_once __DIR__.'/Orphan.php';\nclass Consumer { public function total(int \$n): int { \$u = new Orphan(); return 20; } }";
        $engine = new AtlasLoopOrphanWiringAuthoringEngine(fn (string $p, string $pr): string => $this->response($cosmetic));

        $authoring = $engine->author($this->payload(), $this->ws);
        $result = (new AtlasLoopOrphanWiringExecutionAdapter)->execute('app/Orphan.php', $this->ws, $authoring['author_test'], $authoring['author_wiring']);

        $this->assertFalse($result['certified'], 'a cosmetic authored wiring is rejected by Guard 4e regardless of the engine');
        $this->assertSame('wiring_not_earned', $result['reason']);
    }

    public function test_parse_rejects_malformed_and_out_of_fence_responses(): void
    {
        $engine = new AtlasLoopOrphanWiringAuthoringEngine;
        $this->assertNull($engine->parse('no markers here'));
        // wiring outside app/ (escaping the scope) is rejected.
        $bad = str_replace('app/Consumer.php', 'config/atlas.php', $this->response('<?php // x'));
        $this->assertNull($engine->parse($bad), 'a wiring target outside app/ is fenced out');
        // a test outside tests/ is rejected.
        $bad2 = str_replace('tests/wiring_test.php', 'app/sneaky_test.php', $this->response('<?php // x'));
        $this->assertNull($engine->parse($bad2), 'a test outside tests/ is fenced out');
    }

    public function test_no_configured_provider_yields_no_authoring_fail_closed(): void
    {
        // default engine + no provider configured => liveCompletion returns null (no router call) => no authoring
        // (route degrades to no_winner). Guarantees the live seam is fail-closed without a provider.
        config(['atlas.loop.default_provider' => '']);
        $this->assertNull((new AtlasLoopOrphanWiringAuthoringEngine)->author($this->payload(), $this->ws));
    }
}
