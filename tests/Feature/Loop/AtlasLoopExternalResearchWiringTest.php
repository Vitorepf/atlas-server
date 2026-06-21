<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasEvolutionTaskGenerator;
use App\Services\Ai\AutonomousEvolution\AtlasLoopRedReasonGate;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopExternalResearchService;
use App\Services\Ai\AutonomousEvolution\LoopExecutionDriver;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * generator:external_research — the AtlasLoopExternalResearchService is wired as a THIRD advisory layer into
 * AtlasEvolutionTaskGenerator::advisoryContext(). A clean topic + a real repo_root (with a wired tool) reaches
 * ONLY the generation prompt as an "EXTERNAL RESEARCH (advisory ..." note — it NEVER gates a cert.
 *
 * Sovereignty is load-bearing: a research_topic carrying a REAL repo path fragment is egress-blocked, so NO
 * note reaches the prompt. And with no search tool the service fail-closes (no note).
 */
final class AtlasLoopExternalResearchWiringTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir().'/atlas-research-wire-'.bin2hex(random_bytes(4));
        mkdir($this->base.'/src', 0o755, true);
        mkdir($this->base.'/tests', 0o755, true);
        file_put_contents($this->base.'/composer.json', '{}'.PHP_EOL);
        file_put_contents($this->base.'/src/Subject.php', "<?php\nfunction subject_val(){ return 1; }\n");
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->base]))->run();
        parent::tearDown();
    }

    /**
     * A fake driver that CAPTURES the intent/prompt string passed to attempt() (and writes nothing — we only
     * care about the prompt the generator built, which is assembled BEFORE attempt() runs).
     *
     * @param  array{intent?: string}  $capture  populated by reference via the closure
     */
    private function capturingDriver(\Closure $sink): LoopExecutionDriver
    {
        return new class($sink) implements LoopExecutionDriver
        {
            public function __construct(private \Closure $sink) {}

            public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
            {
                ($this->sink)($intent);

                return ['status' => 'completed'];
            }
        };
    }

    public function test_case1_clean_topic_with_a_tool_reaches_the_generation_prompt(): void
    {
        $captured = '';
        $driver = $this->capturingDriver(function (string $intent) use (&$captured): void {
            $captured = $intent;
        });

        $gen = new AtlasEvolutionTaskGenerator(
            $driver,
            new AtlasLoopRedReasonGate,
            new AtlasLoopExternalResearchService(
                searchToolAvailable: true,
                backend: static fn (string $topic): string => "STATE-OF-THE-ART NOTE for: {$topic}",
            ),
        );

        $gen->generateForTarget($this->base, 'src/Subject.php', [
            'provider' => 'test',
            'index' => 0,
            'research_topic' => 'theory of constraints bottleneck analysis',
            'repo_root' => $this->base, // a clean topic: nothing under this root resolves the topic as a path
        ]);

        $this->assertStringContainsString('EXTERNAL RESEARCH (advisory', $captured);
        $this->assertStringContainsString('STATE-OF-THE-ART NOTE for: theory of constraints', $captured);
    }

    public function test_case2_a_real_repo_path_fragment_is_egress_blocked_no_note(): void
    {
        $captured = '';
        $driver = $this->capturingDriver(function (string $intent) use (&$captured): void {
            $captured = $intent;
        });

        $gen = new AtlasEvolutionTaskGenerator(
            $driver,
            new AtlasLoopRedReasonGate,
            // A working backend is wired, so this proves the egress filter BLOCKS even with a live tool —
            // not merely that a missing backend fail-closes.
            new AtlasLoopExternalResearchService(
                searchToolAvailable: true,
                backend: static fn (string $topic): string => "NOTE for: {$topic}",
            ),
        );

        // a REAL repo path fragment as the topic + the REAL repo root => the egress filter resolves it and BLOCKS.
        $realPathFragment = 'app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopExternalResearchService.php';
        $gen->generateForTarget($this->base, 'src/Subject.php', [
            'provider' => 'test',
            'index' => 0,
            'research_topic' => "how does $realPathFragment work",
            'repo_root' => base_path(),
        ]);

        $this->assertStringNotContainsString('EXTERNAL RESEARCH', $captured, 'a leaking topic must never reach the prompt');
    }

    public function test_case3_fail_closed_with_no_search_tool_no_note(): void
    {
        $captured = '';
        $driver = $this->capturingDriver(function (string $intent) use (&$captured): void {
            $captured = $intent;
        });

        // tool_available=false => the service fail-closes => researched=false => no note.
        $gen = new AtlasEvolutionTaskGenerator(
            $driver,
            new AtlasLoopRedReasonGate,
            new AtlasLoopExternalResearchService(searchToolAvailable: false),
        );

        $gen->generateForTarget($this->base, 'src/Subject.php', [
            'provider' => 'test',
            'index' => 0,
            'research_topic' => 'theory of constraints bottleneck analysis',
            'repo_root' => $this->base,
        ]);

        $this->assertStringNotContainsString('EXTERNAL RESEARCH', $captured, 'fail-closed: no tool ⇒ no note');
    }
}
