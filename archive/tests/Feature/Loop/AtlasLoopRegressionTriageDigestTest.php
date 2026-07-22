<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Services\Ai\AutonomousEvolution\AtlasLoopMorningDigestService;
use App\Services\Ai\AutonomousEvolution\AtlasLoopRegressionWatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

/**
 * WIRING — the RegressionWatcher now records its triage (attributed / unattributed / repair-enqueued) to a
 * JSONL the morning digest surfaces, so the operator finally SEES `unattributed` failures (external/pre-existing
 * breakage the loop will NOT auto-repair). Proves: flag OFF ⇒ no file + byte-identical return; flag ON ⇒ one
 * record per triage outcome and the digest reports the unattributed count + failure id.
 *
 * Focused-migration setUp (no RefreshDatabase: the full suite has a Postgres-only extension). Pure of providers.
 */
final class AtlasLoopRegressionTriageDigestTest extends TestCase
{
    private string $logPath;

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_loop_tasks')) {
            foreach ([
                '2026_06_02_000100_create_atlas_loop_runtime_tables.php',
                '2026_06_02_000200_complete_atlas_loop_runtime_schema.php',
            ] as $file) {
                (require base_path('database/migrations/'.$file))->up();
            }
        }

        $this->logPath = sys_get_temp_dir().'/atlas-regression-triage-'.bin2hex(random_bytes(6)).'.jsonl';
        @unlink($this->logPath);
        config(['atlas.loop.morning_digest.regression_triage_log_path' => $this->logPath]);
    }

    protected function tearDown(): void
    {
        @unlink($this->logPath);
        parent::tearDown();
    }

    private function campaign(): AtlasLoopCampaign
    {
        return AtlasLoopCampaign::query()->create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'goal' => 'regression triage digest proof',
            'status' => 'running',
            'config' => [],
        ]);
    }

    /** @return list<array<string,mixed>> */
    private function failures(): array
    {
        return [
            ['id' => 'suite::check.A', 'related_files' => ['app/X.php']],   // overlaps the merge ⇒ attributed
            ['id' => 'suite::check.B', 'related_files' => ['app/Y.php']],   // no overlap ⇒ unattributed
        ];
    }

    /** @return list<array<string,mixed>> */
    private function recentMerges(): array
    {
        return [['commit' => 'deadbeefcafe0001', 'files' => ['app/X.php'], 'merged_at' => '2026-06-24T00:00:00+00:00']];
    }

    /** @return array<string,list<array<string,mixed>>> records grouped by kind */
    private function readLog(): array
    {
        $grouped = ['attributed' => [], 'unattributed' => [], 'repair_enqueued' => []];
        foreach (preg_split('/\r?\n/', (string) @file_get_contents($this->logPath)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $r = json_decode($line, true);
            if (is_array($r) && isset($grouped[$r['kind'] ?? ''])) {
                $grouped[$r['kind']][] = $r;
            }
        }

        return $grouped;
    }

    // (a) flag OFF ⇒ no JSONL file is created and the watcher result is byte-identical to the early-return.
    public function test_flag_off_writes_no_log_and_is_byte_identical(): void
    {
        config(['atlas.loop.regression_sentinel_enabled' => false]);
        $campaign = $this->campaign();

        $result = app(AtlasLoopRegressionWatcher::class)
            ->enqueueRepairs($campaign->id, $this->failures(), $this->recentMerges());

        $this->assertSame(['attributed' => 0, 'enqueued' => 0, 'unattributed' => 0], $result, 'OFF ⇒ byte-identical early-return');
        $this->assertFileDoesNotExist($this->logPath, 'OFF ⇒ no JSONL file is created');
    }

    // (b) flag ON ⇒ a record per triage outcome; the digest reports unattributed=1 with the failure id.
    public function test_flag_on_records_triage_and_digest_surfaces_unattributed(): void
    {
        config(['atlas.loop.regression_sentinel_enabled' => true]);
        $campaign = $this->campaign();

        $result = app(AtlasLoopRegressionWatcher::class)
            ->enqueueRepairs($campaign->id, $this->failures(), $this->recentMerges());

        // Triage: A attributed (file overlap), B unattributed; the attributed repair is enqueued.
        $this->assertSame(1, $result['attributed']);
        $this->assertSame(1, $result['unattributed']);
        $this->assertSame(1, $result['enqueued']);

        $log = $this->readLog();
        $this->assertCount(1, $log['attributed']);
        $this->assertSame('suite::check.A', $log['attributed'][0]['failure_id']);
        $this->assertCount(1, $log['unattributed']);
        $this->assertSame('suite::check.B', $log['unattributed'][0]['failure_id']);
        $this->assertCount(1, $log['repair_enqueued'], 'symmetry: one record per enqueued repair');
        $this->assertSame('atlas.loop.regression_triage.v1', $log['unattributed'][0]['schema_version']);

        // The digest surfaces the triage: unattributed count + the failure id in the top list.
        $digest = app(AtlasLoopMorningDigestService::class);
        $m = new ReflectionMethod($digest, 'regressionTriage');
        $m->setAccessible(true);
        $section = $m->invoke($digest, Carbon::now()->subHours(24));

        $this->assertSame(1, $section['attributed']);
        $this->assertSame(1, $section['unattributed']);
        $this->assertSame(1, $section['repairs_enqueued']);
        $this->assertSame(['suite::check.B'], $section['top_unattributed_failure_ids']);
    }
}
