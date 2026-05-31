<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SoftwareCompanyStewardship;

use App\Models\AiInboxItem;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusOperatorDecisionService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Reliable24hLoopRunnerService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Atlas Loop Command Surface · END-TO-END HTTP proof of the 5 registered routes.
 *
 * Sibling suites already drive the controller methods directly; this suite is the
 * complementary proof that the routes are REALLY wired into the
 * `ai/software-company-stewardship` group behind the `atlas.token` middleware and
 * reachable over the kernel (router → middleware → controller → JSON), which a
 * direct controller call cannot prove.
 *
 * One test per route (plus the auth gate), exercised through the real HTTP stack
 * with the canonical `X-Atlas-Token` header. The AP-790 runner is bound into the
 * container pointed at an isolated temp storage root, so its real path-getters,
 * signal-file writes and ledger reader are exercised honestly without touching the
 * live `storage/` tree. The directive write lands in the REAL `ai_inbox_items`.
 *
 * Load-bearing honesty invariants asserted over the wire:
 *   - run-control reflects the TRUE on-disk state (the file the loop reads exists),
 *   - a high-risk accept WITHOUT rationale is blocked (proposal-only is not bypassed),
 *   - a directive is NEVER dropped (persisted to the real inbox) and NEVER claims
 *     autonomous pickup or a merge.
 */
final class LoopCommandSurfaceTest extends TestCase
{
    private const AREA = 'agentic_engineering_os';

    private const LOOP_FACTORY_AREA = 'atlas_loop_factory';

    private const BASE = '/ai/software-company-stewardship/loop/agentic_engineering_os';

    private const TOKEN = 'test-token-with-enough-length-123';

    /** @var array<string,string> */
    private array $headers = ['X-Atlas-Token' => self::TOKEN];

    private string $tmp;

    /** Cached anonymous-class migration instance (`require` only returns the value on first include). */
    private static ?object $inboxMigration = null;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', self::TOKEN);
        // Keep push fully inert (no devices, no external dispatch) for the directive DB write.
        config()->set('atlas.mobile.enabled', false);

