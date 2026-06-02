<?php

namespace Tests\Unit\Ai\Hermes;

use App\Services\Ai\Hermes\HermesLearningHomeLinker;
use Illuminate\Filesystem\Filesystem;
use Tests\TestCase;

class HermesLearningHomeLinkerTest extends TestCase
{
    private string $operatorHome;

    private string $managedHome;

    protected function setUp(): void
    {
        parent::setUp();

        $base = sys_get_temp_dir().'/hermes-learning-link-'.uniqid('', true);
        $this->operatorHome = $base.'/operator';
        $this->managedHome = $base.'/managed';

        @mkdir($this->operatorHome, 0700, true);
        @mkdir($this->managedHome, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->operatorHome, $this->managedHome] as $dir) {
            $parent = dirname($dir);
            if (is_dir($parent)) {
                $this->removeTree($parent);
            }
        }

        parent::tearDown();
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            // is_link first: never recurse THROUGH a symlink into the operator's target.
            if (is_link($path) || is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                $this->removeTree($path);
            }
        }

        @rmdir($dir);
    }

    private function linker(): HermesLearningHomeLinker
    {
        return new HermesLearningHomeLinker(new Filesystem);
    }

    private function seedOperator(string $name, string $contents = 'x'): string
    {
        $path = $this->operatorHome.'/'.$name;
        file_put_contents($path, $contents);

        return $path;
    }

    public function test_links_present_learning_assets_as_symlinks_to_operator_targets(): void
    {
        $this->seedOperator('state.db', 'db');
        $this->seedOperator('state.db-wal', 'wal');
        $this->seedOperator('state.db-shm', 'shm');
        $this->seedOperator('.env', 'SECRET=1');
        $this->seedOperator('MEMORY.md', '# memory');
        @mkdir($this->operatorHome.'/skills', 0700, true);
        file_put_contents($this->operatorHome.'/skills/a.txt', 'skill');
        @mkdir($this->operatorHome.'/mcp-tokens', 0700, true);

        $result = $this->linker()->linkLearningState($this->managedHome, $this->operatorHome);

        $this->assertSame($this->operatorHome, $result['operator_home']);
        foreach (['state.db', 'state.db-wal', 'state.db-shm', 'skills', 'mcp-tokens', '.env', 'MEMORY.md'] as $asset) {
            $this->assertContains($asset, $result['linked'], "expected $asset linked");
            $link = $this->managedHome.'/'.$asset;
            $this->assertTrue(is_link($link), "$asset should be a symlink");
            $this->assertSame(
                realpath($this->operatorHome.'/'.$asset),
                realpath($link),
                "$asset should resolve to the operator target"
            );
        }
    }

    public function test_absent_assets_are_skipped_without_error(): void
    {
        $this->seedOperator('state.db', 'db');

        $result = $this->linker()->linkLearningState($this->managedHome, $this->operatorHome);

        $this->assertContains('state.db', $result['linked']);
        $this->assertContains('USER.md', $result['skipped']);
        $this->assertContains('skill-bundles', $result['skipped']);
        $this->assertFalse(file_exists($this->managedHome.'/USER.md'));
        $this->assertFalse(is_link($this->managedHome.'/USER.md'));
    }

    public function test_null_or_missing_operator_home_is_empty_no_op(): void
    {
        $missing = $this->operatorHome.'/does-not-exist';

        $result = $this->linker()->linkLearningState($this->managedHome, $missing);

        $this->assertSame(['operator_home' => null, 'linked' => [], 'skipped' => []], $result);
        // Nothing written into the managed home.
        $this->assertSame([], array_values(array_diff(scandir($this->managedHome) ?: [], ['.', '..'])));
    }

    public function test_idempotent_second_call_does_not_throw_or_duplicate(): void
    {
        $this->seedOperator('state.db', 'db');
        $this->seedOperator('.env', 'x');

        $first = $this->linker()->linkLearningState($this->managedHome, $this->operatorHome);
        $second = $this->linker()->linkLearningState($this->managedHome, $this->operatorHome);

        $this->assertSame(['state.db', '.env'], array_values(array_intersect(['state.db', '.env'], $first['linked'])));
        // Second run links nothing new; both are already present.
        $this->assertNotContains('state.db', $second['linked']);
        $this->assertNotContains('.env', $second['linked']);
        $this->assertContains('state.db', $second['skipped']);
        $this->assertContains('.env', $second['skipped']);

        // No duplication: still exactly one entry each.
        $entries = array_values(array_diff(scandir($this->managedHome) ?: [], ['.', '..']));
        $this->assertSame(1, count(array_filter($entries, fn ($e) => $e === 'state.db')));
        $this->assertSame(1, count(array_filter($entries, fn ($e) => $e === '.env')));
    }

    public function test_memory_config_extracts_block_with_last_non_empty_provider_winning(): void
    {
        $yaml = <<<'YAML'
        model: gpt-5.5
        memory:
          memory_enabled: true
          provider: legacy
          provider: supermemory
          memory_char_limit: 2200
        mcp_servers:
          foo:
            enabled: true
        YAML;
        file_put_contents($this->operatorHome.'/config.yaml', $yaml);

        $config = $this->linker()->memoryConfig($this->operatorHome);

        $this->assertSame(true, $config['memory_enabled']);
        $this->assertSame('supermemory', $config['provider']);
        $this->assertSame(2200, $config['memory_char_limit']);
    }

    public function test_memory_config_handles_quoted_values_with_inline_comments(): void
    {
        $yaml = <<<'YAML'
        memory:
          memory_enabled: "true"  # toggle
          provider: 'supermemory' # backend
          memory_char_limit: "2200" # cap
        model: gpt-5.5
        YAML;
        file_put_contents($this->operatorHome.'/config.yaml', $yaml);

        $config = $this->linker()->memoryConfig($this->operatorHome);

        $this->assertSame(true, $config['memory_enabled']);
        $this->assertSame('supermemory', $config['provider']);
        $this->assertSame(2200, $config['memory_char_limit']);
    }

    public function test_memory_config_empty_when_no_block_or_no_file(): void
    {
        // No config.yaml at all.
        $this->assertSame([], $this->linker()->memoryConfig($this->operatorHome));

        // config.yaml present but no memory block.
        file_put_contents($this->operatorHome.'/config.yaml', "model: gpt-5.5\nmcp_servers:\n  foo:\n    enabled: true\n");
        $this->assertSame([], $this->linker()->memoryConfig($this->operatorHome));
    }

    public function test_safety_helper_writes_or_deletes_nothing_in_operator_home(): void
    {
        $this->seedOperator('state.db', 'db');
        $this->seedOperator('.env', 'SECRET=1');
        file_put_contents($this->operatorHome.'/config.yaml', "memory:\n  memory_enabled: true\n  provider: supermemory\n");

        $before = $this->snapshot($this->operatorHome);

        $this->linker()->linkLearningState($this->managedHome, $this->operatorHome);
        $this->linker()->memoryConfig($this->operatorHome);

        $after = $this->snapshot($this->operatorHome);

        $this->assertSame($before, $after, 'operator home must be untouched');
    }

    /**
     * @return array<string,string>  name => sha of contents
     */
    private function snapshot(string $dir): array
    {
        $out = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            $out[$entry] = is_file($path) ? hash('sha256', (string) file_get_contents($path)) : 'dir';
        }
        ksort($out);

        return $out;
    }
}
