<?php

namespace Tests\Feature\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasExternalGraphHarnessService;
use App\Services\Ai\Kernel\Architecture\AtlasProviderReleaseIntelligenceService;
use App\Services\Ai\Kernel\Architecture\AtlasProviderReleaseSourceRegistry;
use App\Services\Ai\Kernel\Architecture\KernelArchitectureStaticScanner;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Kernel\Evidence\LedgerProjectionRegistry;
use Tests\TestCase;

class KernelBypassRegressionTest extends TestCase
{
    public function test_surface_provider_bypass_scan_flags_direct_provider_execution(): void
    {
        $path = app_path('Services/Ai/Surface/ForbiddenSurfaceProviderBypass.php');

        $this->withTemporaryFile($path, <<<'PHP'
<?php

namespace App\Services\Ai\Surface;

use App\Services\Ai\Provider\Drivers\ClaudeCliProvider;

class ForbiddenSurfaceProviderBypass
{
    public function render(ClaudeCliProvider $provider): mixed
    {
        return $provider->execute([]);
    }
}
PHP, function () use ($path): void {
            $violations = $this->violationsFor('ap1_surface_provider_bypass');

            $this->assertNotEmpty($violations);
            $this->assertStringContainsString(basename($path), implode("\n", $violations));
            $this->assertStringContainsString('provider->execute(', implode("\n", $violations));
        });
    }

    public function test_surface_context_bypass_scan_flags_direct_context_pack_building(): void
    {
        $path = app_path('Services/Ai/Surface/ForbiddenSurfaceContextBypass.php');

        $this->withTemporaryFile($path, <<<'PHP'
<?php

namespace App\Services\Ai\Surface;

use App\Services\Ai\Context\AiContextPackBuilder;

class ForbiddenSurfaceContextBypass
{
    public function normalize(AiContextPackBuilder $builder): array
    {
        return ['context_pack' => $builder];
    }
}
PHP, function () use ($path): void {
            $violations = $this->violationsFor('ap2_surface_context_bypass');

            $this->assertNotEmpty($violations);
            $this->assertStringContainsString(basename($path), implode("\n", $violations));
            $this->assertStringContainsString('AiContextPackBuilder', implode("\n", $violations));
        });
    }

    public function test_provider_driver_identity_bypass_scan_flags_direct_real_provider_wrapper_instantiation(): void
    {
        $path = app_path('Services/Ai/Provider/Drivers/ForbiddenProviderDriverBypass.php');

        $this->withTemporaryFile($path, <<<'PHP'
<?php

namespace App\Services\Ai\Provider\Drivers;

use App\Services\Ai\ClaudeCliProvider;

class ForbiddenProviderDriverBypass
{
    public function build(): ClaudeCliProvider
    {
        return new ClaudeCliProvider;
    }
}
PHP, function () use ($path): void {
            $violations = $this->violationsFor('ap12_provider_driver_identity_bypass');

            $this->assertNotEmpty($violations);
            $this->assertStringContainsString(basename($path), implode("\n", $violations));
            $this->assertStringContainsString('new ClaudeCliProvider', implode("\n", $violations));
        });
    }

    public function test_kernel_pipeline_contract_scan_flags_provider_execution_inside_pipeline(): void
    {
        $path = app_path('Services/Ai/Kernel/Pipeline/ForbiddenPipelineProviderBypass.php');

        $this->withTemporaryFile($path, <<<'PHP'
<?php

namespace App\Services\Ai\Kernel\Pipeline;

use App\Services\Ai\Kernel\Provider\ProviderDriver;

class ForbiddenPipelineProviderBypass
{
    public function run(ProviderDriver $provider): mixed
    {
        return $provider->execute([]);
    }
}
PHP, function () use ($path): void {
            $violations = $this->violationsFor('ap17_kernel_pipeline_contract');

            $this->assertNotEmpty($violations);
            $this->assertStringContainsString(basename($path), implode("\n", $violations));
            $this->assertStringContainsString('ProviderDriver', implode("\n", $violations));
        });
    }

    public function test_kernel_pipeline_contract_scan_flags_direct_ledger_writes_from_command_surface(): void
    {
        $path = app_path('Console/Commands/ForbiddenKernelPipelineLedgerWriteCommand.php');

        $this->withTemporaryFile($path, <<<'PHP'
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ForbiddenKernelPipelineLedgerWriteCommand extends Command
{
    protected $signature = 'atlas:forbidden-kernel-pipeline-ledger-write';

    public function handle(): int
    {
        $this->ledger->recordKernelPipelineAccepted([]);

        return self::SUCCESS;
    }
}
PHP, function () use ($path): void {
            $violations = $this->violationsFor('ap17_kernel_pipeline_contract');

            $this->assertNotEmpty($violations);
            $this->assertStringContainsString(basename($path), implode("\n", $violations));
            $this->assertStringContainsString('KernelPipelineAuditService', implode("\n", $violations));
        });
    }

