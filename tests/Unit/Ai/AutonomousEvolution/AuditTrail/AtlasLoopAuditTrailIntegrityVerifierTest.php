<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\AuditTrail;

use App\Services\Ai\AutonomousEvolution\AuditTrail\AtlasLoopAuditTrailIntegrityVerifier;
use App\Services\Ai\AutonomousEvolution\AuditTrail\IntegrityReport;
use Tests\TestCase;

final class AtlasLoopAuditTrailIntegrityVerifierTest extends TestCase
{
    /**
     * Build N healthy linked events for one source_ledger.
     *
     * @return list<array<string,mixed>>
     */
    private function chain(int $n, string $ledger = 'projection_outcome'): array
    {
        $events = [];
        $prevHash = null;
        for ($i = 1; $i <= $n; $i++) {
            $facts = ['ledger' => $ledger, 'i' => $i, 'note' => 'event-'.$i];
            ksort($facts);
            $hash = hash('sha256', (string) json_encode($facts, JSON_UNESCAPED_SLASHES));
            $events[] = [
                'event_id' => $i,
                'source_ledger' => $ledger,
                'prev_hash' => $prevHash,
                'content_hash' => $hash,
                'facts' => $facts,
                'refs' => [],
            ];
            $prevHash = $hash;
        }

        return $events;
    }

    public function test_healthy_chain_of_50_events_reports_zero_anomalies(): void
    {
        $events = $this->chain(50);
        $report = (new AtlasLoopAuditTrailIntegrityVerifier)->verify($events);

        $this->assertInstanceOf(IntegrityReport::class, $report);
        $this->assertSame([], $report->anomalies);
        $this->assertTrue($report->isIntact());
    }

    public function test_tampered_facts_yield_one_broken_link_with_cascade_25_to_50(): void
    {
        $events = $this->chain(50);
        // Mutate facts of event#25 but keep its content_hash stale (don't recompute).
        $events[24]['facts']['note'] = 'TAMPERED';

        $report = (new AtlasLoopAuditTrailIntegrityVerifier)->verify($events);

        $this->assertFalse($report->isIntact());
        $brokenLinks = $report->ofType(IntegrityReport::ANOMALY_BROKEN_LINK);
        $this->assertCount(1, $brokenLinks);
        $this->assertSame(25, $brokenLinks[0]['event_id']);
        $affected = $brokenLinks[0]['affected_event_ids'];
        $this->assertSame(range(25, 50), $affected);
    }

    public function test_missing_event_yields_one_sequence_gap_and_orphan_refs(): void
    {
        $events = $this->chain(50);
        // Make some later events reference event#10 via refs[].
        $events[14]['refs'] = [10];
        $events[19]['refs'] = [10];
        // Remove event#10.
        unset($events[9]);
        $events = array_values($events);

        $report = (new AtlasLoopAuditTrailIntegrityVerifier)->verify($events);

        $gaps = $report->ofType(IntegrityReport::ANOMALY_SEQUENCE_GAP);
        $this->assertCount(1, $gaps);
        $this->assertSame(10, $gaps[0]['event_id']);

        $orphans = $report->ofType(IntegrityReport::ANOMALY_ORPHAN_REF);
        $this->assertCount(2, $orphans);
        foreach ($orphans as $o) {
            $this->assertSame(10, $o['orphan_target']);
        }
    }

    public function test_verifier_never_mutates_inputs(): void
    {
        $events = $this->chain(10);
        // Snapshot a deep clone of the events.
        $snapshot = json_encode($events, JSON_UNESCAPED_SLASHES);

        (new AtlasLoopAuditTrailIntegrityVerifier)->verify($events);

        $after = json_encode($events, JSON_UNESCAPED_SLASHES);
        $this->assertSame($snapshot, $after, 'verifier must not mutate input events');
    }

    public function test_verifier_source_is_pure_no_side_effects(): void
    {
        $src = (string) file_get_contents(base_path('app/Services/Ai/AutonomousEvolution/AuditTrail/AtlasLoopAuditTrailIntegrityVerifier.php'));
        foreach (['file_put_contents', 'fopen', 'shell_exec', 'proc_open', 'exec(', 'system(', 'DB::', 'Queue::', 'Http::'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $src, "integrity verifier must NOT contain {$forbidden}");
        }
    }

    public function test_accepts_window_object_with_events_method(): void
    {
        $events = $this->chain(5);
        $window = new class($events)
        {
            public function __construct(private array $events) {}

            public function events(): array
            {
                return $this->events;
            }
        };

        $report = (new AtlasLoopAuditTrailIntegrityVerifier)->verify($window);
        $this->assertTrue($report->isIntact());
    }
}
