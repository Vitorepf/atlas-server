<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Cognition\AcosProgram;

use App\Services\Ai\Cognition\AcosProgram\AcosMaxLedgerRotationRegistry;
use App\Services\Ai\Cognition\AcosProgram\AcosMaxMeasureSeriesRegistry;
use App\Services\Ai\Cognition\AcosProgram\AtlasNCaptureDrillService;
use RuntimeException;
use Tests\TestCase;

/**
 * TETO-01 — N-Capture Drill (freeze + guard + reader).
 *
 * Covers:
 *  - freeze payload is deterministic, author\u2260judge, has the three thresholds
 *    the plan pins (cold_start channels, bypass_forbidden, required_fields).
 *  - accepted drill: writes to the ledger, reader publishes the three times and
 *    denominators and status flips from insufficient_signal ⇒ ok.
 *  - case negativo #1 (bypass admission): admission.bypass=true + admitted=true
 *    is REFUSED with named reason `admission_via_bypass_forbidden`.
 *  - case negativo #2 (yardstick failed but admitted): REFUSED with
 *    `yardstick_failed_but_admitted`.
 *  - case negativo #3 (cold-start channel invalid): REFUSED with
 *    `cold_start_channel_invalid`.
 *  - refused engine does not enter admitted count in the reader.
 *  - series is registered in ELEV-20s registry + rotation policy declared.
 */