    public function test_curator_graph_rag_promotion_remains_proposal_only_and_fail_closed(): void
    {
        $runtime = file_get_contents(app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php'));
        $ap = file_get_contents(base_path('docs/ap/AP-683-local-rag-graph-promotion-review.md'));

        $this->assertSame([], $this->violationsFor('ap683_local_rag_graph_promotion_review'));
        $this->assertStringContainsString("'status' => 'proposal_only'", $runtime);
        $this->assertStringContainsString("'auto_apply' => false", $runtime);
        $this->assertStringContainsString("'auto_promotion_allowed' => false", $runtime);
        $this->assertStringContainsString('nao aplicar policy patch automaticamente', $ap);
        $this->assertStringContainsString('auto_promotion_allowed', $ap);
    }

    public function test_curator_voice_production_promotion_remains_human_review_only(): void
    {
        $runtime = file_get_contents(app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php'));
        $scanner = file_get_contents(app_path('Services/Ai/Kernel/Architecture/KernelArchitectureStaticScanner.php'));
        $ap = file_get_contents(base_path('docs/ap/AP-687-voice-realtime-production-promotion-gate.md'));

        $this->assertSame([], $this->violationsFor('ap687_voice_realtime_production_promotion_gate'));
        $this->assertStringContainsString('auto_promotion_allowed', $runtime);
        $this->assertStringContainsString('auto_promotion_allowed=false', $ap);
        $this->assertStringContainsString('human_review_required=true', $ap);
        $this->assertStringContainsString('sdk_probe_import_safe', $scanner);
        $this->assertStringContainsString('sdk_imported=false', $ap);
        $this->assertStringContainsString('import_probe_only=true', $ap);
    }

    public function test_predictive_failure_governance_requires_explicit_target_and_partial_boundary(): void
    {
        $flow = file_get_contents(app_path('Services/Ai/Learning/PredictiveFailure/PredictiveFailureFlow.php'));
        $command = file_get_contents(app_path('Console/Commands/AtlasPredictCommand.php'));
        $ap = file_get_contents(base_path('docs/ap/AP-170-cognitive-predictive-failure-insertion.md'));

        $this->assertSame([], $this->violationsFor('ap170_predictive_failure_governance_contract'));
        $this->assertStringContainsString("'specific_target_required' => true", $flow);
        $this->assertStringContainsString("'random_frustration_allowed' => false", $flow);
        $this->assertStringContainsString("'daily_plan_auto_insert_allowed' => false", $flow);
        $this->assertStringContainsString('predictive_failure_storage_unavailable', $flow);
        $this->assertStringContainsString('run_migrations_before_predictive_failure', $flow);
        $this->assertStringContainsString('predictive_failure_subject_required', $command);
        $this->assertStringContainsString('alvo explicito obrigatorio', $ap);
        $this->assertStringContainsString('Service-level guard tambem exige alvo explicito', $ap);
    }

    public function test_predictive_failure_governance_scan_flags_missing_explicit_target_doctrine(): void
    {
        $path = base_path('docs/ap/AP-170-cognitive-predictive-failure-insertion.md');
        $original = file_get_contents($path);
        $mutated = str_replace('alvo explicito obrigatorio', 'target concreto obrigatorio', $original);

        $this->withTemporaryFileContents($path, $mutated, function (): void {
            $violations = $this->violationsFor('ap170_predictive_failure_governance_contract');

            $this->assertNotEmpty($violations);
            $this->assertStringContainsString('alvo explicito obrigatorio', implode("\n", $violations));
        });
    }

    public function test_agent_behavior_curator_governance_cannot_auto_apply_behavior_changes(): void
    {
        $runtime = file_get_contents(app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php'));
        $ap = file_get_contents(base_path('docs/ap/AP-162-agent-behavior-proposal-governance.md'));

        $this->assertSame([], $this->violationsFor('ap162_agent_behavior_proposal_governance'));
        $this->assertStringContainsString("'auto_apply_behavior_change' => false", $runtime);
        $this->assertStringContainsString("'auto_apply' => false", $runtime);
        $this->assertStringContainsString('open_reviewable_agent_behavior_quality_proposal', $runtime);
        $this->assertStringContainsString('auto_apply_behavior_change=false', $ap);
    }

    public function test_ledger_projection_registry_keeps_known_source_events_and_identity_keys(): void
    {
        $report = app(LedgerProjectionRegistry::class)->complianceReport();
        $projectionIds = $report['projection_ids'];
        $knownEvents = array_map(fn (LedgerEventType $event): string => $event->value, LedgerEventType::cases());

        $this->assertSame([], $this->violationsFor('ap141_ledger_projection_registry_contract'));
        $this->assertSame('atlas.ledger_projection_registry.v1', $report['schema_version']);
        $this->assertContains('ai_traces', $projectionIds);
        $this->assertContains('atlas_engineering_runs', $projectionIds);
        $this->assertContains('atlas_tool_runs', $projectionIds);

        foreach ($report['projections'] as $projection) {
            $this->assertNotEmpty($projection['identity_keys'], $projection['id'].' must keep identity keys.');
            $this->assertNotEmpty($projection['required_columns'], $projection['id'].' must keep required columns.');
            $this->assertSame([], array_values(array_diff($projection['source_events'], $knownEvents)));
        }
    }

    public function test_ledger_projection_curator_and_inbox_actions_remain_reviewable(): void
    {
        $runtime = file_get_contents(app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php'));
        $mcp = file_get_contents(app_path('Services/Ai/AtlasOpenBrainMcpService.php'));

        $this->assertSame([], $this->violationsFor('ap142_ledger_projection_inbox_action'));
        $this->assertSame([], $this->violationsFor('ap143_ledger_projection_curator_action_emission'));
        $this->assertStringContainsString('run_ledger_projection', $runtime);
        $this->assertStringContainsString('open_reviewable_ledger_projection_backfill_proposal', $runtime);
        $this->assertStringContainsString('atlas_ledger_projection_health', $mcp);
    }

    public function test_inbox_action_evidence_replay_and_mcp_report_stay_ledger_backed(): void
    {
        $replay = file_get_contents(app_path('Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php'));
        $mcp = file_get_contents(app_path('Services/Ai/AtlasOpenBrainMcpService.php'));

        $this->assertSame([], $this->violationsFor('ap120_inbox_action_evidence_ledger_contract'));
        $this->assertSame([], $this->violationsFor('ap121_inbox_action_replay_read_model'));
        $this->assertSame([], $this->violationsFor('ap122_inbox_action_mcp_report'));
        $this->assertStringContainsString('LedgerEventType::InboxActionRecorded', $replay);
        $this->assertStringContainsString('inboxActionReportForWindow', $replay);
        $this->assertStringContainsString('open_reviewable_inbox_action_evidence_proposal', $replay);
        $this->assertStringContainsString('atlas_inbox_action_report', $mcp);
    }

    public function test_provider_release_intelligence_stays_anti_wrapper_and_proposal_only(): void
    {
        $payload = app(AtlasProviderReleaseIntelligenceService::class)->review([
            'provider' => 'anthropic',
            'title' => 'Anthropic Finance Agents',
            'url' => 'https://www.anthropic.com/news/finance-agents',
            'type' => 'vertical_agents',
            'domain' => ['finance'],
            'capability' => ['pitch_builder'],
            'connector' => ['factset'],
        ]);
        $sourceRegistry = app(AtlasProviderReleaseSourceRegistry::class)->summary(['provider' => 'anthropic']);
        $docs = file_get_contents(base_path('docs/engineering-knowledge-base/atlas-ai-provider-evolution-intelligence.md'));

        $this->assertSame([], $this->violationsFor('ap178_provider_release_anti_wrapper_contract'));
        $this->assertSame('atlas.provider_release.anti_wrapper_contract.v1', data_get($payload, 'anti_wrapper_contract.schema_version'));
        $this->assertContains('direct_provider_channel_as_primary_product', data_get($payload, 'anti_wrapper_contract.forbidden_paths'));
        $this->assertFalse(data_get($payload, 'absorption_plan.promotion_rules.may_call_provider_vertical_directly'));
        $this->assertFalse(data_get($payload, 'absorption_plan.promotion_rules.may_store_provider_credentials'));
        $this->assertFalse(data_get($payload, 'absorption_plan.promotion_gate.provider_direct_channel_allowed_now'));
        $this->assertSame('atlas.provider_release.promotion_review_packet.v1', data_get($payload, 'absorption_plan.promotion_gate.review_packet.schema_version'));
        $this->assertTrue(data_get($payload, 'absorption_plan.promotion_gate.review_packet.required_decision_receipt'));
        $this->assertContains('change_atlas_decide_routing_policy', data_get($payload, 'absorption_plan.promotion_gate.review_packet.forbidden_until_review'));
        $this->assertFalse(data_get($payload, 'curator_proposal.auto_apply'));
        $this->assertSame('approve_or_reject_provider_release_absorption', data_get($payload, 'curator_proposal.promotion_review_ref.required_human_decision'));
        $this->assertFalse(data_get($sourceRegistry, 'guardrails.network_fetching_enabled'));
        $this->assertSame('create_dedicated_provider_release_fetch_runtime_ap_before_any_activation', data_get($sourceRegistry, 'future_activation_review_contract.next_action'));
        $this->assertStringContainsString('nunca canal direto', $docs);
    }

    public function test_external_graph_harness_stays_read_only_without_provider_or_runtime_promotion(): void
    {
        $contract = app(AtlasExternalGraphHarnessService::class)->contract();
        $validation = app(AtlasExternalGraphHarnessService::class)->validateCandidate([
            'schema_version' => 'atlas.external_graph_candidate.v1',
            'source_tool' => 'graphify',
            'source_tool_version' => '0.4.0',
            'source_archive_hash' => str_repeat('a', 64),
            'scan_root' => 'docs/engineering-knowledge-base',
            'generated_at' => '2026-05-09T00:00:00Z',
            'privacy_class' => 'engineering_internal',
            'review_state' => 'candidate',
            'nodes' => [
                [
                    'id' => 'n1',
                    'label' => 'Kernel Pipeline',
                    'kind' => 'service',
                    'source_refs' => [
                        [
                            'path' => 'docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md',
                            'line_start' => 1,
                            'line_end' => 2,
                        ],
                    ],
                    'metadata' => [
                        'provider_prompt' => 'inject into provider',
                        'memory_write' => true,
                    ],
                ],
            ],
            'edges' => [],
        ]);
        $ap = file_get_contents(base_path('docs/ap/AP-684-graphify-external-graph-harness.md'));

        $this->assertSame([], $this->violationsFor('ap684_external_graph_harness_contract'));
        $this->assertSame('candidate_validation_no_runtime_no_writes', $contract['mode']);
        $this->assertFalse(data_get($contract, 'guardrails.provider_calls_enabled'));
        $this->assertFalse(data_get($contract, 'review_only_constraints.runtime_promotion_allowed'));
        $this->assertFalse(data_get($contract, 'review_only_constraints.provider_prompt_injection_allowed'));
        $this->assertContains('provider_prompt_injection', data_get($contract, 'review_only_constraints.forbidden_uses'));
        $this->assertSame('rejected', $validation['status']);
        $this->assertContains('forbidden_candidate_key:nodes.0.metadata.provider_prompt', $validation['errors']);
        $this->assertContains('forbidden_candidate_key:nodes.0.metadata.memory_write', $validation['errors']);
        $this->assertStringContainsString('provider call exige AP/policy posterior', $ap);
    }

    public function test_voice_python_runtime_boundary_scan_flags_provider_shell_and_raw_audio_escape(): void
    {
        $path = base_path('runtimes/python/voice_realtime/atlas_voice_agent/forbidden_provider_shell_escape.py');

        $this->withTemporaryFile($path, <<<'PY'
import openai
import subprocess


def leak_raw_audio(audio_bytes):
    subprocess.run(["python", "-c", "print('tool escape')"])
    with open("raw_audio.wav", "w") as handle:
        handle.write(audio_bytes)
    return openai.OpenAI()
PY, function () use ($path): void {
            $violations = $this->violationsFor('ap686_voice_realtime_python_runtime_boundary_contract');
            $joined = implode("\n", $violations);

            $this->assertNotEmpty($violations);
            $this->assertStringContainsString(basename($path), $joined);
            $this->assertStringContainsString('direct_provider_sdk_import', $joined);
            $this->assertStringContainsString('shell_escape', $joined);
            $this->assertStringContainsString('raw_audio_file_write', $joined);
        });
    }

    public function test_runtime_language_boundary_scan_flags_python_go_and_swift_runtime_escapes(): void
    {
        $this->withTemporaryFiles([
            app_path('Http/Controllers/ForbiddenRuntimeAggregateController.php') => <<<'PHP'
<?php

namespace App\Http\Controllers;

class ForbiddenRuntimeAggregateController
{
    public function __invoke(): string|false|null
    {
        return shell_exec('python -c "import networkx"');
    }
}
PHP,
            app_path('Console/Commands/ForbiddenRuntimeAggregateCommand.php') => <<<'PHP'
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ForbiddenRuntimeAggregateCommand extends Command
{
    protected $signature = 'atlas:forbidden-runtime-aggregate';

    public function handle(): int
    {
        exec('go run ./services/go-edge/postback-ingestor');

        return self::SUCCESS;
    }
}
PHP,
            app_path('Jobs/ForbiddenSwiftAggregateJob.php') => <<<'PHP'
<?php

namespace App\Jobs;

class ForbiddenSwiftAggregateJob
{
    public function handle(): void
    {
        passthru('swift run ScreenCaptureKitProbe');
    }
}
PHP,
        ], function (): void {
            $violations = $this->violationsFor('ap201_runtime_language_boundary_contract');
            $joined = implode("\n", $violations);

            $this->assertNotEmpty($violations);
            $this->assertStringContainsString('ForbiddenRuntimeAggregateController.php', $joined);
            $this->assertStringContainsString('ForbiddenRuntimeAggregateCommand.php', $joined);
            $this->assertStringContainsString('ForbiddenSwiftAggregateJob.php', $joined);
            $this->assertStringContainsString('python_ai_data', $joined);
            $this->assertStringContainsString('go_edge', $joined);
            $this->assertStringContainsString('swift_native_mac', $joined);
        });
    }

    public function test_self_improvement_curator_flows_stay_review_only_without_auto_apply(): void
    {
        $runtime = file_get_contents(app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php'));
        $runtimeTest = file_get_contents(base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php'));
        $ap = file_get_contents(base_path('docs/ap/AP-162-agent-behavior-proposal-governance.md'));

        $this->assertSame([], $this->violationsFor('ap162_agent_behavior_proposal_governance'));
        $this->assertStringContainsString("'self_improvement.agent_behavior_review' =>", $runtime);
        $this->assertStringContainsString("'self_improvement.provider_release_review' =>", $runtime);
        $this->assertStringContainsString("'self_improvement.voice_realtime_review' =>", $runtime);
        $this->assertStringContainsString("'available_actions' => \$availableActions", $runtime);
        $this->assertStringContainsString("'auto_apply_behavior_change' => false", $runtime);
        $this->assertStringContainsString("'requires_operator_review' => true", $runtime);
        $this->assertStringContainsString("'requires_architecture_validate' => true", $runtime);
        $this->assertStringContainsString("'critical_behavior_change_requires_human_review' => true", $runtime);
        $this->assertStringNotContainsString("'auto_apply_behavior_change' => true", $runtime);
        $this->assertStringNotContainsString("'critical_behavior_change_requires_human_review' => false", $runtime);
        $this->assertStringContainsString('test_self_improvement_emits_agent_behavior_replay_proposal_with_review_governance', $runtimeTest);
        $this->assertStringContainsString('auto_apply_behavior_change=false', $ap);
    }

    public function test_inbox_action_replay_and_mcp_surfaces_stay_evidence_ledger_backed(): void
    {
        $replay = file_get_contents(app_path('Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php'));
        $mcp = file_get_contents(app_path('Services/Ai/AtlasOpenBrainMcpService.php'));
        $mcpTest = file_get_contents(base_path('tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php'));

        $this->assertSame([], $this->violationsFor('ap120_inbox_action_evidence_ledger_contract'));
        $this->assertSame([], $this->violationsFor('ap121_inbox_action_replay_read_model'));
        $this->assertSame([], $this->violationsFor('ap122_inbox_action_mcp_report'));
        $this->assertStringContainsString('public function inboxActionReportForWindow(CarbonInterface $since, ?CarbonInterface $until = null, array $filters = []): array', $replay);
        $this->assertStringContainsString('LedgerEventType::InboxActionRecorded->value', $replay);
        $this->assertStringContainsString('normalizedInboxActionFilters(', $replay);
        $this->assertStringContainsString('matchesInboxActionFilters(', $replay);
        $this->assertStringContainsString("'open_reviewable_inbox_action_evidence_proposal'", $replay);
        $this->assertStringContainsString("'atlas_inbox_action_report' => \$this->toolResponse(\$id, \$this->inboxActionReport(\$arguments))", $mcp);
        $this->assertStringContainsString('$this->ledgerReplay->inboxActionReportForWindow(', $mcp);
        $this->assertStringContainsString('recordInboxActionForMcp(', $mcpTest);
        $this->assertStringContainsString('atlas_inbox_action_report', $mcpTest);
        $this->assertStringContainsString('LedgerEventType::InboxActionRecorded', $mcpTest);
    }

    public function test_inbox_action_report_cli_and_api_surfaces_use_shared_replay_contract(): void
    {
        $command = file_get_contents(app_path('Console/Commands/AtlasAiInboxActionReportCommand.php'));
        $controller = file_get_contents(app_path('Http/Controllers/AtlasAiInboxActionReportController.php'));
        $routes = file_get_contents(base_path('routes/api.php'));
        $commandTest = file_get_contents(base_path('tests/Feature/Ai/AtlasAiInboxActionReportCommandTest.php'));
        $apiTest = file_get_contents(base_path('tests/Feature/Ai/AtlasAiInboxActionReportApiTest.php'));

        $this->assertSame([], $this->violationsFor('ap125_inbox_action_report_surfaces'));
        $this->assertStringContainsString('inboxActionReportForWindow(now()->subHours($hours), filters: $filters)', $command);
        $this->assertStringContainsString('class AtlasAiInboxActionReportController', $controller);
        $this->assertStringContainsString('$replay->inboxActionReportForWindow(now()->subHours($hours), filters: $filters)', $controller);
        $this->assertStringContainsString("Route::get('/ai/inbox-actions/report', AtlasAiInboxActionReportController::class)", $routes);
        $this->assertStringContainsString('LedgerEventType::InboxActionRecorded', $commandTest);
        $this->assertStringContainsString('open_reviewable_inbox_action_evidence_proposal', $commandTest);
        $this->assertStringContainsString('/ai/inbox-actions/report', $apiTest);
        $this->assertStringContainsString('LedgerEventType::InboxActionRecorded', $apiTest);
        $this->assertStringContainsString("assertJsonPath('inbox_actions.review_signal.recommended_action', 'open_reviewable_inbox_action_evidence_proposal')", $apiTest);
    }

    public function test_provider_cost_rate_inbox_replay_stays_human_applied_and_ledger_backed(): void
    {
        $inboxActions = file_get_contents(app_path('Services/Ai/Mobile/InboxActionRegistry.php'));
        $replay = file_get_contents(app_path('Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php'));
        $selfImprovement = file_get_contents(app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php'));
        $ledgerReplayTest = file_get_contents(base_path('tests/Unit/Ai/Kernel/LedgerReplayServiceTest.php'));
        $inboxActionTest = file_get_contents(base_path('tests/Feature/Ai/InboxLedgerProjectionActionTest.php'));
        $mcpTest = file_get_contents(base_path('tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php'));
        $observabilityTest = file_get_contents(base_path('tests/Feature/Ai/AiObservabilityKernelSloTest.php'));
        $ap = file_get_contents(base_path('docs/ap/AP-146-provider-cost-rate-inbox-replay.md'));

        $this->assertSame([], $this->violationsFor('ap146_provider_cost_rate_inbox_replay'));
        $this->assertStringContainsString("'configure_provider_cost_rates' => \$this->configureProviderCostRates(\$locked, \$input)", $inboxActions);
        $this->assertStringContainsString("'schema_version' => 'atlas.inbox_action.provider_cost_rates.v1'", $inboxActions);
        $this->assertStringContainsString('$this->providerCostRates->upsert($rateTemplate)', $inboxActions);
        $this->assertStringContainsString('provider_cost_rate_action_count', $replay);
        $this->assertStringContainsString('provider_cost_rate_applied_count', $replay);
        $this->assertStringContainsString('provider_cost_rate_provider_counts', $replay);
        $this->assertStringContainsString('configure_provider_cost_rates_action_without_applied_rate', $replay);
        $this->assertStringContainsString('provider_cost_rates_configured', $replay);
        $this->assertStringContainsString('configure_provider_cost_rates_action_without_applied_rate', $selfImprovement);
        $this->assertStringContainsString('atlas.provider_cost_rates.curator_completion_request.v1', $selfImprovement);
        $this->assertStringContainsString('test_inbox_action_window_report_projects_provider_cost_rate_actions', $ledgerReplayTest);
        $this->assertStringContainsString('test_inbox_action_window_report_warns_when_provider_cost_rate_action_is_only_previewed', $ledgerReplayTest);
        $this->assertStringContainsString('test_inbox_action_configures_provider_cost_rates_with_human_supplied_rates_and_ledger_evidence', $inboxActionTest);
        $this->assertStringContainsString('test_inbox_action_report_tool_exposes_provider_cost_rate_actions', $mcpTest);
        $this->assertStringContainsString('test_observability_payload_exposes_provider_cost_rate_inbox_actions', $observabilityTest);
        $this->assertStringContainsString('Provider Cost Rate Inbox Replay Contract', $ap);
    }

    public function test_ledger_projection_inbox_action_stays_reviewable_and_dry_run_safe(): void
    {
        $registry = file_get_contents(app_path('Services/Ai/Mobile/InboxActionRegistry.php'));
        $cli = file_get_contents(app_path('Console/Commands/AtlasCliInboxCommand.php'));
        $test = file_get_contents(base_path('tests/Feature/Ai/InboxLedgerProjectionActionTest.php'));
        $ap = file_get_contents(base_path('docs/ap/AP-142-ledger-projection-inbox-action.md'));

        $this->assertSame([], $this->violationsFor('ap142_ledger_projection_inbox_action'));
        $this->assertStringContainsString('LedgerProjectionWorker', $registry);
        $this->assertStringContainsString("'run_ledger_projection' => \$this->runLedgerProjection(\$locked, \$input)", $registry);
        $this->assertStringContainsString('private function runLedgerProjection(AiInboxItem $item, array $input): array', $registry);
        $this->assertStringContainsString("'schema_version' => 'atlas.inbox_action.ledger_projection.v1'", $registry);
        $this->assertStringContainsString("'ledger_projection_action' => \$payload['ledger_projection_action']", $registry);
        $this->assertStringContainsString("'status' => \$applied ? 'resolved'", $registry);
        $this->assertStringContainsString('LedgerEventType::InboxActionRecorded', $registry);
        $this->assertStringContainsString('{--projection-hours= : Hours window for run_ledger_projection}', $cli);
        $this->assertStringContainsString('{--projection-limit= : Max ledger events for run_ledger_projection}', $cli);
        $this->assertStringContainsString('{--dry-run : Preview run_ledger_projection without writing projection tables}', $cli);
        $this->assertStringContainsString("'projection_hours' => \$this->option('projection-hours')", $cli);
        $this->assertStringContainsString("'projection_limit' => \$this->option('projection-limit')", $cli);
        $this->assertStringContainsString('test_inbox_action_runs_ledger_projection_and_records_reviewable_evidence', $test);
        $this->assertStringContainsString('test_inbox_action_can_preview_ledger_projection_without_resolving_item', $test);
        $this->assertStringContainsString('Ledger Projection Inbox Action', $ap);
    }

    public function test_ledger_projection_curator_action_emission_preserves_assisted_action_payload(): void
    {
        $runtime = file_get_contents(app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php'));
        $emitter = file_get_contents(app_path('Services/Ai/Mobile/ProposalInboxEmitter.php'));
        $runtimeTest = file_get_contents(base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php'));
        $emitterTest = file_get_contents(base_path('tests/Unit/Ai/ProposalInboxEmitterTest.php'));
        $ap = file_get_contents(base_path('docs/ap/AP-143-ledger-projection-curator-action-emission.md'));

        $this->assertSame([], $this->violationsFor('ap143_ledger_projection_curator_action_emission'));
        $this->assertStringContainsString('ledgerProjectionDriftFindings', $runtime);
        $this->assertStringContainsString("'available_actions' => [", $runtime);
        $this->assertStringContainsString("'id' => 'run_ledger_projection'", $runtime);
        $this->assertStringContainsString("'projection_health' => [", $runtime);
        $this->assertStringContainsString("'ledger_projection' => [", $runtime);
        $this->assertStringContainsString("'recommended_action' => 'open_reviewable_ledger_projection_backfill_proposal'", $runtime);
        $this->assertStringContainsString('$availableActions = $this->availableActions($data)', $emitter);
        $this->assertStringContainsString('private function availableActions(array $data): array', $emitter);
        $this->assertStringContainsString("\$this->array(\$data['available_actions'] ?? [])", $emitter);
        $this->assertStringContainsString("\$payload = \$this->array(\$data['payload'] ?? [])", $emitter);
        $this->assertStringContainsString('array_replace_recursive($payload', $emitter);
        $this->assertStringContainsString('test_self_improvement_emits_ledger_projection_drift_proposal_with_assisted_action', $runtimeTest);
        $this->assertStringContainsString('payload.ledger_projection.hours', $runtimeTest);
        $this->assertStringContainsString('test_proposal_preserves_custom_actions_and_payload_for_assisted_operations', $emitterTest);
        $this->assertStringContainsString('available_actions.0.id', $emitterTest);
        $this->assertStringContainsString('Ledger Projection Curator Action Emission', $ap);
    }

    public function test_documentation_health_curator_review_stays_proposal_only(): void
    {
        $runtime = file_get_contents(app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php'));
        $test = file_get_contents(base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php'));
        $docOs = file_get_contents(base_path('docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md'));
        $ap = file_get_contents(base_path('docs/ap/AP-145-documentation-health-curator-review.md'));

        $this->assertSame([], $this->violationsFor('ap145_documentation_health_curator_review'));
        $this->assertStringContainsString('private function documentationHealthFindings(array $filters = []): array', $runtime);
        $this->assertStringContainsString('atlas.self_improvement.documentation_health_gap.v1', $runtime);
        $this->assertStringContainsString('split_oversized_active_docs', $runtime);
        $this->assertStringContainsString('documentation.oversized_docs', $runtime);
        $this->assertStringContainsString('split_required_grandfathered', $runtime);
        $this->assertStringContainsString('self-improvement:documentation-health:', $runtime);
        $this->assertStringContainsString('atlas engineering knowledge docs-health --json', $runtime);
        $this->assertStringContainsString('test_self_improvement_detects_oversized_active_documentation_from_architecture_validation', $test);
        $this->assertStringContainsString('atlas.self_improvement.documentation_health_gap.v1', $test);
        $this->assertStringContainsString('split_oversized_active_docs', $test);
        $this->assertStringContainsString('Documentation Health Curator Review', $docOs);
        $this->assertStringContainsString('atlas.self_improvement.documentation_health_gap.v1', $docOs);
        $this->assertStringContainsString('Documentation Health Curator Review', $ap);
    }

    public function test_self_improvement_replay_review_stays_evidence_ledger_backed(): void
    {
        $runtime = file_get_contents(app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php'));
        $test = file_get_contents(base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php'));
        $ap = file_get_contents(base_path('docs/ap/AP-123-self-improvement-inbox-action-replay-review.md'));

        $this->assertSame([], $this->violationsFor('ap123_self_improvement_inbox_action_replay_review'));
        $this->assertStringContainsString('inboxActionReplayFindings(', $runtime);
        $this->assertStringContainsString('inboxActionReportForWindow(', $runtime);
        $this->assertStringContainsString('normalizedInboxActionFilters(', $runtime);
        $this->assertStringContainsString('atlas.self_improvement.inbox_action_replay_gap.v1', $runtime);
        $this->assertStringContainsString('review_patch_action_without_diff_refs', $runtime);
        $this->assertStringContainsString('record_rivals_review_action_without_scores', $runtime);
        $this->assertStringContainsString('open_reviewable_inbox_action_evidence_proposal', $runtime);
        $this->assertStringContainsString('test_self_improvement_detects_inbox_action_replay_patch_review_gap', $test);
        $this->assertStringContainsString('test_self_improvement_detects_rivals_review_action_without_scores', $test);
        $this->assertStringContainsString('recordInboxActionEvent(', $test);
        $this->assertStringContainsString('Self-Improvement Inbox Action Replay Review', $ap);
    }

    public function test_observability_replay_surface_stays_evidence_ledger_backed(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/AiObservabilityController.php'));
        $test = file_get_contents(base_path('tests/Feature/Ai/AiObservabilityKernelSloTest.php'));
        $ap = file_get_contents(base_path('docs/ap/AP-124-observability-inbox-action-replay.md'));

        $this->assertSame([], $this->violationsFor('ap124_observability_inbox_action_replay'));
        $this->assertStringContainsString('$inboxActions = $ledgerReplay->inboxActionReportForWindow($since)', $controller);
        $this->assertStringContainsString("'inbox_actions' => \$inboxActions", $controller);
        $this->assertStringContainsString('test_observability_payload_includes_inbox_action_replay_summary', $test);
        $this->assertStringContainsString('LedgerEventType::InboxActionRecorded', $test);
        $this->assertStringContainsString("assertJsonPath('inbox_actions.available', true)", $test);
        $this->assertStringContainsString("assertJsonPath('inbox_actions.review_signal.recommended_action', 'open_reviewable_inbox_action_evidence_proposal')", $test);
        $this->assertStringContainsString('review_patch_action_without_diff_refs', $test);
        $this->assertStringContainsString('Observability Inbox Action Replay', $ap);
        $this->assertStringContainsString('inboxActionReportForWindow($since)', $ap);
        $this->assertStringContainsString('open_reviewable_inbox_action_evidence_proposal', $ap);
    }

    /**
     * @param  callable(): void  $assertions
     */
    private function withTemporaryFile(string $path, string $contents, callable $assertions): void
    {
        file_put_contents($path, $contents);

        try {
            $assertions();
        } finally {
            @unlink($path);
        }
    }

    /**
     * @param  array<string,string>  $files
     * @param  callable(): void  $assertions
     */
    private function withTemporaryFiles(array $files, callable $assertions): void
    {
        foreach ($files as $path => $contents) {
            file_put_contents($path, $contents);
        }

        try {
            $assertions();
        } finally {
            foreach (array_keys($files) as $path) {
                @unlink($path);
            }
        }
    }

    /**
     * @param  callable(): void  $assertions
     */
    private function withTemporaryFileContents(string $path, string $contents, callable $assertions): void
    {
        $original = file_get_contents($path);
        file_put_contents($path, $contents);

        try {
            $assertions();
        } finally {
            file_put_contents($path, $original);
        }
    }

    /**
     * @return array<int, string>
     */
    private function violationsFor(string $contract): array
    {
        $report = app(KernelArchitectureStaticScanner::class)->complianceReport();

        return data_get($report, "{$contract}.violations", []);
    }
}
