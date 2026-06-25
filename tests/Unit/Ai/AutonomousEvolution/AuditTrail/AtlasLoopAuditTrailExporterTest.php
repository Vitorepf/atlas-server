<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\AuditTrail;

use App\Services\Ai\AutonomousEvolution\AuditTrail\AtlasLoopAuditTrailExporter;
use App\Services\Ai\AutonomousEvolution\AuditTrail\AuditEvent;
use App\Services\Ai\AutonomousEvolution\AuditTrail\ExportManifest;
use App\Services\Ai\AutonomousEvolution\AuditTrail\TimelineWindow;
use Tests\TestCase;

final class AtlasLoopAuditTrailExporterTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir().'/atlas-export-'.bin2hex(random_bytes(6));
        @mkdir($this->tmpDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            foreach ((array) scandir($this->tmpDir) as $f) {
                if ($f === '.' || $f === '..') {
                    continue;
                }
                @unlink($this->tmpDir.'/'.$f);
            }
            @rmdir($this->tmpDir);
        }
        parent::tearDown();
    }

    private function event(int $i): AuditEvent
    {
        return new AuditEvent(
            event_id: 'e-'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
            ts_utc: sprintf('2026-06-25T05:%02d:%02d+00:00', intdiv($i, 60) % 60, $i % 60),
            source_ledger: 'cycle_ledger',
            kind: 'phase_completed',
            refs: ['cycle-'.$i],
            facts: ['index' => $i, 'note' => 'evt'.$i],
        );
    }

    private function window(): TimelineWindow
    {
        return new TimelineWindow('2026-06-25T05:00:00+00:00', '2026-06-25T06:00:00+00:00');
    }

    public function test_every_line_is_independently_valid_json_with_manifest_first(): void
    {
        $events = [];
        for ($i = 0; $i < 100; $i++) {
            $events[] = $this->event($i);
        }
        $out = $this->tmpDir.'/100.jsonl';

        $manifest = (new AtlasLoopAuditTrailExporter)->export($events, $out, $this->window(), ['cycle_ledger' => 'v1']);

        $this->assertSame(100, $manifest->event_count);

        $lines = file($out, FILE_IGNORE_NEW_LINES);
        $this->assertCount(101, $lines, '1 header + 100 event lines');

        $header = json_decode($lines[0], true);
        $this->assertSame(ExportManifest::SCHEMA_VERSION, $header['schema_version']);
        $this->assertSame(100, $header['event_count']);

        for ($i = 1; $i <= 100; $i++) {
            $row = json_decode($lines[$i], true);
            $this->assertIsArray($row, "line {$i} must be JSON");
            $this->assertArrayHasKey('event_id', $row);
        }
    }

    public function test_tampering_a_byte_invalidates_sha256_of_body(): void
    {
        $events = [];
        for ($i = 0; $i < 20; $i++) {
            $events[] = $this->event($i);
        }
        $out = $this->tmpDir.'/tamper.jsonl';
        $manifest = (new AtlasLoopAuditTrailExporter)->export($events, $out, $this->window());

        // Recompute hash from on-disk body lines.
        $lines = file($out, FILE_IGNORE_NEW_LINES);
        $body = implode("\n", array_slice($lines, 1))."\n";
        $this->assertSame($manifest->sha256_of_body, hash('sha256', $body));

        // Mutate a single byte of one event line, write back, recompute — should differ.
        $lines[5] = preg_replace('/evt/', 'EVT', $lines[5], 1);
        $bodyMutated = implode("\n", array_slice($lines, 1))."\n";
        $this->assertNotSame($manifest->sha256_of_body, hash('sha256', $bodyMutated));
    }

    public function test_exporter_is_streaming_and_memory_bounded_for_10k_events(): void
    {
        $self = $this;
        $generator = (function () use ($self) {
            for ($i = 0; $i < 10000; $i++) {
                yield $self->event($i);
            }
        })();

        $out = $this->tmpDir.'/10k.jsonl';

        gc_collect_cycles();
        $before = memory_get_usage(true);
        $beforePeak = memory_get_peak_usage(true);
        $manifest = (new AtlasLoopAuditTrailExporter)->export($generator, $out, $this->window(), ['cycle_ledger' => 'v1']);
        gc_collect_cycles();
        $afterPeak = memory_get_peak_usage(true);

        $this->assertSame(10000, $manifest->event_count);
        // The exporter must not materialise 10k events. We give a generous 8 MiB ceiling
        // for delta in real-allocated memory above baseline; a non-streaming impl
        // (array of 10k AuditEvent + JSON strings) easily blows past this.
        $deltaPeak = $afterPeak - $before;
        $this->assertLessThan(8 * 1024 * 1024, $deltaPeak, "streaming exporter peaked too high: {$deltaPeak} bytes");

        // Source-level proof: exporter file MUST NOT call iterator_to_array on $events.
        $src = (string) file_get_contents(base_path('app/Services/Ai/AutonomousEvolution/AuditTrail/AtlasLoopAuditTrailExporter.php'));
        $this->assertStringNotContainsString('iterator_to_array', $src);
        $this->assertStringNotContainsString('array_merge($events', $src);
    }

    public function test_two_exports_of_same_window_are_byte_identical(): void
    {
        $events1 = [];
        $events2 = [];
        for ($i = 0; $i < 50; $i++) {
            $events1[] = $this->event($i);
            $events2[] = $this->event($i);
        }
        $a = $this->tmpDir.'/run_a.jsonl';
        $b = $this->tmpDir.'/run_b.jsonl';

        (new AtlasLoopAuditTrailExporter)->export($events1, $a, $this->window(), ['cycle_ledger' => 'v1']);
        (new AtlasLoopAuditTrailExporter)->export($events2, $b, $this->window(), ['cycle_ledger' => 'v1']);

        $bytesA = file_get_contents($a);
        $bytesB = file_get_contents($b);
        $this->assertSame($bytesA, $bytesB, 'identical input must produce byte-identical output');
        $this->assertSame(hash('sha256', $bytesA), hash('sha256', $bytesB));
    }

    public function test_event_keys_are_lexicographic_and_facts_deeply_sorted(): void
    {
        $event = new AuditEvent(
            event_id: 'z',
            ts_utc: '2026-06-25T05:30:00+00:00',
            source_ledger: 'cycle_ledger',
            kind: 'k',
            refs: [],
            facts: ['zebra' => 1, 'alpha' => ['c' => 1, 'a' => 2]],
        );
        $out = $this->tmpDir.'/sort.jsonl';
        (new AtlasLoopAuditTrailExporter)->export([$event], $out, $this->window());

        $lines = file($out, FILE_IGNORE_NEW_LINES);
        $eventLine = $lines[1];
        // Top-level keys must appear in lexicographic order in the raw JSON.
        $this->assertMatchesRegularExpression('/^\{"event_id":.*"facts":\{"alpha":\{"a":2,"c":1\},"zebra":1\}.*"ts_utc":/', $eventLine);
    }
}
