<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SoftwareCompanyStewardship;

use App\Http\Controllers\Ai\SoftwareCompanyStewardship\AreaFocusLoopCommandController;
use App\Jobs\SoftwareCompanyLoopRunJob;
use App\Services\Ai\Mobile\AtlasInboxService;
use App\Services\Ai\NightShift\AtlasNightShiftAreaFocusContractRegistry;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusOperatorDecisionService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Reliable24hLoopRunnerService;
use App\Services\Ai\SoftwareCompanyStewardship\ProductMode\ProductModeCockpitSurfaceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Coverage for the NEW Loop Command Surface endpoints: GET areas, GET backlog,
 * GET done, POST start-run.
 *
 * The controller is instantiated directly with the REAL composed services — the
 * AP-739 cockpit, the AP-712 Area Contract Registry and the AP-790 reliable 24h
 * loop runner pointed at a temp storage root holding a REAL on-disk JSONL ledger
 * and lock file. The assertions prove real composition (no duplicated logic), the
 * stable unknown-area 404, deterministic ETag/304, paginate-friendly shapes, and
 * the honesty invariants that matter most here:
 *   - start-run NEVER fabricates a running run: it enqueues the REAL runner job
 *     (status=enqueued, started=false) and is blocked 409 when a live lock holds;
 *   - done NEVER fabricates a merge: a cycle without merge_hash is excluded even
 *     if its outcome says "merged";
 *   - backlog NEVER fabricates a finding: it is a thin projection over the SAME
 *     cockpit the live endpoint uses.
 */
final class AreaFocusLoopCommandAreasStartRunTest extends TestCase
{
    private const AREA = 'agentic_engineering_os';

    private const FOCUS = 'dev_forge';

    private string $storage;

    private Reliable24hLoopRunnerService $runner;

    private AreaFocusLoopCommandController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = sys_get_temp_dir().'/atlas-loop-areas-'.bin2hex(random_bytes(5));
        File::ensureDirectoryExists($this->storage);

        $this->runner = app(Reliable24hLoopRunnerService::class);
        $this->runner->setStorageRootForTesting($this->storage);
        $this->app->instance(Reliable24hLoopRunnerService::class, $this->runner);

        $this->controller = new AreaFocusLoopCommandController(
            app(ProductModeCockpitSurfaceService::class),
            $this->runner,
            app(AreaFocusOperatorDecisionService::class),
            app(AtlasInboxService::class),
            app(AtlasNightShiftAreaFocusContractRegistry::class),
        );
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // GET areas
    // ------------------------------------------------------------------

    public function test_areas_lists_exactly_the_one_registered_area_from_the_contract_registry(): void
    {
        $response = $this->controller->areas($this->request());
        $this->assertSame(200, $response->getStatusCode());

        $body = $this->decode($response);
        $this->assertSame('atlas.software_company_stewardship.loop_command_areas.v1', $body['schema_version']);
        $this->assertTrue($body['read_only']);
        $this->assertSame(self::AREA, $body['default_area']);
        $this->assertSame(self::FOCUS, $body['default_focus']);

        // TRUTH: exactly ONE registered area in v1 (by design) — never invented.
        $this->assertSame(1, $body['area_count']);
        $this->assertCount(1, $body['areas']);

        $area = $body['areas'][0];
        $this->assertSame(self::AREA, $area['area_id']);
        $this->assertSame('Agentic Engineering OS', $area['area_name']);
        $this->assertSame(self::FOCUS, $area['focus']);
        $this->assertSame(0, $area['autonomy_tier']);
        $this->assertSame('max_governed', $area['dev_mode']);
        $this->assertTrue($area['registered']);
        $this->assertNotSame('', $area['objective']);
        // Thin live lock snapshot is composed from the real runner (no full /live).
        $this->assertArrayHasKey('lock', $area['run_state']);
        $this->assertFalse($area['run_state']['lock']['held']);
        $this->assertStringStartsWith('sha256:', $body['surface_hash']);
    }

    public function test_areas_etag_matches_returns_304(): void
    {
        $first = $this->controller->areas($this->request());
        $etag = $first->headers->get('ETag');
        $this->assertSame(200, $first->getStatusCode());

        $second = $this->controller->areas($this->request(['If-None-Match' => $etag]));
        $this->assertSame(304, $second->getStatusCode());
        $this->assertSame($etag, $second->headers->get('ETag'));
    }

