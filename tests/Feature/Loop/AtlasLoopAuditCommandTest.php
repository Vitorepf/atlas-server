<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AuditTrail\AtlasLoopAuditTrailComposer;
use App\Services\Ai\AutonomousEvolution\AuditTrail\AtlasLoopAuditTrailExporter;
use App\Services\Ai\AutonomousEvolution\AuditTrail\AtlasLoopAuditTrailIntegrityVerifier;
use App\Services\Ai\AutonomousEvolution\AuditTrail\AtlasLoopAuditTrailReplayer;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasLoopAuditCommandTest extends TestCase
{
    private string $workDir = '';

    private string $sourcePath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->workDir = sys_get_temp_dir().'/atlas-audit-cli-'.bin2hex(random_bytes(6));
        @mkdir($this->workDir, 0o755, true);
        $this->sourcePath = $this->workDir.'/source.jsonl';
        $this->writeFixtureSource(intact: true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->workDir);
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach ((array) scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir.'/'.$entry;
            if (is_dir($full)) {
                $this->rrmdir($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($dir);
    }

    private function writeFixtureSource(bool $intact): void
    {
        // 5 chronologically ordered events in one source ledger, each carrying a sane facts payload.
        $events = [];
        for ($i = 1; $i <= 5; $i++) {
            $events[] = [
                'event_id' => (string) $i,
                'ts_utc' => sprintf('2026-06-25T00:00:%02dZ', $i),
                'source_ledger' => 'projection_outcome',
                'kind' => 'phase.completed',
                'refs' => $i > 1 ? [(string) ($i - 1)] : [],
                'facts' => ['i' => $i, 'note' => 'event-'.$i],
            ];
        }
        if (! $intact) {
            // Inject a sequence gap: drop event #3 and an orphan ref from #4 → #3.
            unset($events[2]);
            $events = array_values($events);
        }
        $lines = array_map(static fn (array $e): string => (string) json_encode($e, JSON_UNESCAPED_SLASHES), $events);
        file_put_contents($this->sourcePath, implode("\n", $lines)."\n");
    }

    public function test_timeline_prints_events_in_chronological_order(): void
    {
        $exit = Artisan::call('atlas:loop:audit', [
            'action' => 'timeline',
            '--since' => '2026-06-25T00:00:00Z',
            '--until' => '2026-06-25T00:01:00Z',
            '--source-jsonl' => $this->sourcePath,
        ]);
        $p = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('timeline', $p['action']);
        $this->assertCount(5, $p['events']);
        $this->assertSame(1, $p['events'][0]['event_id']);
        $this->assertSame(5, $p['events'][4]['event_id']);
    }

    public function test_replay_prints_replay_report_with_schema_and_counts(): void
    {
        $exit = Artisan::call('atlas:loop:audit', [
            'action' => 'replay',
            '--from' => '2026-06-25T00:00:00Z',
            '--to' => '2026-06-25T00:01:00Z',
            '--source-jsonl' => $this->sourcePath,
        ]);
        $p = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('replay', $p['action']);
        $this->assertSame('atlas.loop.audit_trail.replay_report.v1', $p['report']['schema_version']);
        $this->assertArrayHasKey('per_source_counts', $p['report']);
        $this->assertArrayHasKey('chronological_events', $p['report']);
    }

    public function test_export_writes_jsonl_and_count_matches(): void
    {
        $out = $this->workDir.'/export.jsonl';
        $exit = Artisan::call('atlas:loop:audit', [
            'action' => 'export',
            '--since' => '2026-06-25T00:00:00Z',
            '--until' => '2026-06-25T00:01:00Z',
            '--out' => $out,
            '--source-jsonl' => $this->sourcePath,
        ]);
        $p = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame($out, $p['out']);
        $this->assertSame(5, $p['count']);
        $this->assertFileExists($out);
    }

    public function test_verify_exits_zero_on_intact_chain(): void
    {
        $exit = Artisan::call('atlas:loop:audit', [
            'action' => 'verify',
            '--since' => '2026-06-25T00:00:00Z',
            '--until' => '2026-06-25T00:01:00Z',
            '--source-jsonl' => $this->sourcePath,
        ]);
        $p = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('intact', $p['status']);
        $this->assertSame(0, $p['anomaly_count']);
    }

    public function test_verify_exits_non_zero_on_tampered_chain(): void
    {
        $this->writeFixtureSource(intact: false);
        $exit = Artisan::call('atlas:loop:audit', [
            'action' => 'verify',
            '--since' => '2026-06-25T00:00:00Z',
            '--until' => '2026-06-25T00:01:00Z',
            '--source-jsonl' => $this->sourcePath,
        ]);
        $p = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertSame('anomalies_detected', $p['status']);
        $this->assertGreaterThan(0, $p['anomaly_count']);
    }

    public function test_appserviceprovider_binds_singletons_with_identity_across_resolves(): void
    {
        $composerA = app(AtlasLoopAuditTrailComposer::class);
        $composerB = app(AtlasLoopAuditTrailComposer::class);
        $this->assertSame($composerA, $composerB);

        $exporterA = app(AtlasLoopAuditTrailExporter::class);
        $exporterB = app(AtlasLoopAuditTrailExporter::class);
        $this->assertSame($exporterA, $exporterB);

        $verifierA = app(AtlasLoopAuditTrailIntegrityVerifier::class);
        $verifierB = app(AtlasLoopAuditTrailIntegrityVerifier::class);
        $this->assertSame($verifierA, $verifierB);

        $replayerA = app(AtlasLoopAuditTrailReplayer::class);
        $replayerB = app(AtlasLoopAuditTrailReplayer::class);
        $this->assertSame($replayerA, $replayerB);
    }

    public function test_help_lists_all_four_subcommands(): void
    {
        $code = Artisan::call('help', ['command_name' => 'atlas:loop:audit']);
        $this->assertSame(0, $code);
        $help = Artisan::output();
        foreach (['timeline', 'replay', 'export', 'verify'] as $sub) {
            $this->assertStringContainsString($sub, $help, "help must mention subcommand {$sub}");
        }
    }
}
