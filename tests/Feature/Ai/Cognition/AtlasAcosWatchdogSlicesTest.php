<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Cognition;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Cognition\AtlasCognitionEvidenceResolver;
use App\Services\Ai\Cognition\AtlasCognitionScoreCardService;
use App\Services\Ai\Cognition\Watchdog\AtlasAcosWatchdogHealthService;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheckRegistry;
use App\Services\Ai\Compounding\AtlasLearningRecallUseLiftService;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AtlasAcosWatchdogSlicesTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach ([
            'ai_rag_feedback_events',
            'ai_run_outcomes',
            'atlas_long_horizon_compaction_receipts',
            'atlas_long_horizon_continuation_packs',
            'atlas_ledger_events',
            'atlas_memory_entry_usages',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_feedback_health_command_alerts_on_low_volume_and_exposes_raw_window_counts(): void
    {
        (require database_path('migrations/2026_05_17_180000_create_ai_compounding_engineering_intelligence_tables.php'))->up();

        $exit = Artisan::call('atlas:context:feedback-health', ['--json' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertSame('atlas.context.feedback_health.v1', $payload['schema_version']);
        $this->assertTrue($payload['alert']);
        $this->assertSame(0, $payload['window']['total_event_count']);
        $this->assertContains('total_event_count_below_floor', $payload['blocking']);
    }

    public function test_compaction_soak_watch_is_not_ready_without_live_receipts(): void
    {
        (require database_path('migrations/2026_05_19_040000_create_atlas_long_horizon_continuation_pack_and_compaction_receipt_tables.php'))->up();
        (require database_path('migrations/2026_07_11_171500_add_context_retention_score_to_compaction_receipts.php'))->up();

        $exit = Artisan::call('atlas:compaction:soak-watch', ['--json' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertSame('atlas.compaction.soak_watch.v1', $payload['schema_version']);
        $this->assertFalse($payload['ready_to_enforce']);
        $this->assertSame(0, $payload['window']['compaction_count']);
        $this->assertContains('compaction_volume_below_floor', $payload['blocking']);
    }

    public function test_learning_cadence_report_exposes_pinned_thresholds_and_alerts_without_live_data(): void
    {
        $lift = new class
        {
            public function report(): array
            {
                return [
                    'status' => 'insufficient_live_ab_evidence',
                    'measurement' => [
                        'with_recalled_memory' => ['case_count' => 0],
                        'without_recalled_memory' => ['case_count' => 0],
                        'measurement_ready' => false,
                    ],
                ];
            }
        };
        $this->instance(AtlasLearningRecallUseLiftService::class, $lift);

        $report = app(AtlasAcosWatchdogHealthService::class)->learningCadenceReport();

        $this->assertSame('alert', $report['status']);
        $this->assertSame(168, $report['thresholds']['negative_feedback_max_age_hours']);
        $this->assertSame(48, $report['thresholds']['aemor_source_max_age_hours']);
        $this->assertSame(72, $report['thresholds']['ai_run_outcome_max_age_hours']);
        $this->assertContains('last_negative_feedback_stale_or_missing', $report['blocking']);
    }

    public function test_scorecard_stability_records_blocked_ledger_event_when_pipeline_is_not_green(): void
    {
        $this->migrateLedger();
        $scorecard = new class
        {
            public function build(): array
            {
                return [
                    'scorecard_hash' => 'hash-red',
                    'score' => ['dimensions' => ['pipeline' => ['score_out_of_10' => 9.7]]],
                    'subsystems' => [[
                        'acronym' => 'AURG',
                        'pipeline_status' => AtlasCognitionScoreCardService::STATUS_PARTIAL,
                    ]],
                ];
            }
        };
        $this->instance(AtlasCognitionScoreCardService::class, $scorecard);

        $report = app(AtlasAcosWatchdogHealthService::class)->pipelineStabilityReport();

        $this->assertSame('alert', $report['status']);
        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => LedgerEventType::OperationBlocked->value,
            'scope_type' => 'acos_watchdog',
            'scope_id' => 'pip-08.scorecard_stability',
        ]);
    }

    public function test_lift_cycle_closure_records_blocker_snapshot_to_evidence_ledger(): void
    {
        $this->migrateLedger();
        (require database_path('migrations/2026_05_17_180000_create_ai_compounding_engineering_intelligence_tables.php'))->up();

        $lift = new class
        {
            public function report(): array
            {
                return [
                    'schema_version' => AtlasLearningRecallUseLiftService::SCHEMA_VERSION,
                    'status' => 'insufficient_live_ab_evidence',
                    'measurement' => [
                        'with_recalled_memory' => ['case_count' => 0],
                        'without_recalled_memory' => ['case_count' => 0],
                        'measurement_ready' => false,
                        'blockers' => ['insufficient_memory_recall_use_cases'],
                    ],
                ];
            }
        };
        $this->instance(AtlasLearningRecallUseLiftService::class, $lift);

        $report = app(AtlasAcosWatchdogHealthService::class)->liftCycleClosureReport();

        $this->assertSame('alert', $report['status']);
        $this->assertSame('insufficient_live_ab_evidence', $report['lift_status']);
        $this->assertContains('insufficient_memory_recall_use_cases', $report['blocking']);
        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => LedgerEventType::OperationBlocked->value,
            'scope_type' => 'acos_watchdog',
            'scope_id' => 'ope-08.lift_cycle_closure',
        ]);
        $event = AtlasLedgerEvent::query()->where('scope_id', 'ope-08.lift_cycle_closure')->first();
        $this->assertSame(['insufficient_memory_recall_use_cases'], $event->payload['blockers']);
    }

    public function test_scorecard_receipts_diagnosis_flags_stale_partial_receipts(): void
    {
        $scorecard = new class
        {
            public function build(): array
            {
                return [
                    'subsystems' => [[
                        'acronym' => 'AURG',
                        'service_class' => 'App\\Services\\Ai\\Reality\\AtlasRealityGraphStatusService',
                        'pipeline_status' => AtlasCognitionScoreCardService::STATUS_PARTIAL,
                    ]],
                ];
            }
        };
        $resolver = new class extends AtlasCognitionEvidenceResolver
        {
            public function resolvePipelineDiagnosis(?string $serviceClass): array
            {
                return [
                    'status' => AtlasCognitionEvidenceResolver::STATUS_PARTIAL,
                    'reason' => 'green_receipt_missing',
                    'latest_receipt_age_days' => 9.5,
                ];
            }
        };
        $this->instance(AtlasCognitionScoreCardService::class, $scorecard);
        $this->instance(AtlasCognitionEvidenceResolver::class, $resolver);

        $report = app(AtlasAcosWatchdogHealthService::class)->scorecardReceiptsDiagnosisReport();

        $this->assertSame('alert', $report['status']);
        $this->assertSame(1, $report['partial_count']);
        $this->assertSame(1, $report['stale_partial_count']);
        $this->assertContains('pipeline_partial_stale_after_mint_window', $report['blocking']);
    }

    public function test_engineering_enforce_readiness_command_returns_not_ready_without_storage_receipt_shortcuts(): void
    {
        $exit = Artisan::call('atlas:engineering:enforce-readiness', ['--json' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertSame('atlas.engineering.enforce_readiness.v1', $payload['schema_version']);
        $this->assertSame('not_ready', $payload['status']);
        $this->assertFalse($payload['ready_to_enforce']);
        $this->assertStringNotContainsString('elite-compaction', json_encode($payload['sources']));
    }

    public function test_app_provider_registers_all_requested_onda_four_checks(): void
    {
        $ids = app(AtlasWatchdogCheckRegistry::class)->ids();

        foreach ([
            'mem-09.memory_quality',
            'fee-13.learning_cadence',
            'rag-10.aurg_coverage',
            'rag-12.rag_dimension',
            'com-10.context_feedback_health',
            'cpt-09.compaction_soak',
            'pip-08.scorecard_stability',
            'ope-08.lift_cycle_closure',
            'ope-10.scorecard_receipts_diagnosis',
            'eng-11.enforce_readiness',
        ] as $expected) {
            $this->assertContains($expected, $ids);
        }
    }

    private function migrateLedger(): void
    {
        Schema::dropIfExists('atlas_ledger_events');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_05_19_050000_extend_atlas_ledger_events_with_timeline_fields.php'))->up();
    }
}