final class Teto01NCaptureDrillTest extends TestCase
{
    private string $tempLedger = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempLedger = tempnam(sys_get_temp_dir(), 'teto01-drill-');
        // tempnam creates the file empty; keep it empty (jsonl reader is fine).
    }

    protected function tearDown(): void
    {
        if ($this->tempLedger !== '' && is_file($this->tempLedger)) {
            @unlink($this->tempLedger);
        }
        parent::tearDown();
    }

    public function test_freeze_payload_is_deterministic_and_binds_required_fields(): void
    {
        $freeze = AtlasNCaptureDrillService::freezePayload();

        $this->assertSame('measure_freeze', $freeze['kind']);
        $this->assertSame('atlas.n_capture_drill.v1', $freeze['measure_id']);
        $this->assertSame('n_capture_drill.v1', $freeze['formula_version']);
        $this->assertNotSame($freeze['author_engine_id'], $freeze['judge_engine_id'], 'author must not equal judge');
        $this->assertSame(180, $freeze['thresholds']['days_between_drills_max']);
        $this->assertSame(['maxk02'], $freeze['thresholds']['cold_start_channels_allowed']);
        $this->assertTrue($freeze['thresholds']['bypass_forbidden']);
        $this->assertTrue($freeze['thresholds']['peek_only']);
        $this->assertContains('atlas.decide.route_regret.v2', $freeze['thresholds']['yardstick_required_series']);
        $this->assertContains('engine_id', $freeze['thresholds']['required_fields']);
        $this->assertContains('admission.cold_start_via', $freeze['thresholds']['required_fields']);

        $again = AtlasNCaptureDrillService::freezePayload();
        $this->assertSame($freeze, $again, 'freeze payload must be pure/deterministic');
    }

    public function test_accepted_drill_is_recorded_and_reader_publishes_times_and_denominators(): void
    {
        $service = new AtlasNCaptureDrillService($this->tempLedger);

        $before = $service->report();
        $this->assertSame('insufficient_signal', $before['status']);
        $this->assertSame('no_drill_in_window', $before['reason']);
        $this->assertSame(0, $before['aggregate']['drills_in_window']);

        $drill = $this->validAcceptedDrill();
        $sealed = $service->record($drill);

        $this->assertSame('atlas.acos_max.n_capture_drill.v1', $sealed['schema_version']);
        $this->assertSame('n_capture_drill.v1', $sealed['formula_version']);
        $this->assertSame($drill['engine_id'], $sealed['engine_id']);
        $this->assertTrue($sealed['admission']['admitted']);
        $this->assertSame('maxk02', $sealed['admission']['cold_start_via']);
        $this->assertFalse($sealed['admission']['bypass']);
        $this->assertSame(600, $sealed['times']['time_to_first_routed_task_seconds']);
        $this->assertSame(7200, $sealed['times']['time_to_first_proven_real_seconds']);
        $this->assertSame(3.5, $sealed['times']['hours_of_integration']);
        $this->assertSame(12, $sealed['denominators']['routed_tasks_observed']);
        $this->assertSame(4, $sealed['denominators']['proven_real_outcomes_observed']);

        $after = $service->report();
        $this->assertSame('ok', $after['status']);
        $this->assertNull($after['reason']);
        $this->assertSame(1, $after['aggregate']['drills_in_window']);
        $this->assertSame(1, $after['aggregate']['admitted_count']);
        $this->assertSame(0, $after['aggregate']['refused_count']);
        $this->assertNotNull($after['latest']);
        $this->assertSame($drill['engine_id'], $after['latest']['engine_id']);

        // ledger file exists and has one JSONL line.
        $this->assertFileExists($this->tempLedger);
        $lines = array_filter(explode("\n", (string) file_get_contents($this->tempLedger)));
        $this->assertCount(1, $lines);
    }

    public function test_case_negativo_bypass_admission_is_refused(): void
    {
        $service = new AtlasNCaptureDrillService($this->tempLedger);
        $drill = $this->validAcceptedDrill();
        $drill['admission']['bypass'] = true;

        try {
            $service->record($drill);
            $this->fail('bypass admission must be refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('admission_via_bypass_forbidden', $e->getMessage());
        }

        $violations = $service->validate($drill);
        $reasons = array_map(static fn (array $v): string => $v['reason'], $violations);
        $this->assertContains('admission_via_bypass_forbidden', $reasons);

        // ledger stays empty (nothing recorded).
        $this->assertSame('', trim((string) @file_get_contents($this->tempLedger)));
    }

    public function test_case_negativo_yardstick_failed_but_admitted_is_refused(): void
    {
        $service = new AtlasNCaptureDrillService($this->tempLedger);
        $drill = $this->validAcceptedDrill();
        $drill['yardstick']['golden_v2_passed'] = false;

        try {
            $service->record($drill);
            $this->fail('yardstick failure with admission must be refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('yardstick_failed_but_admitted', $e->getMessage());
        }
    }

    public function test_case_negativo_cold_start_channel_invalid_is_refused(): void
    {
        $service = new AtlasNCaptureDrillService($this->tempLedger);
        $drill = $this->validAcceptedDrill();
        $drill['admission']['cold_start_via'] = 'sideload';

        try {
            $service->record($drill);
            $this->fail('non-MAXK-02 cold-start channel must be refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('cold_start_channel_invalid', $e->getMessage());
        }
    }

    public function test_refused_engine_is_recorded_as_refused_not_admitted(): void
    {
        $service = new AtlasNCaptureDrillService($this->tempLedger);

        $refused = $this->validAcceptedDrill();
        $refused['engine_id'] = 'toy_engine_v0';
        $refused['yardstick']['golden_v2_passed'] = false;
        $refused['admission']['admitted'] = false;
        $refused['admission']['cold_start_via'] = null;
        $refused['admission']['reason'] = 'yardstick_below_gate';

        $sealed = $service->record($refused);
        $this->assertFalse($sealed['admission']['admitted']);

        $report = $service->report();
        $this->assertSame('ok', $report['status']);
        $this->assertSame(1, $report['aggregate']['drills_in_window']);
        $this->assertSame(0, $report['aggregate']['admitted_count']);
        $this->assertSame(1, $report['aggregate']['refused_count']);
        $this->assertSame(['toy_engine_v0' => 1], $report['aggregate']['engines']);
    }

    public function test_case_negativo_missing_required_field_is_refused(): void
    {
        $service = new AtlasNCaptureDrillService($this->tempLedger);
        $drill = $this->validAcceptedDrill();
        unset($drill['times']['hours_of_integration']);

        try {
            $service->record($drill);
            $this->fail('missing required field must be refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('drill_receipt_incomplete', $e->getMessage());
        }
    }

    public function test_series_registered_in_elev20s_registry_with_rotation_policy(): void
    {
        $registry = new AcosMaxMeasureSeriesRegistry;
        $sliceIds = $registry->sliceIds();
        $seriesIds = $registry->seriesIds();

        $this->assertContains('TETO-01', $sliceIds, 'TETO-01 must be registered as a MEDIDOR slice');
        $this->assertContains('atlas.n_capture_drill.v1', $seriesIds);

        $rotation = new AcosMaxLedgerRotationRegistry;
        $policy = $rotation->policyFor('atlas.n_capture_drill.v1');
        $this->assertNotNull($policy, 'TETO-01 series must declare rotation policy');
        $this->assertSame('append_forever', $policy['mode']);
        $this->assertSame(365, $policy['max_age_days']);
    }

    public function test_cli_command_emits_reader_payload_json(): void
    {
        $exit = $this->artisan('atlas:teto:n-capture-drill', ['--json' => true]);
        $exit->assertExitCode(0);
    }

    /** @return array<string,mixed> */
    private function validAcceptedDrill(): array
    {
        return [
            'engine_id' => 'glm_cli_v0',
            'capability_spec' => [
                'function' => 'engine',
                'verified' => true,
                'violations' => [],
            ],
            'yardstick' => [
                'golden_v2_passed' => true,
                'golden_v2_score' => 0.72,
                'regret_measure_id' => 'atlas.decide.route_regret.v2',
            ],
            'times' => [
                'time_to_first_routed_task_seconds' => 600,
                'time_to_first_proven_real_seconds' => 7200,
                'hours_of_integration' => 3.5,
            ],
            'denominators' => [
                'routed_tasks_observed' => 12,
                'proven_real_outcomes_observed' => 4,
            ],
            'admission' => [
                'admitted' => true,
                'cold_start_via' => 'maxk02',
                'bypass' => false,
                'reason' => null,
            ],
            'trigger' => 'engine_new_available',
        ];
    }
}
