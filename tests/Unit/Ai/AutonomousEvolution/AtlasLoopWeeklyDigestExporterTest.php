<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\WeeklyDigest\AtlasLoopWeeklyDigestExporter;
use Tests\TestCase;

class AtlasLoopWeeklyDigestExporterTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-weekly-export-'.bin2hex(random_bytes(6));
        @mkdir($this->root, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->root);
        parent::tearDown();
    }

    private function snapshot(array $override = []): array
    {
        return array_replace([
            'schema' => 'atlas.loop.weekly_digest.v1',
            'window_from' => '2026-06-20T00:00:00Z',
            'window_to' => '2026-06-27T00:00:00Z',
            'rows' => [
                ['ledger_source' => 'certification', 'event_kind' => 'certified', 'occurred_at' => '2026-06-21T10:00:00Z', 'payload_digest' => 'abc'],
                ['ledger_source' => 'decision_receipt', 'event_kind' => 'decided', 'occurred_at' => '2026-06-22T11:00:00Z', 'payload_digest' => 'def'],
            ],
            'snapshot_hash' => 'weekly_test_hash',
            'master_switch_off' => false,
        ], $override);
    }

    public function test_export_writes_markdown_with_snapshot_digest_header(): void
    {
        $exporter = new AtlasLoopWeeklyDigestExporter($this->root);
        $verdict = $exporter->export($this->snapshot(), '2026-W26');

        self::assertTrue($verdict['written']);
        self::assertFileExists($verdict['path']);
        $bytes = (string) file_get_contents($verdict['path']);
        self::assertStringContainsString('snapshot_digest: weekly_test_hash', $bytes);
        self::assertStringContainsString('| occurred_at | event_kind | payload_digest |', $bytes);
        self::assertStringContainsString('certified', $bytes);
    }

    public function test_two_exports_of_same_snapshot_are_byte_identical(): void
    {
        $exporter = new AtlasLoopWeeklyDigestExporter($this->root);
        $exporter->export($this->snapshot(), '2026-W26');
        $bytesA = (string) file_get_contents($this->root.'/2026-W26.md');

        // Wipe and re-export.
        @unlink($this->root.'/2026-W26.md');
        $exporter->export($this->snapshot(), '2026-W26');
        $bytesB = (string) file_get_contents($this->root.'/2026-W26.md');

        self::assertSame(hash('sha256', $bytesA), hash('sha256', $bytesB));
    }

    public function test_master_switch_off_refuses_to_write(): void
    {
        $exporter = new AtlasLoopWeeklyDigestExporter($this->root);
        $verdict = $exporter->export($this->snapshot(['master_switch_off' => true]), '2026-W26');

        self::assertFalse($verdict['written']);
        self::assertSame('master_switch_off', $verdict['reason']);
        self::assertFileDoesNotExist($this->root.'/2026-W26.md');
    }

    public function test_markdown_contains_zero_provider_internal_fields(): void
    {
        $exporter = new AtlasLoopWeeklyDigestExporter($this->root);
        $verdict = $exporter->export($this->snapshot(), '2026-W26');
        $markdown = (string) $verdict['markdown'];

        foreach (['provider:', 'prompt:', 'trace_id'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $markdown, "markdown must not contain {$forbidden}");
        }
    }

    public function test_empty_snapshot_renders_no_facts_in_window_marker(): void
    {
        $exporter = new AtlasLoopWeeklyDigestExporter($this->root);
        $verdict = $exporter->export($this->snapshot(['rows' => []]), '2026-W26');

        self::assertStringContainsString('no facts in window', (string) $verdict['markdown']);
    }
}
