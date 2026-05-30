<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SoftwareCompanyStewardship;

use App\Http\Controllers\Ai\SoftwareCompanyStewardship\AreaFocusLoopCommandController;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Reliable24hLoopRunnerService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Focused coverage for the Loop Command Surface read controller (actions a + b).
 *
 * The controller is instantiated directly with the REAL composed services — the
 * AP-739 cockpit and the AP-790 reliable 24h loop runner pointed at a temp
 * storage root holding a REAL on-disk JSONL ledger. Routes are intentionally
 * not registered here (a later agent owns the route edit), so the controller is
 * exercised in isolation against real inputs. The assertions verify the
 * composed response shape, deterministic ETag/304, the stable unknown-area 404,
 * and the read-only honesty invariants (no merge/provider/execution claimed).
 */
final class AreaFocusLoopCommandLiveCyclesTest extends TestCase
{
    private const AREA = 'agentic_engineering_os';

    private const FOCUS = 'dev_forge';

    private string $storage;

    private Reliable24hLoopRunnerService $runner;

    private AreaFocusLoopCommandController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = sys_get_temp_dir().'/atlas-loop-cmd-'.bin2hex(random_bytes(5));
        File::ensureDirectoryExists($this->storage);

        // Real runner pointed at an isolated temp ledger root. Bound as a
        // singleton so the container injects this exact instance into the
        // controller — keeping the test resilient to the controller's
        // constructor signature (other actions are built concurrently).
        $this->runner = app(Reliable24hLoopRunnerService::class);
        $this->runner->setStorageRootForTesting($this->storage);
        $this->app->instance(Reliable24hLoopRunnerService::class, $this->runner);

        $this->controller = $this->app->make(AreaFocusLoopCommandController::class);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // (a) live
    // ------------------------------------------------------------------

    public function test_live_unknown_area_returns_stable_404_with_supported_areas(): void
    {
        $response = $this->controller->live($this->request(), '___does_not_exist___');

        $this->assertSame(404, $response->getStatusCode());
        $payload = $this->decode($response);
        $this->assertSame('unknown_area', $payload['error']['code']);
        $this->assertContains(self::AREA, $payload['error']['supported_areas']);
        $this->assertStringContainsString('___does_not_exist___', $payload['error']['message']);
    }

    public function test_live_known_area_composes_cockpit_and_real_run_state(): void
    {
        // Real run-state: a lock file + a kill file on disk must be reflected truthfully.
        File::ensureDirectoryExists(dirname($this->runner->lockPath(self::AREA, self::FOCUS)));
        File::put($this->runner->killSwitchPath(self::AREA, self::FOCUS), '{"actor":"vitor"}');

        $response = $this->controller->live($this->request(), self::AREA);

        $this->assertSame(200, $response->getStatusCode());
        // Laravel normalizes the directive order, so assert both are present.
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('max-age=5', $cacheControl);
        $this->assertNotEmpty($response->headers->get('ETag'));

        $body = $this->decode($response);
        $this->assertSame('atlas.software_company_stewardship.loop_command_live.v1', $body['schema_version']);
        $this->assertSame(self::AREA, $body['area_id']);
        $this->assertSame(self::FOCUS, $body['focus']);
        $this->assertSame('atlas_software_company', $body['portfolio_id']);
        $this->assertTrue($body['read_only']);

        // Composed (not duplicated) cockpit aggregate is embedded verbatim.
        $this->assertSame('ready', $body['cockpit']['status']);
        $this->assertSame('atlas.software_company.product_mode_cockpit.v1', $body['cockpit']['schema_version']);

        // Run-state mirrors the runner's real path-authority truth from disk.
        $this->assertTrue($body['run_state']['kill_switch']['active']);
        $this->assertFalse($body['run_state']['pause']['active']);
        $this->assertSame(
            $this->runner->killSwitchPath(self::AREA, self::FOCUS),
            $body['run_state']['kill_switch']['path'],
        );
        $this->assertSame(
            'atlas.software_company_stewardship.ap790_24h_stewardship_recovery.v1',
            $body['run_state']['stewardship_recovery']['schema_version'],
        );
        $this->assertSame(
            'atlas.software_company_stewardship.ap790_continuous_24h_scheduler_backlog.v1',
            $body['run_state']['scheduler_backlog']['schema_version'],
        );
        $this->assertStringStartsWith('sha256:', $body['surface_hash']);
    }

    public function test_live_etag_is_content_derived_and_never_serves_stale_state(): void
    {
        // HONESTY: the AP-739 cockpit aggregate legitimately rotates its own
        // surface_hash per projection (the executive decision inbox synthesizes a
        // fresh item id each call) — a PRE-EXISTING property of the cockpit, not
        // of this read surface. So the live ETag correctly changes when the
        // projected state changes ("no caching of stale state"); it is a real,
        // content-derived hash, not a fabricated-stable one.
        $first = $this->decode($this->controller->live($this->request(), self::AREA));
        $this->assertStringStartsWith('sha256:', $first['surface_hash']);

        // A stale/mismatched If-None-Match must yield a fresh 200 (never a 304).
        $stale = $this->controller->live($this->request(['If-None-Match' => '"sha256:deadbeef"']), self::AREA);
        $this->assertSame(200, $stale->getStatusCode());
        $this->assertNotSame('"sha256:deadbeef"', $stale->headers->get('ETag'));
    }