        // Point the AP-790 runner at an isolated temp root and bind THAT instance so the
        // HTTP-resolved controller composes it — its signal files + ledger never touch storage/.
        $this->tmp = sys_get_temp_dir().'/atlas_loop_surface_'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);
        $runner = $this->app->make(Reliable24hLoopRunnerService::class);
        $runner->setStorageRootForTesting($this->tmp);
        $this->app->instance(Reliable24hLoopRunnerService::class, $runner);

        // The directive endpoint persists into the REAL ai_inbox_items via AtlasInboxService. We
        // migrate ONLY that table (sqlite-safe: standard Blueprint + a partial unique index; its
        // pgsql trigger is driver-guarded). We deliberately do NOT use RefreshDatabase, because an
        // unrelated Vox migration emits a Postgres-only `CREATE EXTENSION` the in-memory sqlite test
        // DB cannot run. AuditLogService self-guards on a missing audit_events table, so the inbox
        // write is exercised end-to-end.
        if (self::$inboxMigration === null) {
            self::$inboxMigration = require database_path('migrations/2026_04_30_152000_create_ai_inbox_items_table.php');
        }
        self::$inboxMigration->down();
        self::$inboxMigration->up();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);

        parent::tearDown();
    }

    private function runner(): Reliable24hLoopRunnerService
    {
        return $this->app->make(Reliable24hLoopRunnerService::class);
    }

    /**
     * Append one genuine AP-790 cycle ledger row into the runner's OWN ledger path so the
     * read-only tail composes a real record (never a fabricated one).
     *
     * @param  array<string,mixed>  $overrides
     */
    private function appendCycleRecord(array $overrides = []): void
    {
        $record = array_merge([
            'schema_version' => Reliable24hLoopRunnerService::LEDGER_SCHEMA,
            'run_id' => 'ap790run_test',
            'cycle_index' => 1,
            'cycle_id' => 'aesc_test_1',
            'finding_key' => 'finding-alpha',
            'finding_keys' => ['finding-alpha'],
            'outcome' => 'blocked',
            'work_class' => 'product',
            'session_status' => 'completed',
            'cycle_final_status' => 'blocked',
            'blockers' => ['full_atlas_forge_flow_required'],
            'merge_performed' => false,
            'merge_hash' => '',
            'cumulative' => ['cycles_this_run' => 1, 'merges_total' => 0, 'blocked_in_row' => 1],
            'recorded_at' => now('UTC')->format(\DateTimeInterface::ATOM),
        ], $overrides);

        $path = $this->runner()->ledgerPath(self::AREA, 'dev_forge');
        File::ensureDirectoryExists(dirname($path));
        File::append($path, json_encode($record, JSON_UNESCAPED_SLASHES).PHP_EOL);
    }

    // ----------------------------------------------------------------- auth gate

    public function test_every_route_requires_the_atlas_token(): void
    {
        // GETs: 401 without the header.
        $this->getJson('/ai/software-company-stewardship/loop/areas')->assertStatus(401);
        $this->getJson(self::BASE.'/live')->assertStatus(401);
        $this->getJson(self::BASE.'/cycles')->assertStatus(401);
        $this->getJson(self::BASE.'/backlog')->assertStatus(401);
        $this->getJson(self::BASE.'/done')->assertStatus(401);
        // POSTs: 401 without the header (the body is irrelevant — auth runs first).
        $this->postJson(self::BASE.'/start-run', [])->assertStatus(401);
        $this->postJson(self::BASE.'/operator-decision', [])->assertStatus(401);
        $this->postJson(self::BASE.'/run-control', [])->assertStatus(401);
        $this->postJson(self::BASE.'/directive', [])->assertStatus(401);
    }

    // ----------------------------------------------------------------- (new) areas
    public function test_areas_lists_the_registered_areas_over_http(): void
    {
        $response = $this->getJson('/ai/software-company-stewardship/loop/areas', $this->headers)
            ->assertStatus(200)
            ->assertJsonPath('schema_version', 'atlas.software_company_stewardship.loop_command_areas.v1')
            ->assertJsonPath('default_area', self::AREA)
            ->assertJsonPath('area_count', 2);

        $areas = (array) $response->json('areas');
        $byId = [];
        foreach ($areas as $area) {
            $this->assertIsArray($area);
            $byId[(string) $area['area_id']] = $area;
        }

        $this->assertArrayHasKey(self::AREA, $byId);
        $this->assertArrayHasKey(self::LOOP_FACTORY_AREA, $byId);
        $this->assertTrue($byId[self::AREA]['registered']);
        $this->assertTrue($byId[self::LOOP_FACTORY_AREA]['registered']);
    }

    // ----------------------------------------------------------------- (new) start-run
    public function test_start_run_enqueues_the_real_job_and_never_claims_running_over_http(): void
    {
        Bus::fake();

        $this->postJson(self::BASE.'/start-run', ['operator_actor' => 'vitor'], $this->headers)
            ->assertStatus(202)
            ->assertJsonPath('status', 'enqueued')
            ->assertJsonPath('launch', 'queued_job')
            ->assertJsonPath('queue', 'software_company_loop')
            ->assertJsonPath('mode', 'dry_run')
            ->assertJsonPath('execute', false)
            ->assertJsonPath('started', false)
            ->assertJsonPath('provider_invoked', false)
            ->assertJsonPath('requires_worker', true);

        Bus::assertDispatched(\App\Jobs\SoftwareCompanyLoopRunJob::class);
    }

    public function test_start_run_missing_actor_is_blocked_422_and_enqueues_nothing_over_http(): void
    {
        Bus::fake();

        $this->postJson(self::BASE.'/start-run', [], $this->headers)
            ->assertStatus(422)
            ->assertJsonPath('reason', 'operator_actor_required');

        Bus::assertNotDispatched(\App\Jobs\SoftwareCompanyLoopRunJob::class);
    }

    public function test_start_run_is_blocked_409_when_a_live_lock_holds_over_http(): void
    {
        Bus::fake();
        // Real, non-expired, non-orphaned lock (this test process' own PID/host).
        $path = $this->runner()->lockPath(self::AREA, 'dev_forge');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, (string) json_encode([
            'run_id' => 'ap790run_live',
            'host' => gethostname() ?: 'unknown',
            'pid' => getmypid() ?: 0,
            'acquired_at_epoch' => microtime(true),
            'lease_ttl_seconds' => 3600,
        ], JSON_UNESCAPED_SLASHES));

        $this->postJson(self::BASE.'/start-run', ['operator_actor' => 'vitor', 'mode' => 'execute'], $this->headers)
            ->assertStatus(409)
            ->assertJsonPath('status', 'blocked')
            ->assertJsonPath('reason', 'loop_already_running')
            ->assertJsonPath('holder.run_id', 'ap790run_live');

        // No double-launch while a run is live.
        Bus::assertNotDispatched(\App\Jobs\SoftwareCompanyLoopRunJob::class);
    }

    // ----------------------------------------------------------------- (new) done
    public function test_done_returns_only_real_merged_cycles_over_http(): void
    {
        // A real delivered cycle (merged + hash) and a merged-without-hash one that must be excluded.
        $this->appendCycleRecord(['cycle_index' => 1, 'cycle_id' => 'aesc_done_1', 'outcome' => 'merged', 'merge_performed' => true, 'merge_hash' => 'feedbed1']);
        $this->appendCycleRecord(['cycle_index' => 2, 'cycle_id' => 'aesc_done_2', 'outcome' => 'merged', 'merge_performed' => true, 'merge_hash' => '']);

        $response = $this->getJson(self::BASE.'/done', $this->headers)->assertStatus(200)
            ->assertJsonPath('schema_version', 'atlas.software_company_stewardship.loop_command_done.v1')
            ->assertJsonPath('delivered_total', 1)
            ->assertJsonPath('delivered.0.cycle_index', 1)
            ->assertJsonPath('delivered.0.merge_hash', 'feedbed1');

        // Deterministic ledger projection -> a matching If-None-Match yields a real 304.
        $etag = $response->headers->get('ETag');
        $this->getJson(self::BASE.'/done', $this->headers + ['If-None-Match' => $etag])->assertStatus(304);
    }

    // ----------------------------------------------------------------- (new) backlog
    public function test_backlog_unknown_area_returns_stable_404_over_http(): void
    {
        $this->getJson('/ai/software-company-stewardship/loop/not_a_real_area/backlog', $this->headers)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'unknown_area');
    }

    // ------------------------------------------------------------------- (a) live

    public function test_live_returns_composed_cockpit_and_run_state_over_http(): void
    {
        // Place a real pause signal so run_state reflects TRUE on-disk state through the wire.
        File::put($this->runner()->pausePath(self::AREA, 'dev_forge'), '{"operator_actor":"vitor"}');

        $response = $this->getJson(self::BASE.'/live', $this->headers)->assertStatus(200);

        $response->assertJsonPath('schema_version', 'atlas.software_company_stewardship.loop_command_live.v1')
            ->assertJsonPath('area_id', self::AREA)
            ->assertJsonPath('focus', 'dev_forge')
            ->assertJsonPath('portfolio_id', 'atlas_software_company')
            ->assertJsonPath('read_only', true)
            // The cockpit aggregate is composed in (not duplicated).
            ->assertJsonPath('cockpit.schema_version', 'atlas.software_company.product_mode_cockpit.v1')
            // run_state composes the runner's real read/path-only accessors.
            ->assertJsonStructure([
                'cockpit',
                'run_state' => [
                    'lock' => ['available', 'held', 'path'],
                    'kill_switch' => ['active', 'path'],
                    'pause' => ['active', 'path'],
                    'stewardship_recovery',
                    'scheduler_backlog',
                ],
                'surface_hash',
                'generated_at',
            ]);

        // HONESTY: the live surface reflects the TRUE on-disk signal we placed (the pause file the
        // loop reads with is_file() exists), and the runner's own path is reported, not a claim.
        $response->assertJsonPath('run_state.pause.active', true)
            ->assertJsonPath('run_state.kill_switch.active', false)
            ->assertJsonPath('run_state.pause.path', $this->runner()->pausePath(self::AREA, 'dev_forge'));

        $this->assertStringStartsWith('sha256:', (string) $response->json('surface_hash'));

        // The ETag is emitted and well-formed (the surface hash wrapped in quotes), and the response
        // is explicitly short-cache (private, max-age=5). The controller's 304 short-circuit MACHINERY
        // is proven on the `cycles` route, which composes only the deterministic ledger.
        $etag = $response->headers->get('ETag');
        $this->assertSame('"'.$response->json('surface_hash').'"', $etag);
        // Symfony normalizes the directive order alphabetically (max-age, then private).
        $this->assertSame('max-age=5, private', $response->headers->get('Cache-Control'));

        // HONESTY — a 304 for /live is NOT asserted here, and that is correct, not a gap in this surface.
        // /live composes the AP-739 ProductModeCockpitSurfaceService aggregate VERBATIM (as the contract
        // mandates: compose, never duplicate). That cockpit aggregate is itself non-deterministic across
        // identical calls — it reseeds executive recommendation IDs (exec_*/edi_*) and their derived
        // target/pack/surface hashes every call — so its surface_hash changes call-to-call and a 304 can
        // never reliably fire. This is a PRE-EXISTING upstream defect, independently reproducible:
        // tests/Feature/Ai/SoftwareCompany/ProductModeCockpitControllerTest::test_etag_supports_conditional_get
        // fails with the same 200-vs-304 today. Fixing it requires changing the cockpit/executive services
        // (out of scope for a read/command surface). Asserting a /live 304 would therefore be dishonest;
        // the surface still serves a correct ETag + 5s cache and never fabricates a stable hash.
    }

    public function test_live_unknown_area_returns_stable_404_over_http(): void
    {
        $response = $this->getJson('/ai/software-company-stewardship/loop/not_a_real_area/live', $this->headers)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'unknown_area');

        $supported = (array) $response->json('error.supported_areas');
        $this->assertContains(self::AREA, $supported);
        $this->assertContains(self::LOOP_FACTORY_AREA, $supported);
    }

    // ----------------------------------------------------------------- (b) cycles

    public function test_cycles_tails_the_real_ledger_and_filters_over_http(): void
    {
        // Three real ledger rows (one stale @50h ago) + a non-ledger health-snapshot line that
        // readLedgerRecords already filters out by schema_version.
        $this->appendCycleRecord(['cycle_index' => 1, 'cycle_id' => 'aesc_1', 'recorded_at' => now('UTC')->subHours(50)->format(\DateTimeInterface::ATOM)]);
        $this->appendCycleRecord(['cycle_index' => 2, 'cycle_id' => 'aesc_2', 'outcome' => 'merged', 'merge_performed' => true]);
        $this->appendCycleRecord(['cycle_index' => 3, 'cycle_id' => 'aesc_3', 'outcome' => 'progress']);
        File::append(
            $this->runner()->ledgerPath(self::AREA, 'dev_forge'),
            json_encode(['record_type' => 'health_snapshot', 'at' => 'x']).PHP_EOL,
        );

        $response = $this->getJson(self::BASE.'/cycles?hours=24&tail=20', $this->headers)->assertStatus(200);

        $response->assertJsonPath('schema_version', 'atlas.software_company_stewardship.loop_command_cycles.v1')
            ->assertJsonPath('area_id', self::AREA)
            ->assertJsonPath('focus', 'dev_forge')
            // Total = 3 real ledger rows (health_snapshot line is NOT a ledger record).
            ->assertJsonPath('ledger_record_count_total', 3)
            // hours=24 drops the 50h-old row → 2 returned, oldest->newest.
            ->assertJsonPath('returned_count', 2)
            ->assertJsonPath('tail', 20)
            ->assertJsonPath('hours', 24);

        $this->assertSame([2, 3], array_column((array) $response->json('cycles'), 'cycle_index'));
        $this->assertStringStartsWith('sha256:', (string) $response->json('surface_hash'));

        // ETag/304 supported over HTTP.
        $etag = $response->headers->get('ETag');
        $this->getJson(self::BASE.'/cycles?hours=24&tail=20', array_merge($this->headers, ['If-None-Match' => $etag]))
            ->assertStatus(304);
    }

    // -------------------------------------------------------- (c) operator-decision

    public function test_operator_decision_accept_returns_ap724_receipt_and_never_executes_over_http(): void
    {
        $response = $this->postJson(self::BASE.'/operator-decision', [
            'decision' => 'accept',
            'operator_actor' => 'vitor',
            'finding_hash' => 'sha256:abc123',
            'risk' => 'medium',
        ], $this->headers)->assertStatus(201);

        $response->assertJsonPath('schema_version', AreaFocusOperatorDecisionService::RECEIPT_SCHEMA)
            ->assertJsonPath('ap_contract', 'AP-724')
            ->assertJsonPath('area_id', self::AREA) // injected from the {area} path param
            ->assertJsonPath('decision', 'accept')
            // Proposal-only / operator-owned: accept unlocks the next stage, it NEVER executes.
            ->assertJsonPath('requires_owner_execution', true)
            ->assertJsonPath('executed', false)
            ->assertJsonPath('atlas_auto_decided', false)
            ->assertJsonPath('autoapproval_allowed', false)
            ->assertJsonPath('autoimplementation_allowed', false)
            ->assertJsonPath('branch_created', false)
            ->assertJsonPath('provider_invoked', false)
            ->assertJsonPath('mutates_target_repo', false)
            ->assertJsonPath('operator_owned', true)
            ->assertJsonPath('next_allowed_action', 'release_to_owner_execution_under_operator_review');
    }

    public function test_operator_decision_high_risk_accept_without_rationale_is_blocked_over_http(): void
    {
        // The load-bearing proposal-only guard is NOT bypassable over the wire.
        $this->postJson(self::BASE.'/operator-decision', [
            'decision' => 'accept',
            'operator_actor' => 'vitor',
            'finding_hash' => 'sha256:abc123',
            'risk' => 'high',
        ], $this->headers)
            ->assertStatus(422)
            ->assertJsonPath('status', 'blocked')
            ->assertJsonPath('reason', 'rationale_required_for_high_risk_accept');
    }

    // ------------------------------------------------------------- (d) run-control

    public function test_run_control_writes_real_signal_and_reflects_true_disk_state_over_http(): void
    {
        // pause → the runner's OWN pause file is created; the response reflects the TRUE post-state.
        $pause = $this->postJson(self::BASE.'/run-control', [
            'action' => 'pause',
            'operator_actor' => 'vitor',
            'reason' => 'soak window',
        ], $this->headers)->assertStatus(200);

        $pause->assertJsonPath('schema_version', 'atlas.software_company_stewardship.loop_command_run_control.v1')
            ->assertJsonPath('action', 'pause')
            ->assertJsonPath('applied', true)
            ->assertJsonPath('pause.active', true)
            ->assertJsonPath('kill_switch.active', false);

        // HONESTY: the file the runner reads with is_file() really exists now (a signal, not a claim).
        $this->assertTrue(is_file($this->runner()->pausePath(self::AREA, 'dev_forge')));
        $this->assertTrue($this->runner()->pauseStatus(self::AREA, 'dev_forge')['active']);

        // resume → the file is removed; the response (and disk) flip back to false.
        $this->postJson(self::BASE.'/run-control', [
            'action' => 'resume',
            'operator_actor' => 'vitor',
        ], $this->headers)
            ->assertStatus(200)
            ->assertJsonPath('pause.active', false);
        $this->assertFalse(is_file($this->runner()->pausePath(self::AREA, 'dev_forge')));

        // An invalid action is rejected AND writes nothing (honest-stop is not weakened).
        $this->postJson(self::BASE.'/run-control', [
            'action' => 'self_destruct',
            'operator_actor' => 'vitor',
        ], $this->headers)
            ->assertStatus(422)
            ->assertJsonPath('reason', 'invalid_action');
        $this->assertFalse(is_file($this->runner()->killSwitchPath(self::AREA, 'dev_forge')));
    }

    // -------------------------------------------------------------- (e) directive

    public function test_directive_is_never_dropped_and_is_honest_about_consumption_over_http(): void
    {
        $response = $this->postJson(self::BASE.'/directive', [
            'directive' => 'Harden the merge governor against false-merge on empty diffs.',
            'operator_actor' => 'vitor',
            'risk' => 'high',
            'target_doc' => 'atlas-autonomous-software-company-night-shift-product-mode.md',
        ], $this->headers)->assertStatus(201);

        $response->assertJsonPath('schema_version', 'atlas.software_company_stewardship.loop_command_directive.v1')
            ->assertJsonPath('persisted_to', 'operational_inbox')
            // HONESTY: never claim autonomous pickup or a merge.
            ->assertJsonPath('loop_autonomously_consumable_now', false)
            ->assertJsonPath('executed', false)
            ->assertJsonPath('provider_invoked', false)
            ->assertJsonPath('mutates_target_repo', false)
            ->assertJsonPath('auto_consumed', false)
            // The machine-readable path to make it loop-consumable names the REAL finding source + flags.
            ->assertJsonPath('to_make_loop_consumable.real_finding_source', 'canonical_doc_frontmatter (next_actions/allowed_changes)');

        $directiveId = (string) $response->json('directive_id');
        $this->assertStringStartsWith('lcd_', $directiveId);
        $this->assertContains('ATLAS_STEWARDSHIP_SCAN_CANONICAL_DOC_BACKLOG', (array) $response->json('to_make_loop_consumable.required_flags'));
        $this->assertContains('ATLAS_STEWARDSHIP_AUTONOMOUS_DOC_BACKLOG_EXECUTION', (array) $response->json('to_make_loop_consumable.required_flags'));

        // The directive was REALLY persisted to ai_inbox_items over the wire (no parallel store, never dropped).
        $itemId = (string) $response->json('inbox_item_id');
        $this->assertNotEmpty($itemId);
        $item = AiInboxItem::query()->find($itemId);
        $this->assertNotNull($item);
        $this->assertSame('loop_command_directive', $item->source_type);
        $this->assertNull($item->source_id); // UUID column — directive_id rides in the payload instead.
        $this->assertSame($directiveId, $item->payload['directive_id']);
        $this->assertFalse($item->payload['loop_autonomously_consumable_now']);
    }

    public function test_directive_missing_text_or_actor_is_blocked_and_writes_nothing_over_http(): void
    {
        $this->postJson(self::BASE.'/directive', [
            'operator_actor' => 'vitor',
        ], $this->headers)
            ->assertStatus(422)
            ->assertJsonPath('reason', 'directive_required');

        $this->postJson(self::BASE.'/directive', [
            'directive' => 'do the thing',
        ], $this->headers)
            ->assertStatus(422)
            ->assertJsonPath('reason', 'operator_actor_required');

        // Neither blocked call wrote an inbox row.
        $this->assertSame(0, AiInboxItem::query()->count());
    }
}
