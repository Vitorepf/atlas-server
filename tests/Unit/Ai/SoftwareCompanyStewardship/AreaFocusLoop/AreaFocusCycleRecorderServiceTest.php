<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\NightShift\AreaFocusLoopReadModelService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusCycleRecorderService;
use Tests\TestCase;

/**
 * Read-only durable cycle recorder tests (AP-720). Storage is redirected to a
 * temp dir so no test touches real storage; reports are injected via the
 * `report` override so the read model is never executed and ids stay deterministic.
 */
class AreaFocusCycleRecorderServiceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_afc_'.uniqid('', true);
        @mkdir($this->tmp, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->tmp.'/*') as $f) {
            @unlink((string) $f);
        }
        @rmdir($this->tmp);
        parent::tearDown();
    }

    private function recorder(): AreaFocusCycleRecorderService
    {
        $svc = app(AreaFocusCycleRecorderService::class);
        $svc->setStorageRootForTesting($this->tmp);

        return $svc;
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function report(array $overrides = []): array
    {
        return array_merge([
            'schema_version' => AreaFocusLoopReadModelService::REPORT_SCHEMA,
            'status' => 'ready',
            'area_id' => 'agentic_engineering_os',
            'report_hash' => 'sha256:'.str_repeat('a', 64),
            'findings' => [
                ['finding_hash' => 'sha256:f1', 'route' => 'atlas_dev', 'requires_branch_isolation' => true, 'operator_decision_required' => true, 'severity' => 'medium'],
            ],
            'morning_inbox' => ['schema_version' => 'atlas.night_shift.morning_inbox.v1', 'decision_count' => 1, 'items' => [['finding_hash' => 'sha256:f1']]],
            'routing_summary' => ['self_directed_evolution' => 0, 'atlas_dev' => 1, 'atlas_forge' => 0, 'queued' => 0, 'inbox_only' => 0],
        ], $overrides);
    }

    public function test_record_appends_and_replays_by_cycle_id(): void
    {
        $recorder = $this->recorder();
        $cycle = $recorder->record(['report' => $this->report()]);

        $this->assertSame(AreaFocusCycleRecorderService::CYCLE_SCHEMA, $cycle['schema_version']);
        $this->assertStringStartsWith('afc_', $cycle['cycle_id']);
        $this->assertSame('agentic_engineering_os', $cycle['area_id']);
        $this->assertStringStartsWith('sha256:', $cycle['findings_hash']);
        $this->assertStringStartsWith('sha256:', $cycle['inbox_hash']);
        $this->assertStringStartsWith('sha256:', $cycle['work_orders_hash']);
        $this->assertStringStartsWith('sha256:', $cycle['cycle_hash']);
        $this->assertArrayHasKey('generated_at', $cycle);
        $this->assertArrayHasKey('recorded_at', $cycle);
        $this->assertNotEmpty($cycle['validation_refs']);

        $replayed = $recorder->replay($cycle['cycle_id']);
        $this->assertNotNull($replayed);
        $this->assertSame($cycle, $replayed);

        $this->assertFileExists($recorder->cycleFilePath('agentic_engineering_os'));
    }

    public function test_deterministic_cycle_id_and_idempotent_append(): void
    {
        $recorder = $this->recorder();
        $a = $recorder->record(['report' => $this->report()]);
        $b = $recorder->record(['report' => $this->report()]);

        $this->assertSame($a['cycle_id'], $b['cycle_id']);
        $this->assertSame($a['cycle_hash'], $b['cycle_hash']);
        // Idempotent: only one line persisted.
        $lines = file($recorder->cycleFilePath('agentic_engineering_os'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertCount(1, $lines);
    }

    public function test_different_reports_produce_different_cycles(): void
    {
        $recorder = $this->recorder();
        $recorder->record(['report' => $this->report()]);
        $recorder->record(['report' => $this->report(['report_hash' => 'sha256:'.str_repeat('b', 64)])]);

        $list = $recorder->listCycles('agentic_engineering_os');
        $this->assertSame(2, $list['cycle_count']);
        $this->assertSame(0, $list['corrupted_line_count']);
    }

    public function test_no_secrets_in_persisted_payload(): void
    {
        $recorder = $this->recorder();
        $cycle = $recorder->record([
            'report' => $this->report(),
            'api_secret' => 'supersecret-value-XYZ',
            'auth_token' => 'token-should-never-persist',
            'gap_read_model' => ['candidates' => [['title' => 'gap-with-supersecret-value-XYZ']]],
            'hours' => 24,
            'limit' => 5,
        ]);

        // Whitelisted scalars survive; secret keys do not.
        $this->assertArrayNotHasKey('api_secret', $cycle['input_digest']);
        $this->assertArrayNotHasKey('auth_token', $cycle['input_digest']);
        $this->assertSame(24, $cycle['input_digest']['hours']);
        $this->assertSame(5, $cycle['input_digest']['limit']);
        $this->assertTrue($cycle['input_digest']['overrides']['gap_read_model']);

        $raw = (string) file_get_contents($recorder->cycleFilePath('agentic_engineering_os'));
        $this->assertStringNotContainsString('supersecret-value-XYZ', $raw);
        $this->assertStringNotContainsString('token-should-never-persist', $raw);
        $this->assertFalse($cycle['claim_policy']['secrets_in_payload']);
        $this->assertFalse($cycle['claim_policy']['mutates_target_repo']);
    }

    public function test_corrupted_lines_are_skipped_and_counted(): void
    {
        $recorder = $this->recorder();
        $cycle = $recorder->record(['report' => $this->report()]);

        // Inject a malformed line + a JSON line without cycle_id.
        $path = $recorder->cycleFilePath('agentic_engineering_os');
        file_put_contents($path, "this is not json\n".json_encode(['no' => 'cycle_id']).PHP_EOL, FILE_APPEND);

        $list = $recorder->listCycles('agentic_engineering_os');
        $this->assertSame(1, $list['cycle_count']);
        $this->assertSame(2, $list['corrupted_line_count']);

        // Replay still finds the valid cycle despite corruption around it.
        $this->assertNotNull($recorder->replay($cycle['cycle_id']));
    }

    public function test_replay_unknown_cycle_returns_null(): void
    {
        $this->assertNull($this->recorder()->replay('afc_does_not_exist'));
    }

    public function test_record_runs_read_model_when_no_report_override(): void
    {
        $recorder = $this->recorder();
        $cycle = $recorder->record(['area_id' => 'agentic_engineering_os', 'limit' => 3]);

        $this->assertSame('agentic_engineering_os', $cycle['area_id']);
        $this->assertContains($cycle['report_status'], ['ready', 'partial', 'blocked']);
        $this->assertSame(AreaFocusLoopReadModelService::REPORT_SCHEMA, $cycle['report_schema_version']);
        $this->assertNotNull($recorder->replay($cycle['cycle_id']));
    }
}
