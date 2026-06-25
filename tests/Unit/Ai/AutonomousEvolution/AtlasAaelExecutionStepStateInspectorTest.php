<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Aael\Execution\Debugger\AtlasAaelExecutionStepStateInspector;
use Tests\TestCase;

final class AtlasAaelExecutionStepStateInspectorTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-inspector-'.bin2hex(random_bytes(6));
        @mkdir($this->root, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->root.'/*') as $f) {
            @unlink((string) $f);
        }
        @rmdir($this->root);
        parent::tearDown();
    }

    private function step(): array
    {
        return [
            'kind' => 'write',
            'allowed_files' => ['b.txt', 'a.txt'],
            'inputs' => ['x' => 1, 'y' => [2, 3]],
        ];
    }

    private function env(): array
    {
        return ['cwd' => '/repo', 'git_head' => 'abc123', 'dirty' => false];
    }

    public function test_capture_pre_and_post_canonical_bytes_are_identical_across_runs(): void
    {
        $a = new AtlasAaelExecutionStepStateInspector();
        $b = new AtlasAaelExecutionStepStateInspector();

        $pre1 = $a->capturePre('R', 0, $this->step(), $this->env(), [], '2026-06-25T05:00:00Z');
        $pre2 = $b->capturePre('R', 0, $this->step(), $this->env(), [], '2026-06-25T05:99:00Z');
        $this->assertSame($pre1->canonicalBytes(), $pre2->canonicalBytes());

        $post1 = $a->capturePost('R', 0, $this->step(), $this->env(), [], ['lines_added' => 5, 'lines_removed' => 1, 'files_changed' => 2], ['ok' => true], '2026-06-25T05:00:00Z');
        $post2 = $b->capturePost('R', 0, $this->step(), $this->env(), [], ['lines_added' => 5, 'lines_removed' => 1, 'files_changed' => 2], ['ok' => true], '2026-06-25T05:99:00Z');
        $this->assertSame($post1->canonicalBytes(), $post2->canonicalBytes());
    }

    public function test_snapshot_body_keys_are_strictly_allowlisted_no_scores(): void
    {
        $inspector = new AtlasAaelExecutionStepStateInspector();
        $snap = $inspector->capturePre('R', 0, $this->step(), $this->env(), [], '2026-06-25T05:00:00Z');

        $keys = array_keys($snap->body);
        sort($keys);
        $expected = AtlasAaelExecutionStepStateInspector::ALLOWED_BODY_KEYS;
        sort($expected);
        $this->assertSame($expected, $keys);

        $bytes = $snap->canonicalBytes();
        foreach (['score', 'grade', 'judge', 'looks_good', 'rank', 'best', 'weight', 'severity', 'recommendation'] as $banned) {
            $this->assertStringNotContainsString($banned, $bytes);
        }
    }

    public function test_capture_does_not_write_under_app_path_and_does_not_call_llm(): void
    {
        // Fake Hermes binding — if anyone resolves it, the test fails-hard.
        app()->bind('atlas.hermes', function () {
            throw new \RuntimeException('inspector_must_not_call_llm');
        });

        $appDir = base_path('app/Services/Ai/AutonomousEvolution/Aael/Execution/Debugger');
        $listBefore = $this->snapshotDir($appDir);

        $inspector = new AtlasAaelExecutionStepStateInspector();
        $inspector->capturePre('R', 0, $this->step(), $this->env(), [], '2026-06-25T05:00:00Z');
        $inspector->capturePost('R', 0, $this->step(), $this->env(), [], ['lines_added' => 0, 'lines_removed' => 0, 'files_changed' => 0], null, '2026-06-25T05:00:00Z');

        $listAfter = $this->snapshotDir($appDir);
        $this->assertSame($listBefore, $listAfter, 'inspector must not mutate app_path()');
    }

    public function test_files_touched_per_file_sha256_matches_actual_bytes(): void
    {
        $f1 = $this->root.'/one.txt';
        $f2 = $this->root.'/two.txt';
        file_put_contents($f1, 'hello pre');
        file_put_contents($f2, 'world pre');

        $inspector = new AtlasAaelExecutionStepStateInspector();
        $pre = $inspector->capturePre('R', 0, $this->step(), $this->env(), [$f1, $f2], '2026-06-25T05:00:00Z');

        $byPath = [];
        foreach ($pre->body['files_touched'] as $r) {
            $byPath[$r['path']] = $r['sha256'];
        }
        $this->assertSame(hash_file('sha256', $f1), $byPath[$f1]);
        $this->assertSame(hash_file('sha256', $f2), $byPath[$f2]);

        file_put_contents($f1, 'hello POST mutated');
        $post = $inspector->capturePost('R', 0, $this->step(), $this->env(), [$f1, $f2], ['lines_added' => 1, 'lines_removed' => 1, 'files_changed' => 1], ['ok' => true], '2026-06-25T05:00:00Z');
        $byPathPost = [];
        foreach ($post->body['files_touched'] as $r) {
            $byPathPost[$r['path']] = $r['sha256'];
        }
        $this->assertSame(hash_file('sha256', $f1), $byPathPost[$f1]);
        $this->assertNotSame($byPath[$f1], $byPathPost[$f1]);
    }

    /** @return list<string> */
    private function snapshotDir(string $dir): array
    {
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        $entries = [];
        foreach ($rii as $f) {
            if ($f->isFile()) {
                $entries[] = $f->getPathname().':'.$f->getSize();
            }
        }
        sort($entries);

        return $entries;
    }
}