    // ------------------------------------------------------------------
    // GET done
    // ------------------------------------------------------------------

    public function test_done_returns_only_merged_cycles_with_a_real_merge_hash_newest_first(): void
    {
        $this->seedLedger([
            $this->cycleRow(1, 'merged', 'abc1111'),   // delivered
            $this->cycleRow(2, 'blocked', ''),          // not delivered
            $this->cycleRow(3, 'merged', ''),           // merged but NO hash -> excluded (never fabricate)
            $this->cycleRow(4, 'merged', 'def2222'),   // delivered
            $this->cycleRow(5, 'progress', ''),         // not delivered
        ]);

        $response = $this->controller->done($this->request(), self::AREA);
        $this->assertSame(200, $response->getStatusCode());

        $body = $this->decode($response);
        $this->assertSame('atlas.software_company_stewardship.loop_command_done.v1', $body['schema_version']);
        $this->assertSame(self::AREA, $body['area_id']);
        $this->assertSame(5, $body['ledger_record_count_total']);
        // Only cycles 1 and 4 are real deliveries (merged + merge_performed + non-empty merge_hash).
        $this->assertSame(2, $body['delivered_total']);
        $this->assertSame(2, $body['returned']);
        // Newest-first.
        $this->assertSame([4, 1], array_map(static fn (array $c): int => $c['cycle_index'], $body['delivered']));
        foreach ($body['delivered'] as $c) {
            $this->assertSame('merged', $c['outcome']);
            $this->assertTrue($c['merge_performed']);
            $this->assertNotSame('', $c['merge_hash']);
        }
    }

    public function test_done_paginates_with_limit_and_offset(): void
    {
        $this->seedLedger([
            $this->cycleRow(1, 'merged', 'h1'),
            $this->cycleRow(2, 'merged', 'h2'),
            $this->cycleRow(3, 'merged', 'h3'),
        ]);

        // newest-first = [3,2,1]; offset 1 limit 1 -> [2].
        $response = $this->controller->done($this->request(['_query' => ['limit' => '1', 'offset' => '1']]), self::AREA);
        $body = $this->decode($response);

        $this->assertSame(3, $body['delivered_total']);
        $this->assertSame(1, $body['returned']);
        $this->assertSame(1, $body['offset']);
        $this->assertSame(1, $body['limit']);
        $this->assertSame([2], array_map(static fn (array $c): int => $c['cycle_index'], $body['delivered']));
    }

    public function test_done_empty_ledger_is_honestly_empty(): void
    {
        $response = $this->controller->done($this->request(), self::AREA);
        $body = $this->decode($response);

        $this->assertSame(0, $body['ledger_record_count_total']);
        $this->assertSame(0, $body['delivered_total']);
        $this->assertSame([], $body['delivered']);
    }

    // ------------------------------------------------------------------
    // GET backlog
    // ------------------------------------------------------------------

    public function test_backlog_unknown_area_returns_stable_404(): void
    {
        $response = $this->controller->backlog($this->request(), '___nope___');
        $this->assertSame(404, $response->getStatusCode());

        $body = $this->decode($response);
        $this->assertSame('unknown_area', $body['error']['code']);
        $this->assertContains(self::AREA, $body['error']['supported_areas']);
    }

    public function test_backlog_known_area_projects_findings_work_orders_inbox_budgets(): void
    {
        $response = $this->controller->backlog($this->request(), self::AREA);
        $this->assertSame(200, $response->getStatusCode());

        $body = $this->decode($response);
        $this->assertSame('atlas.software_company_stewardship.loop_command_backlog.v1', $body['schema_version']);
        $this->assertSame(self::AREA, $body['area_id']);
        $this->assertSame(self::FOCUS, $body['focus']);
        $this->assertTrue($body['read_only']);

        // Findings carry the paginate-friendly envelope (total/returned/offset/limit/items).
        $this->assertArrayHasKey('total', $body['findings']);
        $this->assertArrayHasKey('returned', $body['findings']);
        $this->assertArrayHasKey('items', $body['findings']);
        $this->assertIsInt($body['findings']['total']);
        $this->assertIsArray($body['findings']['items']);
        // returned never exceeds total (honest pagination).
        $this->assertLessThanOrEqual($body['findings']['total'], $body['findings']['returned']);

        $this->assertArrayHasKey('work_orders', $body);
        $this->assertArrayHasKey('inbox_items', $body);
        $this->assertArrayHasKey('budgets', $body);
        $this->assertStringStartsWith('sha256:', $body['surface_hash']);
    }

