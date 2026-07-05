<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopObjectiveProducer;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopOriginationBuilder;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopStateOfAtlasReader;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopAdversarialCritic;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopLeverageScorer;
use App\Services\Ai\AutonomousEvolution\Discovery\StateOfAtlas;
use App\Services\Ai\AutonomousEvolution\Verify\AtlasLoopSignalAnalyzer;
use ReflectionMethod;
use Tests\TestCase;

/**
 * ██   ██  █████  ██████  ███████ ███████ 
 * ██   ██ ██   ██ ██   ██    ███    ███  
 * ██   ██ ███████ ██████    ███    ███  
 * ██   ██ ██   ██ ██   ██  ███    ███  
 *  ██████  ██   ██ ██████  ███████ ███████
 *
 * MULTI-FILE OBJECTIVE ORIGINATION — AtlasLoopObjectiveProducer multi-file gate.
 *
 * Tests that the producer's multi_file_origination_enabled flag correctly gates
 * multi-file vs single-file origination. When the flag is ON and the winner has
 * production callers, the producer emits a multi_file_refactor objective with
 * allowed_files spanning the hub + its callers. When the flag is OFF, it falls
 * through to single-file origination (existing behaviour, byte-identical).
 */
final class AtlasLoopObjectiveProducerMultiFileTest extends TestCase
{
    /** @var list<string> temp dirs to clean up */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->rmdir($dir);
        }
        parent::tearDown();
    }

    // ───── buildMultiFileObjective (pure method, via reflection) ─────

    public function test_build_multi_file_objective_format(): void
    {
        $winner = [
            'path' => 'app/Services/Hub.php',
            '_score' => ['leverage' => 0.75, 'rationale' => 'big wired hub'],
        ];
        $callerPaths = ['app/Callers/CallerA.php', 'app/Callers/CallerB.php'];
        $verdict = [
            'pick' => $winner,
            'challenged' => false,
            'reason' => 'no challenge',
        ];

        $result = $this->invokeBuildMultiFile($winner, $callerPaths, $verdict, null);

        $this->assertSame('multi_file_refactor', $result['shape']);
        $this->assertFalse($result['self_contained'], 'multi-file objectives are NOT self-contained');
        $this->assertSame('app/Services/Hub.php', $result['target_path']);
        $this->assertSame(0.75, $result['leverage']);
        $this->assertTrue($result['payload']['multi_file']);
        $this->assertSame(['app/Callers/CallerA.php', 'app/Callers/CallerB.php'], $result['payload']['caller_paths']);
        $this->assertCount(3, $result['payload']['allowed_files']);
        $this->assertContains('app/Services/Hub.php', $result['payload']['allowed_files']);
        $this->assertContains('app/Callers/CallerA.php', $result['payload']['allowed_files']);
        $this->assertContains('app/Callers/CallerB.php', $result['payload']['allowed_files']);
        $this->assertStringContainsString('multi-file', $result['rationale']);
        $this->assertStringContainsString('hub=app/Services/Hub.php', $result['rationale']);
        $this->assertStringContainsString('callers=2', $result['rationale']);
        $this->assertNotEmpty($result['acceptance_hash']);
        $this->assertIsString($result['objective']);
    }

    public function test_build_multi_file_objective_with_ev_pick_and_critic_challenge(): void
    {
        $winner = [
            'path' => 'app/Services/Hub.php',
            '_score' => ['leverage' => 0.85, 'rationale' => 'wired hub'],
        ];
        $callerPaths = ['app/Callers/CallerA.php'];
        $verdict = [
            'pick' => $winner,
            'challenged' => true,
            'reason' => 'ratio-winner was cheap, promoted hub',
        ];
        $evPick = ['binding_axis' => 'wired', 'relief' => 0.95];

        $result = $this->invokeBuildMultiFile($winner, $callerPaths, $verdict, $evPick);

        $this->assertSame('multi_file_refactor', $result['shape']);
        $this->assertCount(2, $result['payload']['allowed_files'], 'hub + 1 caller = 2 files');
        $this->assertStringContainsString(
            '[critic: ratio-winner was cheap, promoted hub]',
            $result['rationale'],
        );
        $this->assertStringContainsString('[ev: binding=wired relief=0.95]', $result['rationale']);
    }

    // ───── produce() routing: flag ON with callers → multi-file ─────

    public function test_flag_on_with_callers_produces_multi_file_objective(): void
    {
        config(['atlas.loop.multi_file_origination_enabled' => true]);
        $repoRoot = $this->tempRepo();

        $producer = new AtlasLoopObjectiveProducer(
            stateReader: $this->fakeStateReader(),
            analyzer: $this->fakeAnalyzer(),
        );

        $result = $producer->produce(
            $repoRoot,
            ['app/Hub.php', 'app/CallerA.php', 'app/CallerB.php'],
            '',
            '',
        );

        $this->assertNotNull($result, 'produce() should return a multi-file objective');
        $this->assertSame('multi_file_refactor', $result['shape']);
        $this->assertTrue($result['payload']['multi_file'] ?? false);
        $this->assertCount(3, $result['payload']['allowed_files'] ?? []);
        $this->assertContains('app/Hub.php', $result['payload']['allowed_files']);
        $this->assertContains('app/CallerA.php', $result['payload']['allowed_files']);
        $this->assertContains('app/CallerB.php', $result['payload']['allowed_files']);
        $this->assertStringContainsString('multi-file', $result['rationale']);
    }

    // ───── produce() routing: flag OFF → single-file ─────

    public function test_flag_off_produces_single_file_objective(): void
    {
        config(['atlas.loop.multi_file_origination_enabled' => false]);
        $repoRoot = $this->tempRepo();

        $origination = $this->createMock(AtlasLoopOriginationBuilder::class);
        $origination->method('build')->willReturn([
            'objective' => 'Reduce cyclomatic complexity in app/Hub.php',
            'payload' => ['allowed_files' => ['app/Hub.php']],
            'acceptance_hash' => 'abc123',
            'target_path' => 'app/Hub.php',
            'shape' => 'refactor',
            'self_contained' => true,
        ]);

        $producer = new AtlasLoopObjectiveProducer(
            stateReader: $this->fakeStateReader(),
            analyzer: $this->fakeAnalyzer(),
            origination: $origination,
        );

        $result = $producer->produce(
            $repoRoot,
            ['app/Hub.php', 'app/CallerA.php', 'app/CallerB.php'],
            '',
            '',
        );

        $this->assertNotNull($result, 'produce() should return a single-file objective');
        $this->assertSame('refactor', $result['shape'], 'flag OFF ⇒ single-file refactor');
        $this->assertNotSame('multi_file_refactor', $result['shape']);
        $this->assertArrayNotHasKey('multi_file', $result['payload'] ?? []);
    }

    // ───── helpers ─────

    /** Invoke the private buildMultiFileObjective via reflection. */
    private function invokeBuildMultiFile(array $winner, array $callerPaths, array $verdict, ?array $evPick): array
    {
        $m = new ReflectionMethod(AtlasLoopObjectiveProducer::class, 'buildMultiFileObjective');
        $m->setAccessible(true);

        return $m->invoke(new AtlasLoopObjectiveProducer, $winner, $callerPaths, $verdict, $evPick);
    }

    /** Create a temp repo with files that have real callers via FQCN references. */
    private function tempRepo(): string
    {
        $root = sys_get_temp_dir().'/atlas-mfo-'.bin2hex(random_bytes(4));
        $this->tempDirs[] = $root;

        // app/ dir
        mkdir($root.'/app', 0777, true);
        mkdir($root.'/app/Callers', 0777, true);
        mkdir($root.'/tests/Unit', 0777, true);

        // Hub — a wired, complex class that its callers reference via FQCN.
        // The cyclomatic is high enough to pass the refactor floor.
        file_put_contents($root.'/app/Hub.php', "<?php\nnamespace App;\n\nfinal class Hub\n{\n    public function process(int \$n, string \$mode): int\n    {\n        \$result = 0;\n        foreach (range(0, \$n) as \$i) {\n            switch (\$mode) {\n                case 'a': \$result += \$i * 2; break;\n                case 'b': \$result += \$i * 3; break;\n                case 'c': \$result += \$i * 4; break;\n                case 'd': \$result += \$i * 5; break;\n                case 'e': \$result += \$i * 6; break;\n                default: \$result += \$i; break;\n            }\n        }\n        return \$result;\n    }\n}\n");

        // CallerA — explicitly uses Hub via FQCN
        file_put_contents($root.'/app/CallerA.php', "<?php\nnamespace App\\Callers;\n\nuse App\\Hub;\n\nfinal class CallerA\n{\n    public function run(Hub \$h): int\n    {\n        return \$h->process(10, 'a');\n    }\n}\n");

        // CallerB — explicitly uses Hub via FQCN
        file_put_contents($root.'/app/CallerB.php', "<?php\nnamespace App\\Callers;\n\nuse App\\Hub;\n\nfinal class CallerB\n{\n    public function run(Hub \$h): int\n    {\n        return \$h->process(5, 'b');\n    }\n}\n");

        // Sibling test for Hub (enables verifiable flag)
        file_put_contents($root.'/tests/Unit/HubTest.php', "<?php\nnamespace Tests\\Unit;\n\nuse App\\Hub;\n\nfinal class HubTest\n{\n    public function test_process(): void\n    {\n        \$h = new Hub();\n        \$this->assertSame(0, \$h->process(0, ''));\n    }\n}\n");

        return $root;
    }

    /** A fake StateOfAtlas reader that returns a permissive state (nothing forbidden). */
    private function fakeStateReader(): AtlasLoopStateOfAtlasReader
    {
        // We cannot easily mock the reader because it uses `app()` internally.
        // Instead, inject a stub via a custom reader that returns a clean StateOfAtlas.
        $mock = $this->createMock(AtlasLoopStateOfAtlasReader::class);
        $mock->method('read')->willReturn(new StateOfAtlas(
            strategic: [['text' => 'improve core', 'keywords' => ['Hub', 'process']]],
            areaMaturity: ['app' => 0.5],
            realityProvenPaths: [],
            forbiddenPrefixes: [],
            elapsedMs: 0,
            deliveredCapabilities: [],
        ));

        return $mock;
    }

    /** A fake analyzer that returns high cyclomatic for our temp Hub file. */
    private function fakeAnalyzer(): AtlasLoopSignalAnalyzer
    {
        $mock = $this->createMock(AtlasLoopSignalAnalyzer::class);
        $mock->method('fileComplexity')->willReturn([
            'measured' => true,
            'max_per_method' => 18,
            'total' => 22,
        ]);

        return $mock;
    }

    /** Recursive rmdir helper. */
    private function rmdir(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir((string) $f) : @unlink((string) $f);
        }
        @rmdir($path);
    }
}
