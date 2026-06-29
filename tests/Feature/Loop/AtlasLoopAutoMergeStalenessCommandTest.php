<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Merge\AtlasLoopAutoMergeStalenessRefuser;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Proves the auto-merge staleness refuser is live at the operator surface: a base within the commits-behind
 * ceiling is allowed; a base past the ceiling is refused stale_base; an unresolvable ref fails closed.
 */
final class AtlasLoopAutoMergeStalenessCommandTest extends TestCase
{
    private string $repo = '';

    private string $baseSha = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = sys_get_temp_dir().'/atlas-staleness-'.bin2hex(random_bytes(5));
        @mkdir($this->repo, 0o755, true);
        $this->git('init -q');
        $this->git('config user.email t@t.t');
        $this->git('config user.name t');
        // c1 (base), then c2, c3 ⇒ base is 2 commits behind main.
        $this->commit('a.txt', 'c1');
        $this->git('branch -M main');
        $this->baseSha = $this->git('rev-parse HEAD');
        $this->commit('b.txt', 'c2');
        $this->commit('c.txt', 'c3');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->repo));
        parent::tearDown();
    }

    private function git(string $args): string
    {
        return (string) trim((string) shell_exec('cd '.escapeshellarg($this->repo).' && git '.$args.' 2>/dev/null'));
    }

    private function commit(string $file, string $msg): void
    {
        file_put_contents($this->repo.'/'.$file, $msg);
        $this->git('add -A');
        $this->git('commit -q -m '.escapeshellarg($msg));
    }

    private function check(string $base): array
    {
        $exit = Artisan::call('atlas:loop:auto-merge-staleness', ['--base' => $base, '--repo-root' => $this->repo, '--json' => true]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_base_within_ceiling_is_allowed(): void
    {
        Config::set('atlas.loop.automerge.staleness_max_commits_behind', 25);

        ['exit' => $exit, 'd' => $d] = $this->check($this->baseSha);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasLoopAutoMergeStalenessRefuser::SCHEMA, $d['schema']);
        $this->assertTrue($d['allow'], (string) json_encode($d));
        $this->assertSame(2, $d['commits_behind']);
        $this->assertNull($d['reason']);
    }

    public function test_base_past_ceiling_is_stale(): void
    {
        Config::set('atlas.loop.automerge.staleness_max_commits_behind', 1);

        ['d' => $d] = $this->check($this->baseSha);

        $this->assertFalse($d['allow'], (string) json_encode($d));
        $this->assertSame(2, $d['commits_behind']);
        $this->assertSame(AtlasLoopAutoMergeStalenessRefuser::REASON_STALE_BASE, $d['reason']);
    }

    public function test_unresolvable_base_fails_closed(): void
    {
        ['d' => $d] = $this->check('deadbeefdeadbeefdeadbeefdeadbeefdeadbeef');

        $this->assertFalse($d['allow']);
        $this->assertSame(AtlasLoopAutoMergeStalenessRefuser::REASON_UNRESOLVABLE, $d['reason']);
    }

    public function test_missing_base_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:auto-merge-staleness', ['--repo-root' => $this->repo, '--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
