<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Kernel;

use App\Services\Ai\Kernel\Architecture\KernelShadowRunRecorder;
use Tests\TestCase;

/**
 * Gap1.F3 — shadow run recorder contract tests.
 *
 *   - Disabled by default (flag off) → record() returns null, no I/O.
 *   - Enabled → emits canonical envelope `atlas.ai.kernel_shadow_run.v1`.
 *   - Envelope is provider-safe (hashes only, never raw trace/mission IDs).
 *   - Write failure does not bubble — recorder catches and logs.
 *
 * The recorder NEVER invokes the Kernel. It only observes the envelope
 * built by AiGatewayMissionBridge (Gap1.F2) and the legacy outcome from
 * AtlasProgrammingOrchestrator. Cutover (Gap1.F4) reads the recorded
 * deltas to gate the flag transition.
 */
class KernelShadowRunRecorderTest extends TestCase
{
    private string $sandbox;

    private KernelShadowRunRecorder $recorder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sandbox = sys_get_temp_dir().'/atlas-shadow-run-'.bin2hex(random_bytes(4));
        mkdir($this->sandbox, 0775, true);
        $this->recorder = new KernelShadowRunRecorder($this->sandbox);
    }

    protected function tearDown(): void
    {
        $this->purge($this->sandbox);
        parent::tearDown();
    }

    public function test_disabled_by_default_returns_null(): void
    {
        config()->set('atlas_ai.kernel_shadow.enabled', false);

        $result = $this->recorder->record(
            'trace-fixture',
            ['kernel_routed' => true, 'mission_id' => 'm-1'],
            ['status' => 'succeeded'],
        );

        $this->assertNull($result);
        $this->assertFalse($this->recorder->enabled());
    }

    public function test_disabled_writes_no_files(): void
    {
        config()->set('atlas_ai.kernel_shadow.enabled', false);

        $this->recorder->record('trace-x', [], ['status' => 'succeeded']);

        $files = $this->listFilesRecursive($this->sandbox);
        $this->assertSame([], $files, 'no files should be written when disabled');
    }

    public function test_enabled_emits_canonical_envelope(): void
    {
        config()->set('atlas_ai.kernel_shadow.enabled', true);

        $envelope = $this->recorder->record(
            'trace-abc',
            [
                'kernel_routed' => true,
                'mission_id' => 'm-uuid',
                'mission_type' => 'task',
            ],
            ['status' => 'succeeded', 'latency_ms' => 240],
        );

        $this->assertIsArray($envelope);
        $this->assertSame('atlas.ai.kernel_shadow_run.v1', $envelope['schema_version']);
        $this->assertTrue($envelope['kernel']['routed']);
        $this->assertSame('task', $envelope['kernel']['mission_type']);
        $this->assertSame('succeeded', $envelope['legacy']['status']);
        $this->assertSame(240, $envelope['legacy']['latency_ms']);
    }

    public function test_envelope_is_provider_safe_uses_hashes_only(): void
    {
        config()->set('atlas_ai.kernel_shadow.enabled', true);

        $envelope = $this->recorder->record(
            'sensitive-trace-id-12345',
            ['kernel_routed' => true, 'mission_id' => 'sensitive-mission-uuid'],
            ['status' => 'succeeded'],
        );

        $this->assertSame(64, strlen($envelope['trace_id_hash']), 'trace_id_hash is sha256');
        $this->assertSame(64, strlen($envelope['kernel']['mission_id_hash']));
        $this->assertSame(
            hash('sha256', 'sensitive-trace-id-12345'),
            $envelope['trace_id_hash'],
        );
        // Raw trace/mission must NOT appear anywhere.
        $serialized = json_encode($envelope);
        $this->assertStringNotContainsString('sensitive-trace-id-12345', $serialized);
        $this->assertStringNotContainsString('sensitive-mission-uuid', $serialized);
    }

    public function test_delta_block_classifies_outcomes(): void
    {
        config()->set('atlas_ai.kernel_shadow.enabled', true);

        $bothOk = $this->recorder->record('t1',
            ['kernel_routed' => true],
            ['status' => 'succeeded'],
        );
        $this->assertTrue($bothOk['delta']['both_succeeded']);
        $this->assertFalse($bothOk['delta']['both_failed']);

        $kernelOkLegacyFail = $this->recorder->record('t2',
            ['kernel_routed' => true],
            ['status' => 'failed'],
        );
        $this->assertTrue($kernelOkLegacyFail['delta']['kernel_succeeded_but_legacy_failed']);

        $kernelFailLegacyOk = $this->recorder->record('t3',
            ['kernel_routed' => false],
            ['status' => 'succeeded'],
        );
        $this->assertTrue($kernelFailLegacyOk['delta']['kernel_failed_but_legacy_succeeded']);
    }

    public function test_write_creates_dated_directory_and_hashed_filename(): void
    {
        config()->set('atlas_ai.kernel_shadow.enabled', true);

        $this->recorder->record('trace-1', ['kernel_routed' => true], ['status' => 'succeeded']);

        $files = $this->listFilesRecursive($this->sandbox);
        $this->assertCount(1, $files);

        $rel = ltrim(substr($files[0], strlen($this->sandbox)), '/');
        // Format: YYYY-MM-DD/<sha256>.json
        $this->assertMatchesRegularExpression(
            '#^\d{4}-\d{2}-\d{2}/[a-f0-9]{64}\.json$#',
            $rel,
        );
    }

    public function test_bridge_error_envelope_is_marked_present(): void
    {
        config()->set('atlas_ai.kernel_shadow.enabled', true);

        $envelope = $this->recorder->record('t',
            ['kernel_routed' => false, 'kernel_bridge_error' => ['reason' => 'x']],
            ['status' => 'succeeded'],
        );

        $this->assertSame('present', $envelope['kernel']['bridge_error']);
    }

    public function test_envelope_shape_is_stable(): void
    {
        config()->set('atlas_ai.kernel_shadow.enabled', true);

        $envelope = $this->recorder->record('t', [], ['status' => 'unknown']);

        $this->assertSame([
            'schema_version',
            'recorded_at',
            'trace_id_hash',
            'kernel',
            'legacy',
            'delta',
        ], array_keys($envelope));
    }

    /**
     * @return list<string>
     */
    private function listFilesRecursive(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $item) {
            if ($item->isFile()) {
                $files[] = $item->getPathname();
            }
        }

        return $files;
    }

    private function purge(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        $items = @scandir($path);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path.'/'.$item;
            if (is_dir($full)) {
                $this->purge($full);
                @rmdir($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($path);
    }
}
