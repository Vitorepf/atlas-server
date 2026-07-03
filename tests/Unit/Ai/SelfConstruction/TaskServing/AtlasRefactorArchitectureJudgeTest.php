<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskServing;

use App\Services\Ai\SelfConstruction\TaskServing\AtlasRefactorArchitectureJudge;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Freezes the architecture judge contract: a JSON verdict (even wrapped in
 * prose) parses into the judgment envelope; garbage output and an empty diff
 * fail OPEN (unavailable/no_diff), never an exception — the judge is advisory
 * by contract and can never wedge a report.
 */
final class AtlasRefactorArchitectureJudgeTest extends TestCase
{
    private string $repo = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = sys_get_temp_dir().'/atlas-judge-'.bin2hex(random_bytes(5));
        @mkdir($this->repo, 0775, true);
        $this->git(['init', '-q']);
        $this->git(['config', 'user.email', 't@atlas.local']);
        $this->git(['config', 'user.name', 'T']);
        file_put_contents($this->repo.'/a.php', "<?php\nfunction dup() { return 1; }\n");
        $this->git(['add', '-A']);
        $this->git(['commit', '-q', '-m', 'seed']);
        // Worker delivery in-tree (the diff the judge reads).
        file_put_contents($this->repo.'/a.php', "<?php\nfunction seam() { return 1; }\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->repo.'/a.php');
        @exec('rm -rf '.escapeshellarg($this->repo.'/.git'));
        @rmdir($this->repo);
        parent::tearDown();
    }

    public function test_json_verdict_wrapped_in_prose_parses_into_the_envelope(): void
    {
        $judge = new AtlasRefactorArchitectureJudge(
            runner: fn (string $prompt): string => "Claro! Aqui está:\n{\"improves_architecture\": true, \"score_0_10\": 8.5, \"dimensions\": {\"clareza\": 1}, \"reasons\": [\"costura única\"], \"concerns\": []}\nEspero ter ajudado.",
            repoRootOverride: $this->repo,
        );

        $out = $judge->judge(['problem' => 'dup'], ['delta' => ['loc' => -3]], ['a.php']);

        $this->assertSame('judged', $out['status'], json_encode($out));
        $this->assertTrue($out['improves_architecture']);
        $this->assertSame(8.5, $out['score_0_10']);
        $this->assertSame(['costura única'], $out['reasons']);
    }

    public function test_garbage_output_fails_open_as_unparseable(): void
    {
        $judge = new AtlasRefactorArchitectureJudge(
            runner: fn (string $prompt): string => 'segfault lol',
            repoRootOverride: $this->repo,
        );

        $out = $judge->judge([], ['delta' => []], ['a.php']);

        $this->assertSame('unparseable', $out['status']);
    }

    public function test_empty_diff_short_circuits_without_calling_the_model(): void
    {
        $called = false;
        $judge = new AtlasRefactorArchitectureJudge(
            runner: function (string $prompt) use (&$called): string {
                $called = true;

                return '{}';
            },
            repoRootOverride: $this->repo,
        );

        $out = $judge->judge([], [], ['missing.php']);

        $this->assertSame('no_diff', $out['status']);
        $this->assertFalse($called);
    }

    private function git(array $args): void
    {
        (new Process(array_merge(['git'], $args), $this->repo))->run();
    }
}
