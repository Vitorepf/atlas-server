<?php

namespace App\Services\Ai\Kernel\Architecture\Scanner;

use App\Support\PeeledSource;
use App\Support\RoutesApiSource;
use Illuminate\Support\Facades\File;

class SelfImprovementAudit
{
    public function __construct(private ScanPrimitivesSupport $primitives) {}

    /**
     * @return array<string,callable(): array<int,string>>
     */
    public function checks(): array
    {
        return [
            'ap41_self_improvement_architecture_validation_review' => fn (): array => $this->scanSelfImprovementArchitectureValidationReview(),
            'ap42_self_improvement_architecture_audit_schedule' => fn (): array => $this->scanSelfImprovementArchitectureAuditSchedule(),
            'ap43_self_improvement_flow_cadence_contract' => fn (): array => $this->scanSelfImprovementFlowCadenceContract(),
            'ap44_self_improvement_command_next_run_contract' => fn (): array => $this->scanSelfImprovementCommandNextRunContract(),
            'ap45_self_improvement_schedule_mcp_tool' => fn (): array => $this->scanSelfImprovementScheduleMcpTool(),
            'ap46_self_improvement_schedule_health_review' => fn (): array => $this->scanSelfImprovementScheduleHealthReview(),
            'ap47_self_improvement_schedule_health_ledger_event' => fn (): array => $this->scanSelfImprovementScheduleHealthLedgerEvent(),
            'ap48_self_improvement_schedule_replay_read_model' => fn (): array => $this->scanSelfImprovementScheduleReplayReadModel(),
            'ap49_self_improvement_schedule_replay_surfaces' => fn (): array => $this->scanSelfImprovementScheduleReplaySurfaces(),
            'ap50_self_improvement_schedule_replay_mcp_tool' => fn (): array => $this->scanSelfImprovementScheduleReplayMcpTool(),
            'ap51_self_improvement_schedule_replay_review' => fn (): array => $this->scanSelfImprovementScheduleReplayReview(),
            'ap52_self_improvement_schedule_replay_review_signal' => fn (): array => $this->scanSelfImprovementScheduleReplayReviewSignal(),
            'ap53_self_improvement_schedule_replay_review_signal_surfaces' => fn (): array => $this->scanSelfImprovementScheduleReplayReviewSignalSurfaces(),
            'ap69_self_improvement_runtime_window_contract' => fn (): array => $this->scanSelfImprovementRuntimeWindowContract(),
            'ap70_self_improvement_schedule_window_contract' => fn (): array => $this->scanSelfImprovementScheduleWindowContract(),
            'ap71_self_improvement_orchestrator_window_contract' => fn (): array => $this->scanSelfImprovementOrchestratorWindowContract(),
            'ap98_self_improvement_input_contract' => fn (): array => $this->scanSelfImprovementInputContract(),
            'ap115_self_improvement_schedule_replay_inbox_gap_finding' => fn (): array => $this->scanSelfImprovementScheduleReplayInboxGapFinding(),
            'ap116_self_improvement_schedule_replay_inbox_gap_emission' => fn (): array => $this->scanSelfImprovementScheduleReplayInboxGapEmission(),
            'ap123_self_improvement_inbox_action_replay_review' => fn (): array => $this->scanSelfImprovementInboxActionReplayReview(),
            'ap131_self_improvement_architecture_operations_review' => fn (): array => $this->scanSelfImprovementArchitectureOperationsReview(),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function scanSelfImprovementArchitectureOperationsReview(): array
    {
        $violations = [];
        $runtimePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $testPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-131-self-improvement-architecture-operations-review.md');

        $runtime = PeeledSource::read($runtimePath);
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        foreach ([
            'AtlasArchitectureOperationsCatalog $architectureOperations',
            '...$this->architectureOperationsFindings($filters)',
            'private function architectureOperationsFindings(array $filters = []): array',
            'atlas.self_improvement.architecture_operations.v1',
            'restore_architecture_operations_catalog',
            'missing_architecture_operation',
            'php artisan atlas:ai:dynamic-compute-market --provider=<provider> --domain=<domain> --flow=<flow> --json',
            'php artisan atlas:ai:self-improve --flow=provider_performance_review --hours=168 --json',
            'php artisan atlas:ai:self-improve --flow=provider_release_review --hours=168 --json',
            'php artisan atlas:ai:telemetry:cost-rates --missing --hours=168 --json',
            'php artisan atlas:ai:telemetry:cost-rates --provider=<provider> --model=<model> --input-microusd=<input> --output-microusd=<output> --json',
        ] as $token) {
            if (! str_contains($runtime, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: AP-131 Self-Improvement must review Architecture Operations catalog drift [{$token}]";
            }
        }

        foreach ([
            'test_self_improvement_detects_architecture_operations_catalog_drift',
            'AtlasArchitectureOperationsCatalog(commandsOverride:',
            'atlas.self_improvement.architecture_operations.v1',
            'restore_architecture_operations_catalog',
            'missing_architecture_operation',
            'php artisan atlas:ai:dynamic-compute-market --provider=<provider> --domain=<domain> --flow=<flow> --json',
            'php artisan atlas:ai:self-improve --flow=provider_performance_review --hours=168 --json',
            'php artisan atlas:ai:self-improve --flow=provider_release_review --hours=168 --json',
            'php artisan atlas:ai:telemetry:cost-rates --missing --hours=168 --json',
            'php artisan atlas:ai:telemetry:cost-rates --provider=<provider> --model=<model> --input-microusd=<input> --output-microusd=<output> --json',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: AP-131 Self-Improvement architecture operations review must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-131',
            'Self-Improvement Architecture Operations Review',
            'architectureOperationsFindings',
            'restore_architecture_operations_catalog',
            'ap131_self_improvement_architecture_operations_review',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-131 Self-Improvement architecture operations review must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-131-self-improvement-architecture-operations-review.md: AP-131 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanSelfImprovementInboxActionReplayReview(): array
    {
        $violations = [];
        $runtimePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $testPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-123-self-improvement-inbox-action-replay-review.md');

        $runtime = PeeledSource::read($runtimePath);
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        foreach ([
            'inboxActionReplayFindings(',
            'normalizedInboxActionFilters(',
        ] as $token) {
            if (! str_contains($runtime, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: AP-123 Self-Improvement must consume Inbox action replay gaps [{$token}]";
            }
        }

        // Pins relocated under GOD-DEBULK D3 (2026-07-23): inboxActionReplayFindings moved verbatim
        // from AtlasSelfImprovementRuntime into the Runtime/InboxActionReplaySection family class;
        // the AP-123 replay-gap invariant is unchanged, only the file moved (the facade keeps a
        // same-signature delegator).
        $inboxActionReplaySectionPath = app_path('Services/Ai/SelfImprovement/Runtime/InboxActionReplaySection.php');
        $inboxActionReplaySection = PeeledSource::read($inboxActionReplaySectionPath);
        foreach ([
            'inboxActionReportForWindow(',
            'atlas.self_improvement.inbox_action_replay_gap.v1',
            'review_patch_action_without_diff_refs',
            'record_rivals_review_action_without_scores',
            'open_reviewable_inbox_action_evidence_proposal',
            'self-improvement:inbox-action-replay:',
        ] as $token) {
            if (! str_contains($inboxActionReplaySection, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/Runtime/InboxActionReplaySection.php: AP-123 Self-Improvement must consume Inbox action replay gaps [{$token}]";
            }
        }

        foreach ([
            'test_self_improvement_detects_inbox_action_replay_patch_review_gap',
            'test_self_improvement_detects_rivals_review_action_without_scores',
            'recordInboxActionEvent(',
            'LedgerEventType::InboxActionRecorded',
            'atlas.self_improvement.inbox_action_replay_gap.v1',
            'record_rivals_review_action_without_scores',
            'open_reviewable_inbox_action_evidence_proposal',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: AP-123 Inbox action replay review must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-123',
            'Self-Improvement Inbox Action Replay Review',
            'inboxActionReplayFindings',
            'atlas.self_improvement.inbox_action_replay_gap.v1',
            'review_patch_action_without_diff_refs',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-123 Inbox action replay review must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-123-self-improvement-inbox-action-replay-review.md: AP-123 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanSelfImprovementScheduleReplayInboxGapEmission(): array
    {
        $violations = [];
        $runtimePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $testPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-116-self-improvement-schedule-replay-inbox-gap-emission-contract.md');

        $runtime = PeeledSource::read($runtimePath);
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        foreach ([
            '$item = $this->proposals->emit([',
            "'emitted_to_inbox' => \$emittedInboxItemId !== null",
            "'emitted_inbox_item_id' => \$emittedInboxItemId",
            "'emitted_inbox_item_ids' => array_values(\$emitted)",
        ] as $token) {
            if (! str_contains($runtime, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: AP-116 Self-Improvement must emit inbox-gap findings through the standard proposal/ledger path [{$token}]";
            }
        }

        foreach ([
            'test_self_improvement_emits_schedule_replay_missing_inbox_ref_proposal',
            'ProposalInboxEmitter::class',
            'emitted_to_inbox',
            'emitted_inbox_item_id',
            'emitted_inbox_item_ids',
            'restore_or_reemit_missing_self_improvement_inbox_items',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: AP-116 inbox-gap proposal emission must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-116',
            'Self-Improvement Schedule Replay Inbox Gap Emission',
            'emitted_to_inbox',
            'OPERATION_COMPLETED',
            'restore_or_reemit_missing_self_improvement_inbox_items',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-116 inbox-gap emission must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-116-self-improvement-schedule-replay-inbox-gap-emission-contract.md: AP-116 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanSelfImprovementScheduleReplayInboxGapFinding(): array
    {
        $violations = [];
        $runtimePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $testPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-115-self-improvement-schedule-replay-inbox-gap-finding-contract.md');

        $runtime = PeeledSource::read($runtimePath);
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        foreach ([
            "\$missingInboxItemIds = array_values((array) (\$report['emitted_inbox_item_missing_ids'] ?? []))",
            "'title' => 'Restaurar propostas do Inbox emitidas pelo Self-Improvement'",
            "'schema_version' => 'atlas.self_improvement.schedule_replay_inbox_gap.v1'",
            "'recommended_action' => 'restore_or_reemit_missing_self_improvement_inbox_items'",
            "'dedupe_key' => 'self-improvement:schedule-replay-inbox-gap:'.sha1",
        ] as $token) {
            if (! str_contains($runtime, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: AP-115 Self-Improvement must turn schedule replay inbox hydration gaps into reviewable findings [{$token}]";
            }
        }

        foreach ([
            'test_self_improvement_detects_schedule_replay_missing_inbox_refs',
            'recordSelfImprovementCompletion',
            'atlas.self_improvement.schedule_replay_inbox_gap.v1',
            'restore_or_reemit_missing_self_improvement_inbox_items',
            'self-improvement:schedule-replay-inbox-gap:',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: AP-115 missing inbox refs finding must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-115',
            'Self-Improvement Schedule Replay Inbox Gap Finding',
            'atlas.self_improvement.schedule_replay_inbox_gap.v1',
            'restore_or_reemit_missing_self_improvement_inbox_items',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-115 inbox gap finding must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-115-self-improvement-schedule-replay-inbox-gap-finding-contract.md: AP-115 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanSelfImprovementArchitectureValidationReview(): array
    {
        $runtimePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $testPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $domainDocsPath = base_path('docs/engineering-knowledge-base/domains/self-improvement.md');
        $kernelDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $runtime = PeeledSource::read($runtimePath);
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $domainDocs = $this->primitives->selfImprovementDomainDocumentationCorpus();
        $kernelDocs = $this->primitives->kernelDocumentationCorpus();

        foreach ([
            'AtlasAiArchitectureValidationService',
            'private readonly AtlasAiArchitectureValidationService $architectureValidation',
            '...$this->architectureValidationFindings($filters)',
            'private function architectureValidationFindings(array $filters = []): array',
            '$payload = $this->architectureValidation->payload();',
            "'type' => 'architecture_validation_ap'",
            "'dedupe_key' => 'self-improvement:architecture-validation:'",
        ] as $token) {
            if (! str_contains($runtime, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: Self-Improvement must review architecture validation from the shared service [{$token}]";
            }
        }

        foreach ([
            'test_self_improvement_detects_architecture_validation_regressions_from_shared_service',
            'AtlasAiArchitectureValidationService::class',
            "'ap40_architecture_validation_mcp_tool'",
            "'self-improvement:architecture-validation:'",
            "data_get(\$finding, 'metadata.failed_keys')",
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: Self-Improvement architecture validation finding must be tested [{$token}]";
            }
        }

        foreach ([
            'architecture validation',
            'AP41',
        ] as $token) {
            if (! str_contains($domainDocs, $token) && ! str_contains($kernelDocs, $token)) {
                $violations[] = "docs/engineering-knowledge-base: docs must describe Self-Improvement architecture validation review [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanSelfImprovementArchitectureAuditSchedule(): array
    {
        $configPath = config_path('atlas_ai.php');
        $schedulePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementScheduleService.php');
        $unitTestPath = base_path('tests/Unit/Ai/AtlasSelfImprovementScheduleServiceTest.php');
        $featureTestPath = base_path('tests/Feature/Ai/AtlasAiSelfImprovementScheduleApiTest.php');
        $observabilityTestPath = base_path('tests/Feature/Ai/AiObservabilityKernelSloTest.php');
        $domainDocsPath = base_path('docs/engineering-knowledge-base/domains/self-improvement.md');
        $kernelDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $config = File::exists($configPath) ? File::get($configPath) : '';
        $schedule = PeeledSource::read($schedulePath);
        $unitTest = File::exists($unitTestPath) ? File::get($unitTestPath) : '';
        $featureTest = File::exists($featureTestPath) ? File::get($featureTestPath) : '';
        $observabilityTest = File::exists($observabilityTestPath) ? File::get($observabilityTestPath) : '';
        $domainDocs = $this->primitives->selfImprovementDomainDocumentationCorpus();
        $kernelDocs = $this->primitives->kernelDocumentationCorpus();

        if (! str_contains($config, 'nightly_review,weekly_architecture_audit,repair_loop_review,kernel_pipeline_review,agent_behavior_review,provider_release_review,voice_realtime_review')) {
            $violations[] = 'config/atlas_ai.php: ATLAS_AI_SELF_IMPROVEMENT_FLOWS default must include architecture, repair, kernel, agent, provider release and voice reviews';
        }

        foreach ([
            "'nightly_review',\n            'weekly_architecture_audit',\n            'repair_loop_review',\n            'kernel_pipeline_review',\n            'agent_behavior_review',\n            'provider_release_review',\n            'voice_realtime_review'",
            'Restore the default nightly_review, weekly_architecture_audit, repair_loop_review, kernel_pipeline_review, agent_behavior_review, provider_release_review, and voice_realtime_review schedule.',
        ] as $token) {
            if (! str_contains($schedule, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementScheduleService.php: default schedule must include architecture audit [{$token}]";
            }
        }

        foreach ([
            'test_default_schedule_runs_nightly_architecture_repair_kernel_pipeline_agent_and_provider_release_reviews',
            "'weekly_architecture_audit'",
            'registered_command_count',
            'atlas:ai:self-improve --flow=weekly_architecture_audit --hours=24 --limit=5 --json',
        ] as $token) {
            if (! str_contains($unitTest, $token) && ! str_contains($featureTest, $token) && ! str_contains($observabilityTest, $token)) {
                $violations[] = "tests: schedule tests must lock weekly_architecture_audit in recurring self-improvement plan [{$token}]";
            }
        }

        foreach ([
            'weekly_architecture_audit',
            'AP42',
        ] as $token) {
            if (! str_contains($domainDocs, $token) && ! str_contains($kernelDocs, $token)) {
                $violations[] = "docs/engineering-knowledge-base: docs must describe scheduled architecture audit [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanSelfImprovementFlowCadenceContract(): array
    {
        $schedulePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementScheduleService.php');
        $bootstrapPath = base_path('bootstrap/app.php');
        $unitTestPath = base_path('tests/Unit/Ai/AtlasSelfImprovementScheduleServiceTest.php');
        $featureTestPath = base_path('tests/Feature/Ai/AtlasAiSelfImprovementScheduleApiTest.php');
        $observabilityTestPath = base_path('tests/Feature/Ai/AiObservabilityKernelSloTest.php');
        $domainDocsPath = base_path('docs/engineering-knowledge-base/domains/self-improvement.md');
        $kernelDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $schedule = PeeledSource::read($schedulePath);
        $bootstrap = File::exists($bootstrapPath) ? File::get($bootstrapPath) : '';
        $unitTest = File::exists($unitTestPath) ? File::get($unitTestPath) : '';
        $featureTest = File::exists($featureTestPath) ? File::get($featureTestPath) : '';
        $observabilityTest = File::exists($observabilityTestPath) ? File::get($observabilityTestPath) : '';
        $domainDocs = $this->primitives->selfImprovementDomainDocumentationCorpus();
        $kernelDocs = $this->primitives->kernelDocumentationCorpus();

        foreach ([
            '$cadence = SelfImprovementScheduleMath::cadenceForFlow($flow)',
            '$weekDay = SelfImprovementScheduleMath::weekDayForFlow($flow)',
            'public static function cadenceForFlow(string $flow): string',
            "return \$flow === 'weekly_architecture_audit' ? 'weekly' : 'daily';",
            'public static function weekDayForFlow(string $flow): ?int',
            "'cadence_counts' => SelfImprovementScheduleMath::cadenceCounts(\$commands)",
        ] as $token) {
            if (! str_contains($schedule, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementScheduleService.php: flow cadence contract must be explicit [{$token}]";
            }
        }

        foreach ([
            "\$scheduledEvent = \$schedule->command(\$selfImprovementCommand['command']);",
            "(\$selfImprovementCommand['cadence'] ?? 'daily') === 'weekly'",
            "->weeklyOn((int) (\$selfImprovementCommand['week_day'] ?? 1), \$selfImprovementCommand['time'])",
            "->dailyAt(\$selfImprovementCommand['time'])",
        ] as $token) {
            if (! str_contains($bootstrap, $token)) {
                $violations[] = "bootstrap/app.php: scheduler must respect per-flow cadence from Self-Improvement schedule contract [{$token}]";
            }
        }

        foreach ([
            'test_scheduled_commands_mark_weekly_architecture_audit_as_weekly',
            "\$this->assertSame('weekly', \$commands[0]['cadence'])",
            "\$this->assertSame(1, \$commands[0]['week_day'])",
            "assertJsonPath('cadence_counts.weekly', 1)",
            "assertJsonPath('self_improvement_schedule.cadence_counts.weekly', 1)",
        ] as $token) {
            if (! str_contains($unitTest, $token) && ! str_contains($featureTest, $token) && ! str_contains($observabilityTest, $token)) {
                $violations[] = "tests: Self-Improvement cadence contract must be locked by unit/API/observability tests [{$token}]";
            }
        }

        foreach ([
            'cadence',
            'weeklyOn',
            'AP43',
        ] as $token) {
            if (! str_contains($domainDocs, $token) && ! str_contains($kernelDocs, $token)) {
                $violations[] = "docs/engineering-knowledge-base: docs must describe Self-Improvement per-flow cadence [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanSelfImprovementCommandNextRunContract(): array
    {
        $schedulePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementScheduleService.php');
        $unitTestPath = base_path('tests/Unit/Ai/AtlasSelfImprovementScheduleServiceTest.php');
        $domainDocsPath = base_path('docs/engineering-knowledge-base/domains/self-improvement.md');
        $kernelDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $schedule = PeeledSource::read($schedulePath);
        $unitTest = File::exists($unitTestPath) ? File::get($unitTestPath) : '';
        $domainDocs = $this->primitives->selfImprovementDomainDocumentationCorpus();
        $kernelDocs = $this->primitives->kernelDocumentationCorpus();

        foreach ([
            "'next_run_at' => SelfImprovementScheduleMath::nextRunAtForCommand(",
            "'next_run_at' => \$command['next_run_at']",
            'public static function nextRunAtForCommand(',
            'while ((int) $next->dayOfWeek !== $targetWeekDay || $next->lessThanOrEqualTo($now))',
            'public static function hashableCommands(array $commands): array',
            "unset(\$command['next_run_at']);",
        ] as $token) {
            if (! str_contains($schedule, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementScheduleService.php: command next_run_at contract must be explicit and hash-stable [{$token}]";
            }
        }

        foreach ([
            "\$this->assertSame('2026-05-11T05:00:00.000000Z', \$commands[1]['next_run_at'])",
            "\$this->assertNotSame(\$first['commands'][0]['next_run_at'], \$second['commands'][0]['next_run_at'])",
            "\$this->assertSame(\$first['plan_hash'], \$second['plan_hash'])",
        ] as $token) {
            if (! str_contains($unitTest, $token)) {
                $violations[] = "tests/Unit/Ai/AtlasSelfImprovementScheduleServiceTest.php: command next_run_at must be covered, including weekly cadence and stable plan hash [{$token}]";
            }
        }

        foreach ([
            'per-command `next_run_at`',
            'AP44',
        ] as $token) {
            if (! str_contains($domainDocs, $token) && ! str_contains($kernelDocs, $token)) {
                $violations[] = "docs/engineering-knowledge-base: docs must describe Self-Improvement per-command next_run_at [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanSelfImprovementScheduleMcpTool(): array
    {
        $mcpPath = app_path('Services/Ai/AtlasOpenBrainMcpService.php');
        $testPath = base_path('tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php');
        $domainDocsPath = base_path('docs/engineering-knowledge-base/domains/self-improvement.md');
        $kernelDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $reportToolsPath = app_path('Services/Ai/OpenBrainMcp/ReportTools.php');

        $mcp = PeeledSource::read($mcpPath);
        $reportTools = PeeledSource::read($reportToolsPath);
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $domainDocs = $this->primitives->selfImprovementDomainDocumentationCorpus();
        $kernelDocs = $this->primitives->kernelDocumentationCorpus();

        // Façade keeps the tools() schema + dispatch; the handler + its schedule service
        // dependency were relocated under GOD-DEBULK D3 to OpenBrainMcp/ReportTools.
        foreach ([
            "'name' => 'atlas_self_improvement_schedule'",
            "'atlas_self_improvement_schedule' => \$this->toolResponse(\$id, \$this->reportTools->selfImprovementSchedule(\$arguments))",
            "'writes' => false",
        ] as $token) {
            if (! str_contains($mcp, $token)) {
                $violations[] = "app/Services/Ai/AtlasOpenBrainMcpService.php: Open Brain must expose Self-Improvement schedule as read-only MCP tool [{$token}]";
            }
        }

        foreach ([
            'AtlasSelfImprovementScheduleService',
            'public function selfImprovementSchedule(array $arguments): array',
            "'allowed_detail' => ['health', 'plan', 'commands']",
        ] as $token) {
            if (! str_contains($reportTools, $token)) {
                $violations[] = "app/Services/Ai/OpenBrainMcp/ReportTools.php: Open Brain must expose Self-Improvement schedule as read-only MCP tool [{$token}]";
            }
        }

        foreach ([
            'test_self_improvement_schedule_tool_exposes_recurring_health',
            'test_self_improvement_schedule_tool_rejects_invalid_detail',
            "\$this->assertContains('atlas_self_improvement_schedule'",
            "\$this->assertSame('weekly_architecture_audit', data_get(\$structured, 'schedule.commands.1.flow'))",
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php: MCP schedule tool must be covered in inventory, success and invalid-detail tests [{$token}]";
            }
        }

        foreach ([
            'atlas_self_improvement_schedule',
            'AP45',
        ] as $token) {
            if (! str_contains($domainDocs, $token) && ! str_contains($kernelDocs, $token)) {
                $violations[] = "docs/engineering-knowledge-base: docs must describe Self-Improvement schedule MCP tool [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanSelfImprovementScheduleHealthReview(): array
    {
        $runtimePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $testPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $domainDocsPath = base_path('docs/engineering-knowledge-base/domains/self-improvement.md');
        $kernelDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $runtime = PeeledSource::read($runtimePath);
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $domainDocs = $this->primitives->selfImprovementDomainDocumentationCorpus();
        $kernelDocs = $this->primitives->kernelDocumentationCorpus();

        foreach ([
            'AtlasSelfImprovementScheduleService $schedule',
            '...$this->selfImprovementScheduleFindings($filters)',
            'private function selfImprovementScheduleFindings(array $filters = []): array',
            '$health = $this->schedule->scheduleHealth();',
            "'title' => 'Corrigir schedule recorrente do Self-Improvement'",
            "'dedupe_key' => 'self-improvement:schedule-health:'",
            "'type' => 'self_improvement_schedule'",
        ] as $token) {
            if (! str_contains($runtime, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: Curator must review its recurring schedule through AtlasSelfImprovementScheduleService [{$token}]";
            }
        }

        foreach ([
            'test_self_improvement_detects_unhealthy_recurring_schedule',
            "'self-improvement:schedule-health:'.sha1('warning:registered:invalid_self_improvement_flows_configured')",
            "\$this->assertSame('Corrigir schedule recorrente do Self-Improvement'",
            "\$this->assertSame(['invalid_self_improvement_flows_configured'], data_get(\$finding, 'metadata.issues'))",
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: Self-Improvement schedule health review must be covered [{$token}]";
            }
        }

        foreach ([
            'schedule health',
            'AP46',
        ] as $token) {
            if (! str_contains($domainDocs, $token) && ! str_contains($kernelDocs, $token)) {
                $violations[] = "docs/engineering-knowledge-base: docs must describe Self-Improvement schedule health review [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanSelfImprovementScheduleHealthLedgerEvent(): array
    {
        $eventTypePath = app_path('Services/Ai/Kernel/Evidence/LedgerEventType.php');
        $runtimePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $testPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $domainDocsPath = base_path('docs/engineering-knowledge-base/domains/self-improvement.md');
        $kernelDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $eventType = PeeledSource::read($eventTypePath);
        $runtime = PeeledSource::read($runtimePath);
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $domainDocs = $this->primitives->selfImprovementDomainDocumentationCorpus();
        $kernelDocs = $this->primitives->kernelDocumentationCorpus();

        if (! str_contains($eventType, "case SelfImprovementScheduleObserved = 'SELF_IMPROVEMENT_SCHEDULE_OBSERVED';")) {
            $violations[] = 'app/Services/Ai/Kernel/Evidence/LedgerEventType.php: missing SELF_IMPROVEMENT_SCHEDULE_OBSERVED ledger event type';
        }

        foreach ([
            'LedgerEventType::SelfImprovementScheduleObserved',
            "'schedule_health' => \$this->scheduleHealthLedgerProjection(\$this->schedule->scheduleHealth())",
            'private function scheduleHealthLedgerProjection(array $health): array',
            "'health_status' => data_get(\$health, 'health.status')",
            "'scheduler_registration' => (array) (\$health['scheduler_registration'] ?? [])",
        ] as $token) {
            if (! str_contains($runtime, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: schedule health must be recorded as a dedicated ledger event [{$token}]";
            }
        }

        foreach ([
            'LedgerEventType::SelfImprovementScheduleObserved->value',
            "\$this->assertArrayHasKey('health_status', data_get(\$scheduleEvent->payload, 'schedule_health'))",
            "\$this->assertArrayHasKey('scheduler_registration', data_get(\$scheduleEvent->payload, 'schedule_health'))",
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: schedule observed ledger event must be asserted [{$token}]";
            }
        }

        foreach ([
            'SELF_IMPROVEMENT_SCHEDULE_OBSERVED',
            'AP47',
        ] as $token) {
            if (! str_contains($domainDocs, $token) && ! str_contains($kernelDocs, $token)) {
                $violations[] = "docs/engineering-knowledge-base: docs must describe schedule health ledger event [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanSelfImprovementScheduleReplayReadModel(): array
    {
        $replayPath = app_path('Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php');
        $observabilityPath = app_path('Http/Controllers/AiObservabilityController.php');
        $testPath = base_path('tests/Unit/Ai/Kernel/LedgerReplayServiceTest.php');
        $observabilityTestPath = base_path('tests/Feature/Ai/AiObservabilityKernelSloTest.php');
        $domainDocsPath = base_path('docs/engineering-knowledge-base/domains/self-improvement.md');
        $kernelDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $replay = PeeledSource::read($replayPath);
        $observability = PeeledSource::read($observabilityPath);
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $observabilityTest = File::exists($observabilityTestPath) ? File::get($observabilityTestPath) : '';
        $domainDocs = $this->primitives->selfImprovementDomainDocumentationCorpus();
        $kernelDocs = $this->primitives->kernelDocumentationCorpus();

        foreach ([
            'public function selfImprovementScheduleReportForWindow(CarbonInterface $since, ?CarbonInterface $until = null): array',
            'LedgerEventType::SelfImprovementScheduleObserved->value',
            'private function selfImprovementScheduleEventFromEvent(array $event): array',
            'private function selfImprovementScheduleEventSummary(Collection $events): array',
            "'schedule_observation_count' => \$events->count()",
            "'review_required' => \$warningCount > 0",
        ] as $token) {
            if (! str_contains($replay, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php: schedule observed events must have a replay read model [{$token}]";
            }
        }

        foreach ([
            '$selfImprovementScheduleReplay = $ledgerReplay->selfImprovementScheduleReportForWindow($since)',
            "'self_improvement_schedule_replay' => \$selfImprovementScheduleReplay",
        ] as $token) {
            if (! str_contains($observability, $token)) {
                $violations[] = "app/Http/Controllers/AiObservabilityController.php: observability must expose schedule replay read model [{$token}]";
            }
        }

        foreach ([
            'test_self_improvement_schedule_window_report_projects_schedule_health_events',
            'recordSelfImprovementScheduleEvent',
            "\$this->assertSame(2, \$report['schedule_observation_count'])",
            "\$this->assertTrue(\$report['review_required'])",
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/LedgerReplayServiceTest.php: schedule replay read model must be covered [{$token}]";
            }
        }

        foreach ([
            'self_improvement_schedule_replay',
        ] as $token) {
            if (! str_contains($observabilityTest, $token)) {
                $violations[] = "tests/Feature/Ai/AiObservabilityKernelSloTest.php: observability schedule replay must be covered [{$token}]";
            }
        }

        foreach ([
            'schedule replay',
            'AP48',
        ] as $token) {
            if (! str_contains($domainDocs, $token) && ! str_contains($kernelDocs, $token)) {
                $violations[] = "docs/engineering-knowledge-base: docs must describe Self-Improvement schedule replay read model [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanSelfImprovementScheduleReplaySurfaces(): array
    {
        $commandPath = app_path('Console/Commands/AtlasAiSelfImprovementScheduleReportCommand.php');
        $controllerPath = app_path('Http/Controllers/AtlasAiSelfImprovementScheduleReportController.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiSelfImprovementScheduleReportCommandTest.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiSelfImprovementScheduleReportApiTest.php');
        $domainDocsPath = base_path('docs/engineering-knowledge-base/domains/self-improvement.md');
        $kernelDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $command = PeeledSource::read($commandPath);
        $controller = PeeledSource::read($controllerPath);
        $routes = RoutesApiSource::read();
        $commandTest = File::exists($commandTestPath) ? File::get($commandTestPath) : '';
        $apiTest = File::exists($apiTestPath) ? File::get($apiTestPath) : '';
        $domainDocs = $this->primitives->selfImprovementDomainDocumentationCorpus();
        $kernelDocs = $this->primitives->kernelDocumentationCorpus();

        foreach ([
            'atlas:ai:self-improvement-schedule-report',
            '$replay->selfImprovementScheduleReportForWindow(now()->subHours($hours))',
            "'self_improvement_schedule_replay' => \$report",
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiSelfImprovementScheduleReportCommand.php: CLI report must expose schedule replay read model [{$token}]";
            }
        }

        foreach ([
            'class AtlasAiSelfImprovementScheduleReportController extends Controller',
            '$replay->selfImprovementScheduleReportForWindow(now()->subHours($hours))',
            "'self_improvement_schedule_replay' => \$report",
        ] as $token) {
            if (! str_contains($controller, $token)) {
                $violations[] = "app/Http/Controllers/AtlasAiSelfImprovementScheduleReportController.php: API report must expose schedule replay read model [{$token}]";
            }
        }

        foreach ([
            'AtlasAiSelfImprovementScheduleReportController',
            "Route::get('/ai/self-improvement/schedule/report', AtlasAiSelfImprovementScheduleReportController::class)",
        ] as $token) {
            if (! str_contains($routes, $token)) {
                $violations[] = "routes/api.php: schedule replay report route must be registered [{$token}]";
            }
        }

        foreach ([
            'test_command_summarizes_self_improvement_schedule_replay_as_json',
            'test_command_reports_unavailable_when_ledger_table_is_missing',
            'atlas:ai:self-improvement-schedule-report',
        ] as $token) {
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiSelfImprovementScheduleReportCommandTest.php: CLI schedule report must be covered [{$token}]";
            }
        }

        foreach ([
            'test_schedule_report_api_returns_window_summary',
            'test_schedule_report_api_requires_atlas_token',
            'test_schedule_report_api_returns_service_unavailable_when_ledger_table_is_missing',
            '/ai/self-improvement/schedule/report',
        ] as $token) {
            if (! str_contains($apiTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiSelfImprovementScheduleReportApiTest.php: API schedule report must be covered [{$token}]";
            }
        }

        foreach ([
            'self-improvement-schedule-report',
            'AP49',
        ] as $token) {
            if (! str_contains($domainDocs, $token) && ! str_contains($kernelDocs, $token)) {
                $violations[] = "docs/engineering-knowledge-base: docs must describe Self-Improvement schedule replay surfaces [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanSelfImprovementScheduleReplayMcpTool(): array
    {
        $mcpPath = app_path('Services/Ai/AtlasOpenBrainMcpService.php');
        $testPath = base_path('tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php');
        $domainDocsPath = base_path('docs/engineering-knowledge-base/domains/self-improvement.md');
        $kernelDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $reportToolsPath = app_path('Services/Ai/OpenBrainMcp/ReportTools.php');

        $mcp = PeeledSource::read($mcpPath);
        $reportTools = PeeledSource::read($reportToolsPath);
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $domainDocs = $this->primitives->selfImprovementDomainDocumentationCorpus();
        $kernelDocs = $this->primitives->kernelDocumentationCorpus();

        // Façade keeps the tools() schema + dispatch; the handler + its ledger-replay
        // dependency were relocated under GOD-DEBULK D3 to OpenBrainMcp/ReportTools.
        foreach ([
            "'name' => 'atlas_self_improvement_schedule_report'",
            "'atlas_self_improvement_schedule_report' => \$this->toolResponse(\$id, \$this->reportTools->selfImprovementScheduleReport(\$arguments))",
            "'writes' => false",
        ] as $token) {
            if (! str_contains($mcp, $token)) {
                $violations[] = "app/Services/Ai/AtlasOpenBrainMcpService.php: MCP must expose schedule replay read model through atlas_self_improvement_schedule_report [{$token}]";
            }
        }

        foreach ([
            'AtlasLedgerReplayService $ledgerReplay',
            'public function selfImprovementScheduleReport(array $arguments): array',
            '$this->ledgerReplay->selfImprovementScheduleReportForWindow(now()->subHours($hours))',
        ] as $token) {
            if (! str_contains($reportTools, $token)) {
                $violations[] = "app/Services/Ai/OpenBrainMcp/ReportTools.php: MCP must expose schedule replay read model through atlas_self_improvement_schedule_report [{$token}]";
            }
        }

        foreach ([
            'test_self_improvement_schedule_report_tool_exposes_replay_read_model',
            "\$this->assertContains('atlas_self_improvement_schedule_report'",
            "\$this->assertSame('atlas_self_improvement_schedule_report', \$structured['tool'])",
            "\$this->assertFalse(\$structured['writes'])",
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php: MCP schedule report must be covered [{$token}]";
            }
        }

        foreach ([
            'atlas_self_improvement_schedule_report',
            'AP50',
        ] as $token) {
            if (! str_contains($domainDocs, $token) && ! str_contains($kernelDocs, $token)) {
                $violations[] = "docs/engineering-knowledge-base: docs must describe Self-Improvement schedule replay MCP tool [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanSelfImprovementScheduleReplayReview(): array
    {
        $runtimePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $testPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $domainDocsPath = base_path('docs/engineering-knowledge-base/domains/self-improvement.md');
        $kernelDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $runtime = PeeledSource::read($runtimePath);
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $domainDocs = $this->primitives->selfImprovementDomainDocumentationCorpus();
        $kernelDocs = $this->primitives->kernelDocumentationCorpus();

        foreach ([
            '...$this->selfImprovementScheduleReplayFindings($hours, $filters)',
            'private function selfImprovementScheduleReplayFindings(int $hours, array $filters = []): array',
            '$this->replay->selfImprovementScheduleReportForWindow(now()->subHours($hours))',
            "'title' => 'Investigar drift recorrente no schedule do Self-Improvement'",
            "'dedupe_key' => 'self-improvement:schedule-replay:'.sha1",
            "'review_required' => (bool) (\$report['review_required'] ?? false)",
        ] as $token) {
            if (! str_contains($runtime, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: Self-Improvement must consume schedule replay read model for drift review [{$token}]";
            }
        }

        foreach ([
            'test_self_improvement_detects_schedule_replay_drift',
            'recordSelfImprovementScheduleObservation',
            "'self-improvement:schedule-replay:'.sha1('1:invalid_self_improvement_flows_configured')",
            "\$this->assertSame('Investigar drift recorrente no schedule do Self-Improvement', \$finding['title'])",
            "\$this->assertSame(1, data_get(\$finding, 'metadata.warning_count'))",
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: schedule replay drift review must be covered [{$token}]";
            }
        }

        foreach ([
            'schedule replay drift',
            'AP51',
        ] as $token) {
            if (! str_contains($domainDocs, $token) && ! str_contains($kernelDocs, $token)) {
                $violations[] = "docs/engineering-knowledge-base: docs must describe Self-Improvement schedule replay drift review [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanSelfImprovementScheduleReplayReviewSignal(): array
    {
        $replayPath = app_path('Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php');
        $runtimePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $unitTestPath = base_path('tests/Unit/Ai/Kernel/LedgerReplayServiceTest.php');
        $runtimeTestPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $domainDocsPath = base_path('docs/engineering-knowledge-base/domains/self-improvement.md');
        $kernelDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $replay = PeeledSource::read($replayPath);
        $runtime = PeeledSource::read($runtimePath);
        $unitTest = File::exists($unitTestPath) ? File::get($unitTestPath) : '';
        $runtimeTest = File::exists($runtimeTestPath) ? File::get($runtimeTestPath) : '';
        $domainDocs = $this->primitives->selfImprovementDomainDocumentationCorpus();
        $kernelDocs = $this->primitives->kernelDocumentationCorpus();

        foreach ([
            '$reviewSignal = $this->selfImprovementScheduleReviewSignal($events, $warningCount, $issueCounts)',
            "'review_signal' => \$reviewSignal",
            'private function selfImprovementScheduleReviewSignal(Collection $events, int $warningCount, array $issueCounts): array',
            "'recommended_action' => 'open_reviewable_self_improvement_schedule_proposal'",
        ] as $token) {
            if (! str_contains($replay, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php: schedule replay must publish canonical review_signal [{$token}]";
            }
        }

        foreach ([
            "\$reviewSignal = (array) (\$report['review_signal'] ?? [])",
            "! (bool) (\$reviewSignal['review_required'] ?? false)",
            "'review_signal' => \$reviewSignal",
        ] as $token) {
            if (! str_contains($runtime, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: Curator must consume canonical schedule replay review_signal [{$token}]";
            }
        }

        foreach ([
            "data_get(\$report, 'review_signal.status')",
            "data_get(\$report, 'review_signal.severity')",
            "data_get(\$report, 'review_signal.recommended_action')",
        ] as $token) {
            if (! str_contains($unitTest, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/LedgerReplayServiceTest.php: schedule replay review_signal must be covered [{$token}]";
            }
        }

        foreach ([
            "data_get(\$finding, 'metadata.review_signal.status')",
            "data_get(\$finding, 'metadata.review_signal.severity')",
            "data_get(\$finding, 'metadata.review_signal.recommended_action')",
        ] as $token) {
            if (! str_contains($runtimeTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: Curator review_signal consumption must be covered [{$token}]";
            }
        }

        foreach ([
            'schedule replay review_signal',
            'AP52',
        ] as $token) {
            if (! str_contains($domainDocs, $token) && ! str_contains($kernelDocs, $token)) {
                $violations[] = "docs/engineering-knowledge-base: docs must describe canonical schedule replay review_signal [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanSelfImprovementScheduleReplayReviewSignalSurfaces(): array
    {
        $commandPath = app_path('Console/Commands/AtlasAiSelfImprovementScheduleReportCommand.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiSelfImprovementScheduleReportCommandTest.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiSelfImprovementScheduleReportApiTest.php');
        $observabilityTestPath = base_path('tests/Feature/Ai/AiObservabilityKernelSloTest.php');
        $mcpTestPath = base_path('tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php');
        $domainDocsPath = base_path('docs/engineering-knowledge-base/domains/self-improvement.md');
        $kernelDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $command = PeeledSource::read($commandPath);
        $commandTest = File::exists($commandTestPath) ? File::get($commandTestPath) : '';
        $apiTest = File::exists($apiTestPath) ? File::get($apiTestPath) : '';
        $observabilityTest = File::exists($observabilityTestPath) ? File::get($observabilityTestPath) : '';
        $mcpTest = File::exists($mcpTestPath) ? File::get($mcpTestPath) : '';
        $domainDocs = $this->primitives->selfImprovementDomainDocumentationCorpus();
        $kernelDocs = $this->primitives->kernelDocumentationCorpus();

        foreach ([
            "data_get(\$report, 'review_signal.status'",
            "data_get(\$report, 'review_signal.severity'",
            "data_get(\$report, 'review_signal.recommended_action'",
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiSelfImprovementScheduleReportCommand.php: human schedule report must render review_signal [{$token}]";
            }
        }

        foreach ([
            "data_get(\$payload, 'self_improvement_schedule_replay.review_signal.status')",
            "data_get(\$payload, 'self_improvement_schedule_replay.review_signal.severity')",
            "data_get(\$payload, 'self_improvement_schedule_replay.review_signal.recommended_action')",
            'test_command_human_output_includes_schedule_replay_review_signal',
        ] as $token) {
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiSelfImprovementScheduleReportCommandTest.php: CLI schedule report review_signal must be covered [{$token}]";
            }
        }

        foreach ([
            "self_improvement_schedule_replay.review_signal.status', 'warning'",
            "self_improvement_schedule_replay.review_signal.severity', 'medium'",
            "self_improvement_schedule_replay.review_signal.recommended_action', 'open_reviewable_self_improvement_schedule_proposal'",
        ] as $token) {
            if (! str_contains($apiTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiSelfImprovementScheduleReportApiTest.php: API schedule report review_signal must be covered [{$token}]";
            }
        }

        foreach ([
            "self_improvement_schedule_replay.review_signal.status', 'ok'",
            "self_improvement_schedule_replay.review_signal.severity', 'none'",
            "self_improvement_schedule_replay.review_signal.recommended_action', 'none'",
        ] as $token) {
            if (! str_contains($observabilityTest, $token)) {
                $violations[] = "tests/Feature/Ai/AiObservabilityKernelSloTest.php: Observability schedule replay review_signal must be covered [{$token}]";
            }
        }

        foreach ([
            "data_get(\$structured, 'self_improvement_schedule_replay.review_signal.status')",
            "data_get(\$structured, 'self_improvement_schedule_replay.review_signal.severity')",
            "data_get(\$structured, 'self_improvement_schedule_replay.review_signal.recommended_action')",
        ] as $token) {
            if (! str_contains($mcpTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php: MCP schedule report review_signal must be covered [{$token}]";
            }
        }

        foreach ([
            'review_signal surface parity',
            'AP53',
        ] as $token) {
            if (! str_contains($domainDocs, $token) && ! str_contains($kernelDocs, $token)) {
                $violations[] = "docs/engineering-knowledge-base: docs must describe schedule replay review_signal surface parity [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanSelfImprovementRuntimeWindowContract(): array
    {
        $inputPath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementInput.php');
        $runtimePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $testPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $input = PeeledSource::read($inputPath);
        $runtime = File::exists($runtimePath) ? File::get($runtimePath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();

        foreach ([
            'public const DEFAULT_REVIEW_WINDOW_HOURS = 24',
            'public const MAX_AUTONOMOUS_REVIEW_WINDOW_HOURS = 168',
            'public const MAX_FINDINGS_PER_RUN = 20',
            'int $hours = self::DEFAULT_REVIEW_WINDOW_HOURS',
            'private readonly AtlasSelfImprovementInput $input',
            '$this->input->reviewWindowHours($hours)',
            '$this->input->findingsLimit($limit)',
        ] as $token) {
            if (! str_contains($runtime, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: Self-Improvement autonomous runtime limits must be explicit [{$token}]";
            }
        }

        foreach ([
            'final class AtlasSelfImprovementInput',
            'public const DEFAULT_FINDINGS_LIMIT = 5',
            'public function reviewWindowHours(',
            'public function findingsLimit(',
            'public function runtimeOptions(',
            'AtlasSelfImprovementRuntime::MAX_AUTONOMOUS_REVIEW_WINDOW_HOURS',
            'AtlasSelfImprovementRuntime::MAX_FINDINGS_PER_RUN',
        ] as $token) {
            if (! str_contains($input, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementInput.php: Self-Improvement input contract is incomplete [{$token}]";
            }
        }

        if (str_contains($runtime, 'min(168, $hours)') || str_contains($runtime, 'min(20, $limit)')) {
            $violations[] = 'app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: Self-Improvement runtime must not use magic numeric limits for hours/limit';
        }

        foreach ([
            'test_nightly_review_uses_explicit_autonomous_window_and_limit_contract',
            'test_command_plan_only_uses_shared_self_improvement_input_contract',
            'AtlasSelfImprovementRuntime::MAX_AUTONOMOUS_REVIEW_WINDOW_HOURS',
            'AtlasSelfImprovementRuntime::MAX_FINDINGS_PER_RUN',
            'AtlasSelfImprovementInput::DEFAULT_FINDINGS_LIMIT',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: Self-Improvement runtime window contract must be covered [{$token}]";
            }
        }

        foreach ([
            'self-improvement runtime window contract',
            'AP-69',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe Self-Improvement runtime window contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanSelfImprovementScheduleWindowContract(): array
    {
        $schedulePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementScheduleService.php');
        $testPath = base_path('tests/Unit/Ai/AtlasSelfImprovementScheduleServiceTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $schedule = File::exists($schedulePath) ? File::get($schedulePath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();

        foreach ([
            'AtlasSelfImprovementRuntime::DEFAULT_REVIEW_WINDOW_HOURS',
            'private readonly AtlasSelfImprovementInput $input',
            '$this->input->reviewWindowHours(',
            '$this->input->findingsLimit(',
        ] as $token) {
            if (! str_contains($schedule, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementScheduleService.php: schedule must reuse Self-Improvement runtime limits [{$token}]";
            }
        }

        if (str_contains($schedule, 'min(168,') || str_contains($schedule, 'min(20,')) {
            $violations[] = 'app/Services/Ai/SelfImprovement/AtlasSelfImprovementScheduleService.php: schedule must not duplicate Self-Improvement numeric limits';
        }

        foreach ([
            'use App\Services\Ai\SelfImprovement\AtlasSelfImprovementRuntime;',
            'AtlasSelfImprovementRuntime::MAX_AUTONOMOUS_REVIEW_WINDOW_HOURS',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/AtlasSelfImprovementScheduleServiceTest.php: schedule runtime limit reuse must be covered [{$token}]";
            }
        }

        foreach ([
            'self-improvement schedule window contract',
            'AP-70',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe Self-Improvement schedule window contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanSelfImprovementOrchestratorWindowContract(): array
    {
        $orchestratorPath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementOrchestrator.php');
        $testPath = base_path('tests/Unit/Ai/AtlasSelfImprovementOrchestratorTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $orchestrator = File::exists($orchestratorPath) ? File::get($orchestratorPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();

        foreach ([
            'AtlasSelfImprovementRuntime::DEFAULT_REVIEW_WINDOW_HOURS',
            'private readonly AtlasSelfImprovementInput $input',
            '$this->input->runtimeOptions(',
            'AtlasSelfImprovementInput::DEFAULT_FINDINGS_LIMIT',
        ] as $token) {
            if (! str_contains($orchestrator, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementOrchestrator.php: orchestrator must reuse Self-Improvement runtime limits [{$token}]";
            }
        }

        if (str_contains($orchestrator, 'min(168,') || str_contains($orchestrator, 'min(20,')) {
            $violations[] = 'app/Services/Ai/SelfImprovement/AtlasSelfImprovementOrchestrator.php: orchestrator must not duplicate Self-Improvement numeric limits';
        }

        foreach ([
            'test_flow_plan_reuses_runtime_window_and_limit_contract',
            'AtlasSelfImprovementRuntime::MAX_AUTONOMOUS_REVIEW_WINDOW_HOURS',
            'AtlasSelfImprovementRuntime::MAX_FINDINGS_PER_RUN',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/AtlasSelfImprovementOrchestratorTest.php: orchestrator runtime limit reuse must be covered [{$token}]";
            }
        }

        foreach ([
            'self-improvement orchestrator window contract',
            'AP-71',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe Self-Improvement orchestrator window contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanSelfImprovementInputContract(): array
    {
        $inputPath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementInput.php');
        $commandPath = app_path('Console/Commands/AtlasAiSelfImproveCommand.php');
        $runtimePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $orchestratorPath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementOrchestrator.php');
        $schedulePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementScheduleService.php');
        $testPath = base_path('tests/Unit/Ai/AtlasSelfImprovementInputTest.php');
        $runtimeTestPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $input = PeeledSource::read($inputPath);
        $command = File::exists($commandPath) ? File::get($commandPath) : '';
        $runtime = PeeledSource::read($runtimePath);
        $orchestrator = PeeledSource::read($orchestratorPath);
        $schedule = PeeledSource::read($schedulePath);
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $runtimeTest = File::exists($runtimeTestPath) ? File::get($runtimeTestPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();

        foreach ([
            'final class AtlasSelfImprovementInput',
            'public const DEFAULT_FINDINGS_LIMIT = 5',
            'public function reviewWindowHours(',
            'public function findingsLimit(',
            'public function runtimeOptions(',
            'AtlasSelfImprovementRuntime::DEFAULT_REVIEW_WINDOW_HOURS',
            'AtlasSelfImprovementRuntime::MAX_AUTONOMOUS_REVIEW_WINDOW_HOURS',
            'AtlasSelfImprovementRuntime::MAX_FINDINGS_PER_RUN',
        ] as $token) {
            if (! str_contains($input, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementInput.php: self-improvement input contract is incomplete [{$token}]";
            }
        }

        foreach ([
            'AtlasSelfImprovementInput $input',
            '$input->runtimeOptions([',
            "'hours' => \$this->option('hours')",
            "'limit' => \$this->option('limit')",
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiSelfImproveCommand.php: command must use self-improvement input contract [{$token}]";
            }
        }

        if (str_contains($command, "(int) \$this->option('hours')") || str_contains($command, "(int) \$this->option('limit')")) {
            $violations[] = 'app/Console/Commands/AtlasAiSelfImproveCommand.php: command must not cast hours/limit outside AtlasSelfImprovementInput';
        }

        foreach ([
            'private readonly AtlasSelfImprovementInput $input',
            '$this->input->reviewWindowHours($hours)',
            '$this->input->findingsLimit($limit)',
        ] as $token) {
            if (! str_contains($runtime, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: runtime must use self-improvement input contract [{$token}]";
            }
        }

        foreach ([
            'private readonly AtlasSelfImprovementInput $input',
            '$this->input->runtimeOptions([',
            'AtlasSelfImprovementInput::DEFAULT_FINDINGS_LIMIT',
        ] as $token) {
            if (! str_contains($orchestrator, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementOrchestrator.php: orchestrator must use self-improvement input contract [{$token}]";
            }
        }

        foreach ([
            'private readonly AtlasSelfImprovementInput $input',
            '$this->input->reviewWindowHours(',
            '$this->input->findingsLimit(',
            'AtlasSelfImprovementInput::DEFAULT_FINDINGS_LIMIT',
        ] as $token) {
            if (! str_contains($schedule, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementScheduleService.php: schedule must use self-improvement input contract [{$token}]";
            }
        }

        foreach ([
            'test_normalizes_self_improvement_runtime_options',
            'AtlasSelfImprovementInput::DEFAULT_FINDINGS_LIMIT',
            'AtlasSelfImprovementRuntime::MAX_AUTONOMOUS_REVIEW_WINDOW_HOURS',
            'AtlasSelfImprovementRuntime::MAX_FINDINGS_PER_RUN',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/AtlasSelfImprovementInputTest.php: self-improvement input contract must be covered [{$token}]";
            }
        }

        if (! str_contains($runtimeTest, 'test_command_plan_only_uses_shared_self_improvement_input_contract')) {
            $violations[] = 'tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: command normalization must be covered by self-improvement input contract test';
        }

        foreach ([
            'self-improvement input contract',
            'AP-98',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe self-improvement input contract [{$token}]";
            }
        }

        return $violations;
    }
}
