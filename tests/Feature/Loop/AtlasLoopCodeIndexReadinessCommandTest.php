<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the code-index readiness bridge is live at the operator surface and emits deterministic facts: a
 * clean facts set (drift passed, gate ready, index fresh & non-empty, readiness clean) is READY/passed; a
 * facts set missing schema-drift is BLOCKED. A missing --facts is a usage error.
 */
final class AtlasLoopCodeIndexReadinessCommandTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        parent::tearDown();
    }

    public function test_requires_facts(): void
    {
        $exit = Artisan::call('atlas:loop:code-index-readiness', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertSame('usage_error', $decoded['status']);
    }

    public function test_clean_facts_are_ready(): void
    {
        $decoded = $this->invoke([
            'code_status' => ['status' => 'ready', 'indexed_symbols' => 8700, 'is_stale' => false],
            'readiness' => ['status' => 'ready', 'blocking_findings' => []],
            'automatic_gate' => ['status' => 'ready'],
            'schema_drift' => ['passed' => true, 'status' => 'clean'],
        ]);

        $this->assertSame('atlas.self_construction.code_index_readiness_bridge.v1', $decoded['schema']);
        $this->assertSame('ready', $decoded['status']);
        $this->assertTrue($decoded['passed']);
        $this->assertSame([], $decoded['blockers']);
        $this->assertSame('ready', $decoded['code_index_facts']['code_status']);
    }

    public function test_missing_schema_drift_is_blocked(): void
    {
        $decoded = $this->invoke([
            'code_status' => ['status' => 'ready', 'indexed_symbols' => 8700, 'is_stale' => false],
            'readiness' => ['status' => 'ready', 'blocking_findings' => []],
            'automatic_gate' => ['status' => 'ready'],
            // schema_drift omitted ⇒ blocked
        ]);

        $this->assertSame('blocked', $decoded['status']);
        $this->assertFalse($decoded['passed']);
        $this->assertContains('schema_drift_facts_missing', $decoded['blockers']);
    }

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    private function invoke(array $facts): array
    {
        $path = tempnam(sys_get_temp_dir(), 'codeindex_').'.json';
        $this->files[] = $path;
        file_put_contents($path, json_encode($facts));

        $exit = Artisan::call('atlas:loop:code-index-readiness', ['--facts' => $path, '--json' => true]);
        $this->assertSame(0, $exit);

        return json_decode(trim(Artisan::output()), true);
    }
}
