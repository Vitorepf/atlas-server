<?php

namespace Tests\Feature\Ai\Cognitive;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AtlasFailureCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_05_07_160000_create_failure_signatures_table.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('failure_repetition_alerts');
        Schema::dropIfExists('failure_diversity_metrics');
        Schema::dropIfExists('failure_signatures');
        Schema::dropIfExists('atlas_ledger_events');

        parent::tearDown();
    }

    public function test_failure_command_records_lists_computes_diversity_and_acknowledges_alert(): void
    {
        for ($i = 0; $i < 3; $i++) {
            Artisan::call('atlas:failure', [
                'action' => 'record',
                'subject' => 'runtime failed while executing provider',
                '--domain' => 'programming',
                '--json' => true,
            ]);
        }

        $recorded = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('recorded', $recorded['status']);
        $this->assertSame('triggered', data_get($recorded, 'alert.status'));
        $this->assertSame(3, AtlasLedgerEvent::query()->where('event_type', LedgerEventType::FailureSignatureRecorded->value)->count());
        $this->assertSame(1, AtlasLedgerEvent::query()->where('event_type', LedgerEventType::FailureRepetitionAlert->value)->count());

        Artisan::call('atlas:failure', ['action' => 'recent', '--domain' => 'programming', '--json' => true]);
        $recent = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(3, $recent['signatures']);

        Artisan::call('atlas:failure', ['action' => 'diversity', '--domain' => 'programming', '--json' => true]);
        $diversity = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('computed', $diversity['status']);
        $this->assertSame(1, $diversity['unique_signatures']);

        Artisan::call('atlas:failure', ['action' => 'alerts', '--json' => true]);
        $alerts = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(1, $alerts['alerts']);

        Artisan::call('atlas:failure', [
            'action' => 'ack',
            'subject' => (string) $alerts['alerts'][0]['id'],
            '--reflection' => 'Vou revisar o runtime antes de repetir.',
            '--json' => true,
        ]);
        $ack = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('acknowledged', data_get($ack, 'alert.alert_status'));
    }

    public function test_failure_review_triages_test_suite_report_without_auto_correction(): void
    {
        $path = storage_path('framework/testing/failure-review-tests-'.Str::uuid().'.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([
            'tests' => [
                [
                    'name' => 'Tests\\Feature\\ExternalDatabaseTest',
                    'status' => 'failed',
                    'message' => 'SQLSTATE[HY000] [2002] Connection refused while connecting to mysql',
                ],
                [
                    'name' => 'Tests\\Unit\\ContractTest',
                    'status' => 'failed',
                    'message' => 'Failed asserting that false is true.',
                ],
                [
                    'name' => 'Tests\\Unit\\GreenTest',
                    'status' => 'passed',
                    'message' => '',
                ],
            ],
            'history' => [
                ['week' => '2026-W23', 'failed' => 7],
                ['week' => '2026-W24', 'failed' => 4],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        try {
            Artisan::call('atlas:failure', [
                'action' => 'review',
                '--domain' => 'programming',
                '--test-report' => $path,
                '--json' => true,
            ]);
            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame('planned', $payload['status']);
            $this->assertSame('triaged', data_get($payload, 'test_suite_triage.status'));
            $this->assertSame(3, data_get($payload, 'test_suite_triage.total_tests_seen'));
            $this->assertSame(2, data_get($payload, 'test_suite_triage.failed_tests_seen'));
            $this->assertSame(1, data_get($payload, 'test_suite_triage.counts.environmental'));
            $this->assertSame(1, data_get($payload, 'test_suite_triage.counts.real_failure'));
            $this->assertSame('decreasing', data_get($payload, 'test_suite_triage.trend.status'));
            $this->assertFalse((bool) data_get($payload, 'test_suite_triage.claim_policy.auto_corrects_tests'));
            $this->assertFalse((bool) data_get($payload, 'test_suite_triage.claim_policy.auto_quarantines_tests'));
            $this->assertTrue((bool) data_get($payload, 'rules.environmental_quarantine_requires_operator_review'));
        } finally {
            @File::delete($path);
        }
    }
}