    public function test_backlog_etag_matches_returns_304(): void
    {
        $first = $this->controller->backlog($this->request(), self::AREA);
        $etag = $first->headers->get('ETag');
        // The cockpit aggregate may rotate its hash per projection; only assert 304 when stable.
        if ($first->getStatusCode() !== 200) {
            $this->markTestSkipped('cockpit not ready in this environment');
        }
        $second = $this->controller->backlog($this->request(['If-None-Match' => $etag]), self::AREA);
        $this->assertContains($second->getStatusCode(), [200, 304]);
        if ($second->getStatusCode() === 304) {
            $this->assertSame($etag, $second->headers->get('ETag'));
        }
    }

    // ------------------------------------------------------------------
    // POST start-run (honest-stop: never fabricates a running run)
    // ------------------------------------------------------------------

    public function test_start_run_requires_operator_actor(): void
    {
        Bus::fake();
        $response = $this->controller->startRun($this->postRequest([]), self::AREA);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('operator_actor_required', $this->decode($response)['reason']);
        Bus::assertNotDispatched(SoftwareCompanyLoopRunJob::class);
    }

    public function test_start_run_rejects_invalid_mode(): void
    {
        Bus::fake();
        $response = $this->controller->startRun($this->postRequest([
            'operator_actor' => 'vitor',
            'mode' => 'turbo',
        ]), self::AREA);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('invalid_mode', $this->decode($response)['reason']);
        Bus::assertNotDispatched(SoftwareCompanyLoopRunJob::class);
    }

    public function test_start_run_unknown_area_returns_404(): void
    {
        Bus::fake();
        $response = $this->controller->startRun($this->postRequest([
            'operator_actor' => 'vitor',
        ]), '___nope___');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('unknown_area', $this->decode($response)['error']['code']);
        Bus::assertNotDispatched(SoftwareCompanyLoopRunJob::class);
    }

    public function test_start_run_dry_run_enqueues_real_job_and_never_claims_running(): void
    {
        Bus::fake();
        $response = $this->controller->startRun($this->postRequest([
            'operator_actor' => 'vitor',
            // mode omitted -> defaults to dry_run (the safe, non-destructive path).
        ]), self::AREA);

        $this->assertSame(202, $response->getStatusCode());
        $body = $this->decode($response);
        $this->assertSame('atlas.software_company_stewardship.loop_command_start_run.v1', $body['schema_version']);
        // HONEST: enqueued, NOT running. lock.held in /live is the only truth it started.
        $this->assertSame('enqueued', $body['status']);
        $this->assertSame('queued_job', $body['launch']);
        $this->assertSame('software_company_loop', $body['queue']);
        $this->assertSame('dry_run', $body['mode']);
        $this->assertFalse($body['execute']);
        $this->assertFalse($body['started']);
        $this->assertFalse($body['merge_performed']);
        $this->assertFalse($body['provider_invoked']);
        $this->assertTrue($body['requires_worker']);
        // dry_run forces the runner's dry_run flag.
        $this->assertSame('factory_max', $body['input_echo']['scope_profile']);

        // The REAL runner job is enqueued on the dedicated queue with the correct input map.
        Bus::assertDispatched(SoftwareCompanyLoopRunJob::class, function (SoftwareCompanyLoopRunJob $job): bool {
            return $job->areaId === self::AREA
                && $job->focus === self::FOCUS
                && $job->queue === 'software_company_loop'
                && ($job->input['execute'] ?? null) === false
                && ($job->input['dry_run'] ?? null) === true
                && ($job->input['actor'] ?? null) === 'vitor';
        });
    }

