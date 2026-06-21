<?php

declare(strict_types=1);

namespace Tests\Feature\Loop\Discovery;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopWiredAcceptanceProducer;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * §5.6 · ORPHAN-WIRING — the grind-time acceptance authoring bridge: a grind-authored test becomes a frozen
 * wired_proof acceptance ONLY when it is genuinely EARNED-RED on the pre-wiring baseline. An always-green test
 * (the writer-grades-itself failure) yields null — fail-closed.
 */
final class AtlasLoopWiredAcceptanceProducerTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $d) {
            (new Process(['rm', '-rf', $d]))->run();
        }
        parent::tearDown();
    }

    private function ws(): string
    {
        $d = sys_get_temp_dir().'/atlas-wired-accept-'.bin2hex(random_bytes(5));
        $this->dirs[] = $d;
        File::ensureDirectoryExists($d.'/tests');
        File::put($d.'/tests/red.php', "<?php\nfwrite(STDERR, 'red');\nexit(1);\n");   // fails on baseline
        File::put($d.'/tests/green.php', "<?php\necho 'ok';\n");                        // already green

        return $d;
    }

    public function test_an_earned_red_authored_test_becomes_a_frozen_wired_acceptance(): void
    {
        $ws = $this->ws();
        $acc = (new AtlasLoopWiredAcceptanceProducer)->produce(
            'app/Orphan.php',
            'tests/red.php',
            'php tests/red.php',
            ['app/**'],
            $ws,
        );

        $this->assertIsArray($acc);
        $this->assertTrue($acc['wired_proof']);
        $this->assertTrue($acc['earned_red']);
        $this->assertSame('app/Orphan.php', $acc['wired_target']['orphan_path']);
        $this->assertSame(['php tests/red.php'], $acc['commands']);
        $this->assertContains('tests/red.php', $acc['frozen_globs'], 'the authored test is frozen');
    }

    public function test_an_already_green_authored_test_is_rejected_fail_closed(): void
    {
        $ws = $this->ws();
        $acc = (new AtlasLoopWiredAcceptanceProducer)->produce(
            'app/Orphan.php',
            'tests/green.php',
            'php tests/green.php',
            ['app/**'],
            $ws,
        );

        $this->assertNull($acc, 'an always-green test proves no wiring is needed => the writer cannot grade itself');
    }

    public function test_empty_inputs_fail_closed(): void
    {
        $ws = $this->ws();
        $p = new AtlasLoopWiredAcceptanceProducer;
        $this->assertNull($p->produce('', 'tests/red.php', 'php tests/red.php', [], $ws));
        $this->assertNull($p->produce('app/Orphan.php', '', 'php tests/red.php', [], $ws));
        $this->assertNull($p->produce('app/Orphan.php', 'tests/red.php', '', [], $ws));
    }
}
