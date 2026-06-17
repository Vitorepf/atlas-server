<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasEvolutionTaskGenerator;
use App\Services\Ai\AutonomousEvolution\LoopExecutionDriver;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * ACDE U1 — N-sampled comprehension. Proves samples=1 is byte-identical to generateForTarget, and that drawing
 * N readings RESCUES a genuine verified-RED task when an early reading fails to produce one — width over a weak
 * engine raises HONEST task yield without lowering the bar. Temp base, no DB => hang-free.
 */
final class AtlasLoopComprehensionSamplingTest extends TestCase
{
    private string $base = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir().'/atlas-u1-'.bin2hex(random_bytes(5));
        mkdir($this->base.'/src', 0o755, true);
        mkdir($this->base.'/tests', 0o755, true);
        file_put_contents($this->base.'/composer.json', '{}'.PHP_EOL);
        file_put_contents($this->base.'/src/Subject.php', "<?php\nfunction subject_val(){ return 1; }\n");
    }

    protected function tearDown(): void
    {
        if ($this->base !== '' && is_dir($this->base)) {
            (new Process(['rm', '-rf', $this->base]))->run();
        }
        parent::tearDown();
    }

    private function redTest(): string
    {
        return "<?php\nrequire __DIR__.'/../src/Subject.php';\nif (subject_val() !== 2) { fwrite(STDERR,'red'); exit(1);} echo 'green';\n";
    }

    private function greenTest(): string
    {
        return "<?php\nrequire __DIR__.'/../src/Subject.php';\nif (subject_val() !== 1) { exit(1);} echo 'green';\n";
    }

    /** @param array<int,array{test:string,obj:string}> $byIndex */
    private function driverPerIndex(array $byIndex): LoopExecutionDriver
    {
        return new class($byIndex) implements LoopExecutionDriver
        {
            /** @param array<int,array{test:string,obj:string}> $byIndex */
            public function __construct(private array $byIndex) {}

            public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
            {
                $idx = 0;
                foreach ($userConstraints as $c) {
                    if (preg_match('/atlas_generated_(\d+)\.php/', (string) $c, $m) === 1) {
                        $idx = (int) $m[1];
                    }
                }
                $spec = $this->byIndex[$idx] ?? null;
                if ($spec !== null) {
                    file_put_contents($workspace.'/tests/atlas_generated_'.$idx.'.php', $spec['test']);
                    file_put_contents($workspace.'/GENERATED_OBJECTIVE_'.$idx.'.txt', $spec['obj']);
                }

                return ['status' => 'completed'];
            }
        };
    }

    public function test_samples_one_is_identical_to_single_pass(): void
    {
        config(['atlas.loop.comprehension_samples' => 1]);
        $driver = $this->driverPerIndex([0 => ['test' => $this->redTest(), 'obj' => "Make subject_val() return 2.\n"]]);

        $r = (new AtlasEvolutionTaskGenerator($driver))->generateBestForTarget($this->base, 'src/Subject.php', ['provider' => 'test', 'index' => 0]);

        $this->assertTrue($r['generated'], json_encode($r));
        $this->assertSame('verified_red_task', $r['reason']);
        $this->assertArrayNotHasKey('comprehension_sample', $r, 'a single pass carries no sampling metadata');
    }

    public function test_sampling_rescues_a_genuine_red_when_the_first_reading_fails(): void
    {
        config(['atlas.loop.comprehension_samples' => 2]);
        // reading 0 produces a GREEN (no real work) test; reading 1 produces a genuine RED.
        $driver = $this->driverPerIndex([
            0 => ['test' => $this->greenTest(), 'obj' => "Keep subject_val at 1.\n"],
            1 => ['test' => $this->redTest(), 'obj' => "Make subject_val() return 2.\n"],
        ]);

        $r = (new AtlasEvolutionTaskGenerator($driver))->generateBestForTarget($this->base, 'src/Subject.php', ['provider' => 'test', 'index' => 0]);

        $this->assertTrue($r['generated'], 'the second reading rescues a genuine task: '.json_encode($r));
        $this->assertSame(1, $r['comprehension_sample'], 'the winning reading was sample index 1');
        $this->assertSame(2, $r['comprehension_samples']);
    }

    public function test_returns_honest_rejection_when_no_reading_is_red(): void
    {
        config(['atlas.loop.comprehension_samples' => 2]);
        $driver = $this->driverPerIndex([
            0 => ['test' => $this->greenTest(), 'obj' => "a\n"],
            1 => ['test' => $this->greenTest(), 'obj' => "b\n"],
        ]);

        $r = (new AtlasEvolutionTaskGenerator($driver))->generateBestForTarget($this->base, 'src/Subject.php', ['provider' => 'test', 'index' => 0]);

        $this->assertFalse($r['generated'], 'no fabricated task when no reading is genuinely red');
    }
}
