<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasEvolutionFrozenJudge;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * ARBOR-GRAFT MG1 — the required_outputs POSITIVE merge guard (artifact-must-exist), conjunctive with the
 * existing negative guards, sourced from the frozen acceptance, byte-identical when the key is absent.
 */
final class AtlasEvolutionFrozenJudgeRequiredOutputsTest extends TestCase
{
    private string $ws;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ws = sys_get_temp_dir().'/atlas-judge-mg1-'.bin2hex(random_bytes(4));
        mkdir($this->ws.'/src', 0o755, true);
        mkdir($this->ws.'/tests', 0o755, true);
        file_put_contents($this->ws.'/src/Subject.php', "<?php\nfunction greet(){ return 'helo'; }\n");
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

    private function fixSubject(): void
    {
        file_put_contents($this->ws.'/src/Subject.php', "<?php\nfunction greet(){ return 'hello'; }\n");
    }

    private function acceptance(array $extra = []): array
    {
        return array_merge([
            'commands' => ['php tests/subject_test.php'],
            'allowed_globs' => ['src/**', 'dist/**'],
            'frozen_globs' => ['tests/**'],
            'metric_kind' => AtlasEvolutionFrozenJudge::METRIC_GATE,
        ], $extra);
    }

    public function test_absent_required_outputs_is_byte_identical(): void
    {
        $this->fixSubject();
        $v = (new AtlasEvolutionFrozenJudge)->score($this->ws, $this->acceptance());
        $this->assertTrue($v['passed'], json_encode($v['details']));
    }

    public function test_missing_required_output_is_rejected(): void
    {
        $this->fixSubject(); // otherwise valid candidate
        $v = (new AtlasEvolutionFrozenJudge)->score($this->ws, $this->acceptance([
            'required_outputs' => ['dist/bundle.js'],
        ]));

        $this->assertFalse($v['passed']);
        $this->assertSame(0.0, $v['metric']);
        $this->assertSame('missing_required_output', $v['details']['reason']);
        $this->assertSame(['dist/bundle.js'], $v['details']['missing_required_outputs']);
    }

    public function test_present_required_output_passes_the_guard(): void
    {
        $this->fixSubject();
        mkdir($this->ws.'/dist', 0o755, true);
        file_put_contents($this->ws.'/dist/bundle.js', "console.log('ok');\n");

        $v = (new AtlasEvolutionFrozenJudge)->score($this->ws, $this->acceptance([
            'required_outputs' => ['dist/bundle.js'],
        ]));

        $this->assertTrue($v['passed'], json_encode($v['details']));
    }

    public function test_tamper_takes_precedence_over_missing_required_output(): void
    {
        // Tamper the frozen test AND declare a (missing) required output: TAMPER must win (precedence).
        file_put_contents($this->ws.'/tests/subject_test.php', "<?php\necho 'green';\n");

        $v = (new AtlasEvolutionFrozenJudge)->score($this->ws, $this->acceptance([
            'required_outputs' => ['dist/bundle.js'],
        ]));

        $this->assertFalse($v['passed']);
        $this->assertSame('frozen_path_tampered', $v['details']['reason']);
    }
}
