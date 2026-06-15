<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasEvolutionFrozenJudge;
use App\Services\Ai\AutonomousEvolution\AtlasEvolutionScenarioExplorer;
use App\Services\Ai\AutonomousEvolution\LoopExecutionDriver;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The multi-file-refactor transport fix: a candidate that CREATES a new file (e.g. an extracted
 * class) must be captured as a VALID, applyable unified diff. The old hand-built /dev/null hunk was
 * malformed, so `git apply` at the cert gate dropped the new file => class-not-found => every
 * multi-file refactor rejected. This pins: diff_text round-trips through `git apply` into a fresh
 * baseline and the new file lands with its exact content + the tracked edit applies.
 */
final class AtlasLoopDiffTextUntrackedFileTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $d) {
            File::deleteDirectory($d);
        }
        parent::tearDown();
    }

    private function git(string $cwd, array $args): void
    {
        (new Process(array_merge(['git', '-C', $cwd], $args), null, [
            'GIT_AUTHOR_NAME' => 't', 'GIT_AUTHOR_EMAIL' => 't@t', 'GIT_COMMITTER_NAME' => 't', 'GIT_COMMITTER_EMAIL' => 't@t',
        ], null, 60.0))->run();
    }

    private function baselineRepo(string $fooBody): string
    {
        $d = sys_get_temp_dir().'/atlas-difftext-'.bin2hex(random_bytes(4));
        $this->dirs[] = $d;
        File::ensureDirectoryExists($d.'/app');
        File::put($d.'/app/Foo.php', $fooBody);
        $this->git($d, ['init', '-q']);
        $this->git($d, ['add', '-A']);
        $this->git($d, ['-c', 'commit.gpgsign=false', 'commit', '-q', '-m', 'baseline']);

        return $d;
    }

    public function test_diff_text_captures_a_new_file_as_an_applyable_patch(): void
    {
        $original = "<?php\nclass Foo { public function v(): int { return 1; } }\n";
        $ws = $this->baselineRepo($original);

        // Candidate: edit the tracked file to DELEGATE + CREATE a new untracked collaborator file.
        File::put($ws.'/app/Foo.php', "<?php\nclass Foo { public function v(): int { return (new FooSupport())->v(); } }\n");
        File::put($ws.'/app/FooSupport.php', "<?php\nclass FooSupport { public function v(): int { return 1; } }\n");

        $explorer = new AtlasEvolutionScenarioExplorer(
            new class implements LoopExecutionDriver
            {
                public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
                {
                    return ['status' => 'noop'];
                }
            },
            app(AtlasEvolutionFrozenJudge::class),
        );
        $ref = new \ReflectionMethod($explorer, 'diffText');
        $ref->setAccessible(true);
        $diff = (string) $ref->invoke($explorer, $ws);

        $this->assertStringContainsString('app/FooSupport.php', $diff, 'the new file is in the diff');
        $this->assertStringContainsString('new file mode', $diff, 'as a proper git creation hunk (not the old malformed /dev/null text)');

        // THE PROOF: the diff applies cleanly into a FRESH baseline and CREATES the new file.
        $fresh = $this->baselineRepo($original);
        $patch = $fresh.'/candidate.diff';
        File::put($patch, $diff);
        $apply = new Process(['git', '-C', $fresh, 'apply', '--whitespace=nowarn', $patch], null, null, null, 60.0);
        $apply->run();

        $this->assertTrue($apply->isSuccessful(), 'git apply succeeds: '.$apply->getErrorOutput());
        $this->assertFileExists($fresh.'/app/FooSupport.php', 'the extracted-class file was CREATED by applying the diff');
        $this->assertStringContainsString('class FooSupport', (string) File::get($fresh.'/app/FooSupport.php'));
        $this->assertStringContainsString('new FooSupport()', (string) File::get($fresh.'/app/Foo.php'), 'the tracked edit applied too');
    }
}
