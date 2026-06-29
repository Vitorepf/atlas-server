<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Proves the auto-merge pre-flight gate is live at the operator surface and emits deterministic facts: when
 * the base SHA equals the repo's head it ALLOWS, when it has moved it denies with 'main_moved'. A missing
 * --base is a usage error. Read-only — it never merges.
 */
final class AtlasLoopAutoMergePreflightCommandTest extends TestCase
{
    private ?string $repo = null;

    protected function tearDown(): void
    {
        if ($this->repo !== null && is_dir($this->repo)) {
            (new Process(['rm', '-rf', $this->repo]))->run();
        }
        parent::tearDown();
    }

    public function test_requires_base(): void
    {
        $exit = Artisan::call('atlas:loop:auto-merge-preflight', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertSame('usage_error', $decoded['status']);
    }

    public function test_base_equal_to_head_is_allowed(): void
    {
        $head = $this->makeRepo();
        $decoded = $this->check($head);

        $this->assertSame('atlas.loop.automerge_preflight.v1', $decoded['schema_version']);
        $this->assertTrue($decoded['allow']);
        $this->assertTrue($decoded['equal']);
        $this->assertSame($head, $decoded['head_sha']);
    }

    public function test_moved_main_is_denied(): void
    {
        $this->makeRepo();
        $decoded = $this->check('deadbeefdeadbeefdeadbeefdeadbeefdeadbeef');

        $this->assertFalse($decoded['allow']);
        $this->assertFalse($decoded['equal']);
        $this->assertSame('main_moved', $decoded['reason']);
    }

    private function makeRepo(): string
    {
        $this->repo = sys_get_temp_dir().'/preflight_'.bin2hex(random_bytes(6));
        mkdir($this->repo);
        $this->git(['init', '-q']);
        $this->git(['config', 'user.email', 't@example.test']);
        $this->git(['config', 'user.name', 'Tester']);
        file_put_contents($this->repo.'/a.txt', "hello\n");
        $this->git(['add', '-A']);
        $this->git(['commit', '-qm', 'init']);

        return trim((new Process(['git', '-C', $this->repo, 'rev-parse', 'HEAD']))->mustRun()->getOutput());
    }

    /**
     * @param  list<string>  $args
     */
    private function git(array $args): void
    {
        (new Process(array_merge(['git', '-C', (string) $this->repo], $args)))->mustRun();
    }

    /**
     * @return array<string,mixed>
     */
    private function check(string $base): array
    {
        $exit = Artisan::call('atlas:loop:auto-merge-preflight', [
            '--base' => $base,
            '--repo-root' => (string) $this->repo,
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);

        return json_decode(trim(Artisan::output()), true);
    }
}