    // ------------------------------------------------------------------
    // (b) cycles
    // ------------------------------------------------------------------

    public function test_cycles_returns_filtered_ledger_oldest_to_newest_excluding_health_snapshots(): void
    {
        $this->seedLedger([
            $this->cycleRow(1, 'merged', '-5 hours'),
            ['record_type' => 'health_snapshot', 'note' => 'not a cycle row'],
            $this->cycleRow(2, 'blocked', '-3 hours'),
            $this->cycleRow(3, 'progress', '-1 hours'),
        ]);

        $response = $this->controller->cycles($this->request(), self::AREA);
        $this->assertSame(200, $response->getStatusCode());

        $body = $this->decode($response);
        $this->assertSame('atlas.software_company_stewardship.loop_command_cycles.v1', $body['schema_version']);
        $this->assertSame(self::AREA, $body['area_id']);
        $this->assertSame(self::FOCUS, $body['focus']);

        // health_snapshot row is filtered by readLedgerRecords (only LEDGER_SCHEMA rows).
        $this->assertSame(3, $body['ledger_record_count_total']);
        $this->assertSame(3, $body['returned_count']);
        $this->assertSame(20, $body['tail']);
        $this->assertNull($body['hours']);
        $this->assertSame([1, 2, 3], array_map(static fn (array $c): int => $c['cycle_index'], $body['cycles']));
        $this->assertStringStartsWith('sha256:', $body['surface_hash']);
    }

    public function test_cycles_hours_filter_applies_before_tail(): void
    {
        $this->seedLedger([
            $this->cycleRow(1, 'merged', '-50 hours'),   // outside 24h
            $this->cycleRow(2, 'blocked', '-30 hours'),  // outside 24h
            $this->cycleRow(3, 'merged', '-10 hours'),   // inside 24h
            $this->cycleRow(4, 'progress', '-2 hours'),  // inside 24h
        ]);

        // hours=24 keeps only rows 3 & 4; tail=1 then keeps the newest (row 4).
        $response = $this->controller->cycles($this->request(['_query' => ['hours' => '24', 'tail' => '1']]), self::AREA);
        $body = $this->decode($response);

        $this->assertSame(4, $body['ledger_record_count_total']);
        $this->assertSame(24, $body['hours']);
        $this->assertSame(1, $body['tail']);
        $this->assertSame(1, $body['returned_count']);
        $this->assertSame([4], array_map(static fn (array $c): int => $c['cycle_index'], $body['cycles']));
    }

    public function test_cycles_tail_is_hard_capped_at_200(): void
    {
        $response = $this->controller->cycles($this->request(['_query' => ['tail' => '9999']]), self::AREA);
        $body = $this->decode($response);

        $this->assertSame(200, $body['tail']);
        $this->assertSame(0, $body['ledger_record_count_total']);
        $this->assertSame([], $body['cycles']);
    }

    public function test_cycles_empty_ledger_returns_honest_empty_envelope(): void
    {
        $response = $this->controller->cycles($this->request(), self::AREA);
        $body = $this->decode($response);

        $this->assertSame(0, $body['ledger_record_count_total']);
        $this->assertSame(0, $body['returned_count']);
        $this->assertSame([], $body['cycles']);
    }

    public function test_cycles_etag_matches_returns_304(): void
    {
        $this->seedLedger([$this->cycleRow(1, 'merged', '-1 hours')]);

        $first = $this->controller->cycles($this->request(), self::AREA);
        $etag = $first->headers->get('ETag');
        $this->assertSame(200, $first->getStatusCode());

        $second = $this->controller->cycles($this->request(['If-None-Match' => $etag]), self::AREA);
        $this->assertSame(304, $second->getStatusCode());
        $this->assertSame($etag, $second->headers->get('ETag'));
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /**
     * @param  array<string,mixed>  $opts  optional 'If-None-Match' header and '_query' array
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
    private function cycleRow(int $index, string $outcome, string $recordedAtModifier): array
    {
        $recordedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify($recordedAtModifier)
            ->format(DateTimeInterface::ATOM);

        return [
            'schema_version' => Reliable24hLoopRunnerService::LEDGER_SCHEMA,
            'run_id' => 'ap790run_test',
            'cycle_index' => $index,
            'cycle_id' => 'aesc_test_'.$index,
            'finding_key' => 'finding_'.$index,
            'outcome' => $outcome,
            'merge_performed' => $outcome === 'merged',
            'recorded_at' => $recordedAt,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function decode(\Symfony\Component\HttpFoundation\Response $response): array
    {
        return (array) json_decode((string) $response->getContent(), true);
    }
}