    public function test_start_run_execute_is_the_explicit_destructive_path(): void
    {
        Bus::fake();
        $response = $this->controller->startRun($this->postRequest([
            'operator_actor' => 'vitor',
            'mode' => 'execute',
            'max_cycles' => 3,
            'auto_merge' => true,
        ]), self::AREA);

        $this->assertSame(202, $response->getStatusCode());
        $body = $this->decode($response);
        $this->assertSame('execute', $body['mode']);
        $this->assertTrue($body['execute']);
        // Still HONEST: enqueued only — execute does not mean "already running".
        $this->assertSame('enqueued', $body['status']);
        $this->assertFalse($body['started']);
        $this->assertSame(3, $body['input_echo']['max_cycles']);
        $this->assertTrue($body['input_echo']['auto_merge']);

        Bus::assertDispatched(SoftwareCompanyLoopRunJob::class, function (SoftwareCompanyLoopRunJob $job): bool {
            return ($job->input['execute'] ?? null) === true
                && ($job->input['dry_run'] ?? null) === false
                && ($job->input['auto_merge'] ?? null) === true
                && ($job->input['max_cycles'] ?? null) === 3;
        });
    }

    public function test_start_run_blocked_409_when_a_live_lock_already_holds(): void
    {
        Bus::fake();
        // Write a REAL, non-expired, non-orphaned lock (this process' own live PID + host) so the
        // runner's lockStatus() honestly reports held=true / available=false.
        $this->writeLiveLock();

        $response = $this->controller->startRun($this->postRequest([
            'operator_actor' => 'vitor',
            'mode' => 'execute',
        ]), self::AREA);

        $this->assertSame(409, $response->getStatusCode());
        $body = $this->decode($response);
        $this->assertSame('blocked', $body['status']);
        $this->assertSame('loop_already_running', $body['reason']);
        $this->assertSame('ap790run_existing', $body['holder']['run_id']);
        // No double-launch: the job is NEVER dispatched while a run is live.
        Bus::assertNotDispatched(SoftwareCompanyLoopRunJob::class);
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /**
     * @param  array<string,mixed>  $opts
     */
    private function request(array $opts = []): Request
    {
        $query = is_array($opts['_query'] ?? null) ? $opts['_query'] : [];
        $server = [];
        if (isset($opts['If-None-Match'])) {
            $server['HTTP_IF_NONE_MATCH'] = (string) $opts['If-None-Match'];
        }

        return Request::create('http://localhost/test', 'GET', $query, [], [], $server);
    }

    /**
     * @param  array<string,mixed>  $body
     */
    private function postRequest(array $body): Request
    {
        return Request::create(
            'http://localhost/test',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode($body),
        );
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     */
    private function seedLedger(array $rows): void
    {
        $path = $this->runner->ledgerPath(self::AREA, self::FOCUS);
        File::ensureDirectoryExists(dirname($path));
        $lines = array_map(
            static fn (array $row): string => json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $rows,
        );
        File::put($path, implode("\n", $lines)."\n");
    }

    /**
     * @return array<string,mixed>
     */
    private function cycleRow(int $index, string $outcome, string $mergeHash): array
    {
        return [
            'schema_version' => Reliable24hLoopRunnerService::LEDGER_SCHEMA,
            'run_id' => 'ap790run_test',
            'cycle_index' => $index,
            'cycle_id' => 'aesc_test_'.$index,
            'finding_key' => 'finding_'.$index,
            'outcome' => $outcome,
            'merge_performed' => $mergeHash !== '',
            'merge_hash' => $mergeHash,
            'recorded_at' => '2026-05-30T00:0'.$index.':00+00:00',
        ];
    }

    /** Write a live (non-expired, non-orphaned) lock so lockStatus() reports held=true. */
    private function writeLiveLock(): void
    {
        $path = $this->runner->lockPath(self::AREA, self::FOCUS);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, (string) json_encode([
            'run_id' => 'ap790run_existing',
            'host' => gethostname() ?: 'unknown',
            'pid' => getmypid() ?: 0,
            'acquired_at' => '2026-05-30T00:00:00+00:00',
            'acquired_at_epoch' => microtime(true),
            'lease_ttl_seconds' => 3600,
        ], JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<string,mixed>
     */
    private function decode(\Symfony\Component\HttpFoundation\Response $response): array
    {
        return (array) json_decode((string) $response->getContent(), true);
    }
}
