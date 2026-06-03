<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasEvolutionTaskGenerator;
use App\Services\Ai\AutonomousEvolution\LoopExecutionDriver;
use Closure;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class AtlasEvolutionTaskGeneratorTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir().'/atlas-taskgen-'.bin2hex(random_bytes(4));
        mkdir($this->base.'/src', 0o755, true);
        mkdir($this->base.'/tests', 0o755, true);
        file_put_contents($this->base.'/composer.json', '{}'.PHP_EOL);
        // a real target with a genuine gap: val() returns 1 (an "improvement" makes it 2)
        file_put_contents($this->base.'/src/Subject.php', "<?php\nfunction subject_val(){ return 1; }\n");
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->base]))->run();
        parent::tearDown();
    }

    private function driverThatWrites(Closure $writer): LoopExecutionDriver
    {
        return new class($writer) implements LoopExecutionDriver
        {
            public function __construct(private Closure $writer) {}

            public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
            {
                ($this->writer)($workspace);

                return ['status' => 'completed'];
            }
        };
    }

    public function test_generates_a_task_when_the_provider_writes_a_genuinely_red_test(): void
    {
        $fake = $this->driverThatWrites(function (string $ws): void {
            file_put_contents($ws.'/tests/atlas_generated_0.php', "<?php\nrequire __DIR__.'/../src/Subject.php';\nif (subject_val() !== 2) { fwrite(STDERR,'red'); exit(1);} echo 'green';\n");
            file_put_contents($ws.'/GENERATED_OBJECTIVE_0.txt', "Make subject_val() return 2.\n");
        });

        $r = (new AtlasEvolutionTaskGenerator($fake))->generateForTarget($this->base, 'src/Subject.php', ['provider' => 'test', 'index' => 0]);

        $this->assertTrue($r['generated'], json_encode($r));
        $this->assertSame('verified_red_task', $r['reason']);
        $this->assertStringContainsString('subject_val', $r['task']['objective']);
        $this->assertSame(['src/Subject.php'], $r['task']['allowed_files']);
        $this->assertSame(['php tests/atlas_generated_0.php'], $r['task']['acceptance']['commands']);
        $this->assertContains('tests/**', $r['task']['acceptance']['frozen_globs']);
    }

    public function test_rejects_a_task_whose_generated_test_is_already_green(): void
    {
        // the provider "found" something already true -> no real work
        $fake = $this->driverThatWrites(function (string $ws): void {
            file_put_contents($ws.'/tests/atlas_generated_0.php', "<?php\nrequire __DIR__.'/../src/Subject.php';\nif (subject_val() !== 1) { exit(1);} echo 'green';\n");
            file_put_contents($ws.'/GENERATED_OBJECTIVE_0.txt', "Keep subject_val at 1.\n");
        });

        $r = (new AtlasEvolutionTaskGenerator($fake))->generateForTarget($this->base, 'src/Subject.php', ['provider' => 'test', 'index' => 0]);

        $this->assertFalse($r['generated']);
        $this->assertStringContainsString('not_red', $r['reason']);
    }

    public function test_rejects_when_the_provider_emits_nothing(): void
    {
        $fake = $this->driverThatWrites(function (string $ws): void {
            // provider writes nothing
        });

        $r = (new AtlasEvolutionTaskGenerator($fake))->generateForTarget($this->base, 'src/Subject.php', ['provider' => 'test', 'index' => 0]);

        $this->assertFalse($r['generated']);
        $this->assertStringContainsString('did_not_emit', $r['reason']);
    }
}
