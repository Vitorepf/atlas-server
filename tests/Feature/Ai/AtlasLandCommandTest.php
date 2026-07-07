<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\Brain\AtlasEvolutionDiary;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * L1 (Obra #19) — `atlas:land` commits ONLY its scope and labels the act in the
 * Diary in the same run. Runs in a THROWAWAY git repo (never the real one).
 */
final class AtlasLandCommandTest extends TestCase
{
    private string $repo = '';

    private string $diary = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = sys_get_temp_dir().'/atlas-land-'.bin2hex(random_bytes(5));
        $this->diary = sys_get_temp_dir().'/atlas-land-diary-'.bin2hex(random_bytes(5)).'.jsonl';
        @mkdir($this->repo, 0775, true);
        $this->git(['init', '-q']);
        $this->git(['config', 'user.email', 'test@atlas.local']);
        $this->git(['config', 'user.name', 'Atlas Test']);
        @file_put_contents($this->repo.'/README.md', "seed\n");
        $this->git(['add', 'README.md']);
        $this->git(['commit', '-q', '-m', 'seed']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->repo);
        @unlink($this->diary);
        parent::tearDown();
    }

    public function test_lands_only_the_scope_and_records_a_diary_entry(): void
    {
        $this->writeFile('app/Mine.php', "<?php // mine\n");
        $this->writeFile('app/Neighbour.php', "<?php // NOT mine\n");

        $this->artisan('atlas:land', [
            'paths' => ['app/Mine.php'],
            '--m' => 'L1: land only my file',
            '--why' => 'because the scope is mine',
            '--evidence' => 'phpunit',
            '--repo' => $this->repo,
            '--diary' => $this->diary,
        ])->assertExitCode(0);

        $committed = trim($this->git(['show', '--name-only', '--pretty=format:', 'HEAD'])['out']);
        $this->assertStringContainsString('app/Mine.php', $committed);
        $this->assertStringNotContainsString('app/Neighbour.php', $committed, 'the neighbour file is NEVER swept in');

        $dirty = trim($this->git(['status', '--porcelain', '--untracked-files=all'])['out']);
        $this->assertStringContainsString('app/Neighbour.php', $dirty);

        $entries = (new AtlasEvolutionDiary($this->diary))->all();
        $this->assertCount(1, $entries);
        $this->assertSame('merge', $entries[0]['tipo']);
        $this->assertSame('L1: land only my file', $entries[0]['o_que']);
        // id_reversao is the commit sha — the operator can `git revert` it (Carta Regra 4).
        $head = trim($this->git(['rev-parse', 'HEAD'])['out']);
        $this->assertSame($head, $entries[0]['id_reversao']);
    }

    public function test_fail_closed_nonzero_when_nothing_in_scope_changed(): void
    {
        $this->artisan('atlas:land', [
            'paths' => ['app/Nonexistent.php'],
            '--m' => 'nothing here',
            '--repo' => $this->repo,
            '--diary' => $this->diary,
        ])->assertExitCode(1);

        // No commit, no diary entry.
        $this->assertSame('seed', trim($this->git(['log', '-1', '--pretty=%s'])['out']));
        $this->assertSame([], (new AtlasEvolutionDiary($this->diary))->all());
    }

    private function writeFile(string $rel, string $content): void
    {
        $path = $this->repo.'/'.$rel;
        @mkdir(\dirname($path), 0775, true);
        @file_put_contents($path, $content);
    }

    /** @param list<string> $args @return array{code:int,out:string,err:string} */
    private function git(array $args): array
    {
        $p = new Process(array_merge(['git'], $args), $this->repo);
        $p->run();

        return ['code' => (int) $p->getExitCode(), 'out' => $p->getOutput(), 'err' => $p->getErrorOutput()];
    }
}
