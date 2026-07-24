<?php

namespace App\Services\Ai\Kernel\Architecture\Scanner;

use Illuminate\Support\Facades\File;

class CliAudit
{
    public function __construct(private ScanPrimitivesSupport $primitives) {}

    /**
     * @return array<string,callable(): array<int,string>>
     */
    public function checks(): array
    {
        return [
            'ap20_cli_fix_dev_repair_alias' => fn (): array => $this->scanCliFixDevRepairAlias(),
            'ap21_cli_continue_dev_resume_alias' => fn (): array => $this->scanCliContinueDevResumeAlias(),
            'ap22_cli_forge_programming_harness_contract' => fn (): array => $this->scanCliForgeProgrammingHarnessContract(),
            'ap26_cli_dev_model_selection_contract' => fn (): array => $this->scanCliDevModelSelectionContract(),
            'ap96_cli_limit_input_contract' => fn (): array => $this->scanCliLimitInputContract(),
            'ap119_cli_inbox_review_action_result_parity' => fn (): array => $this->scanCliInboxReviewActionResultParity(),
            'ap127_cli_help_architecture_operations_discovery' => fn (): array => $this->scanCliHelpArchitectureOperationsDiscovery(),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function scanCliHelpArchitectureOperationsDiscovery(): array
    {
        $violations = [];
        $commandPath = app_path('Console/Commands/AtlasCliHelpCommand.php');
        $catalogPath = app_path('Services/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalog.php');
        $testPath = base_path('tests/Feature/AtlasCliHelpCommandTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-127-cli-help-architecture-operations-discovery.md');

        $command = File::exists($commandPath) ? File::get($commandPath) : '';
        $catalog = File::exists($catalogPath) ? File::get($catalogPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        foreach ([
            'AtlasArchitectureOperationsCatalog $architectureOperations',
            '$architectureOperations->sectionKey() => $architectureOperations->commands()',
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasCliHelpCommand.php: AP-127 CLI help must expose architecture operations discovery [{$token}]";
            }
        }

        foreach ([
            "return 'arquitetura_mae'",
            "'command' => 'php artisan atlas:ai:architecture-operations --json'",
            "'command' => 'php artisan atlas:ai:architecture-validate'",
            "'command' => 'php artisan atlas:ai:slo --hours=24 --json'",
            "'command' => 'php artisan atlas:ai:kernel-pipeline-report --hours=24 --json'",
            "'command' => 'php artisan atlas:ai:repair-report --hours=24 --json'",
            "'command' => 'php artisan atlas:ai:provider-performance --hours=24 --json'",
            "'command' => 'php artisan atlas:ai:provider-release-review --provider=<provider> --title=\"<release>\" --json'",
            "'command' => 'php artisan atlas:ai:agent-behavior-report --hours=24 --json'",
            "'command' => 'php artisan atlas:ai:dynamic-compute-market --provider=<provider> --domain=<domain> --flow=<flow> --json'",
            "'command' => 'php artisan atlas:ai:self-improve --flow=provider_performance_review --hours=168 --json'",
            "'command' => 'php artisan atlas:ai:self-improve --flow=provider_release_review --hours=168 --json'",
            "'command' => 'php artisan atlas:ai:self-improvement-schedule-report --hours=24 --json'",
            "'command' => 'php artisan atlas:ai:inbox-action-report --hours=24 --json'",
        ] as $token) {
            if (! str_contains($catalog, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalog.php: AP-127 architecture operation commands must remain discoverable [{$token}]";
            }
        }

        foreach ([
            'arquitetura_mae',
            'php artisan atlas:ai:architecture-operations --json',
            'php artisan atlas:ai:architecture-validate',
            'php artisan atlas:ai:slo --hours=24 --json',
            'php artisan atlas:ai:kernel-pipeline-report --hours=24 --json',
            'php artisan atlas:ai:repair-report --hours=24 --json',
            'php artisan atlas:ai:provider-performance --hours=24 --json',
            'php artisan atlas:ai:provider-release-review --provider=<provider> --title="<release>" --json',
            'php artisan atlas:ai:agent-behavior-report --hours=24 --json',
            'php artisan atlas:ai:dynamic-compute-market --provider=<provider> --domain=<domain> --flow=<flow> --json',
            'php artisan atlas:ai:self-improve --flow=provider_performance_review --hours=168 --json',
            'php artisan atlas:ai:self-improve --flow=provider_release_review --hours=168 --json',
            'php artisan atlas:ai:self-improvement-schedule-report --hours=24 --json',
            'php artisan atlas:ai:inbox-action-report --hours=24 --json',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/AtlasCliHelpCommandTest.php: AP-127 CLI help architecture operations must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-127',
            'CLI Help Architecture Operations Discovery',
            'arquitetura_mae',
            'php artisan atlas:ai:architecture-operations --json',
            'php artisan atlas:ai:agent-behavior-report --hours=24 --json',
            'php artisan atlas:ai:inbox-action-report --hours=24 --json',
            'ap127_cli_help_architecture_operations_discovery',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-127 CLI help architecture operations discovery must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-127-cli-help-architecture-operations-discovery.md: AP-127 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanCliInboxReviewActionResultParity(): array
    {
        $violations = [];
        $commandPath = app_path('Console/Commands/AtlasCliInboxCommand.php');
        $testPath = base_path('tests/Feature/MobileGatewayTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-119-cli-inbox-review-action-result-parity-contract.md');

        $command = File::exists($commandPath) ? File::get($commandPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        foreach ([
            "return \$this->printItem(\$result['item'], \$result['result'] ?? [])",
            "'result' => \$result",
            'private function printItem(AiInboxItem $item, array $result = [])',
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasCliInboxCommand.php: AP-119 CLI respond JSON must preserve action result parity [{$token}]";
            }
        }

        foreach ([
            'Review patch via CLI',
            "'--action' => 'review_patch'",
            'result.payload.recommended_action',
            'result.payload.diff_refs.0.path',
            'review_cli_proposal_contract',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/MobileGatewayTest.php: AP-119 CLI review_patch parity must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-119',
            'CLI Inbox Review Action Result Parity',
            'atlas:cli:inbox respond',
            'review_patch',
            'recommended_action',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-119 CLI review action parity must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-119-cli-inbox-review-action-result-parity-contract.md: AP-119 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanCliLimitInputContract(): array
    {
        $inputPath = app_path('Console/Commands/Support/AtlasCliLimitInput.php');
        $toolsPath = app_path('Console/Commands/AtlasToolsCommand.php');
        $tracePath = app_path('Console/Commands/AtlasCliTraceCommand.php');
        $inboxPath = app_path('Console/Commands/AtlasCliInboxCommand.php');
        $benchmarkReportPath = app_path('Console/Commands/AtlasEngineeringBenchmarkReportCommand.php');
        $benchmarkCalibratePath = app_path('Console/Commands/AtlasEngineeringBenchmarkCalibrateCommand.php');
        $testPath = base_path('tests/Unit/Console/AtlasCliLimitInputTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $input = File::exists($inputPath) ? File::get($inputPath) : '';
        $tools = File::exists($toolsPath) ? File::get($toolsPath) : '';
        $trace = File::exists($tracePath) ? File::get($tracePath) : '';
        $inbox = File::exists($inboxPath) ? File::get($inboxPath) : '';
        $benchmarkReport = File::exists($benchmarkReportPath) ? File::get($benchmarkReportPath) : '';
        $benchmarkCalibrate = File::exists($benchmarkCalibratePath) ? File::get($benchmarkCalibratePath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();

        foreach ([
            'final class AtlasCliLimitInput',
            'public const DEFAULT_LIST_LIMIT = 20',
            'public const DEFAULT_INBOX_LIMIT = 50',
            'public const DEFAULT_RELEASE_GATE_LIMIT = 100',
            'public const MAX_STANDARD_LIMIT = 100',
            'public const MAX_RELEASE_GATE_LIMIT = 200',
            'public const MAX_BENCHMARK_CALIBRATION_LIMIT = 500',
            'public function standardLimit(',
            'public function releaseGateLimit(',
            'public function benchmarkReportLimit(',
            'public function benchmarkCalibrationLimit(',
            'public function inboxLimit(',
        ] as $token) {
            if (! str_contains($input, $token)) {
                $violations[] = "app/Console/Commands/Support/AtlasCliLimitInput.php: CLI limit input contract is incomplete [{$token}]";
            }
        }

        foreach ([
            'AtlasCliLimitInput $limits',
            '$this->cliLimits()->standardLimit($this->option(\'limit\'))',
            '$this->cliLimits()->releaseGateLimit($this->option(\'limit\'))',
            'private function cliLimits(): AtlasCliLimitInput',
        ] as $token) {
            if (! str_contains($tools, $token)) {
                $violations[] = "app/Console/Commands/AtlasToolsCommand.php: tools command must use shared CLI limit input [{$token}]";
            }
        }

        foreach ([
            '?AtlasCliLimitInput $limits = null',
            '$this->cliLimits()->standardLimit($this->option(\'limit\'))',
            'private function cliLimits(): AtlasCliLimitInput',
        ] as $token) {
            if (! str_contains($trace, $token)) {
                $violations[] = "app/Console/Commands/AtlasCliTraceCommand.php: trace command must use shared CLI limit input [{$token}]";
            }
        }

        foreach ([
            '?AtlasCliLimitInput $limits = null',
            '$this->cliLimits()->inboxLimit($this->option(\'limit\'))',
            'private function cliLimits(): AtlasCliLimitInput',
        ] as $token) {
            if (! str_contains($inbox, $token)) {
                $violations[] = "app/Console/Commands/AtlasCliInboxCommand.php: inbox command must use shared CLI limit input [{$token}]";
            }
        }

        if (! str_contains($benchmarkReport, '$limits->benchmarkReportLimit($this->option(\'limit\'))')) {
            $violations[] = 'app/Console/Commands/AtlasEngineeringBenchmarkReportCommand.php: benchmark report must use shared CLI limit input';
        }

        if (! str_contains($benchmarkCalibrate, '$limits->benchmarkCalibrationLimit($this->option(\'limit\'))')) {
            $violations[] = 'app/Console/Commands/AtlasEngineeringBenchmarkCalibrateCommand.php: benchmark calibrate must use shared CLI limit input';
        }

        foreach ([
            'test_normalizes_shared_cli_limits',
            'AtlasCliLimitInput::MAX_STANDARD_LIMIT',
            'AtlasCliLimitInput::MAX_RELEASE_GATE_LIMIT',
            'AtlasCliLimitInput::MAX_BENCHMARK_CALIBRATION_LIMIT',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Console/AtlasCliLimitInputTest.php: CLI limit input contract must be covered [{$token}]";
            }
        }

        foreach ([
            'CLI limit input contract',
            'AP-96',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe CLI limit input contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanCliFixDevRepairAlias(): array
    {
        $fixPath = app_path('Console/Commands/AtlasCliFixCommand.php');
        $builderPath = app_path('Services/Ai/Programming/AtlasProgrammingSurfaceCommandBuilder.php');
        $testPath = base_path('tests/Feature/AtlasCliFixCommandTest.php');

        $fix = File::exists($fixPath) ? File::get($fixPath) : '';
        $builder = File::exists($builderPath) ? File::get($builderPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';

        $violations = [];

        foreach ([
            'AtlasProgrammingSurfaceCommandBuilder',
            "return \$this->call('atlas:cli:dev', \$commands->repairDevArguments(",
            '{--plan-only : Run preflight and print the repair execution plan without calling provider}',
            'planOnly: (bool) $this->option(\'plan-only\')',
        ] as $token) {
            if (! str_contains($fix, $token)) {
                $violations[] = "app/Console/Commands/AtlasCliFixCommand.php: atlas fix must remain a thin atlas dev --repair alias with auditable plan-only support [{$token}]";
            }
        }

        foreach ([
            'public function repairDevArguments(',
            "'--repair' => true",
            "'--plan-only' => \$planOnly",
            "'--permission' => 'write'",
        ] as $token) {
            if (! str_contains($builder, $token)) {
                $violations[] = "app/Services/Ai/Programming/AtlasProgrammingSurfaceCommandBuilder.php: repairDevArguments must route fix through the canonical dev repair contract [{$token}]";
            }
        }

        foreach ([
            'test_fix_plan_only_uses_dev_repair_kernel_flow',
            "'programming.repair'",
            "'atlas_cli_dev'",
            "'dev_execution_plan.kernel_pipeline.provider_execution_allowed'",
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/AtlasCliFixCommandTest.php: atlas fix needs coverage proving it uses the dev repair kernel flow [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanCliContinueDevResumeAlias(): array
    {
        $continuePath = app_path('Console/Commands/AtlasCliContinueCommand.php');
        $sessionPath = app_path('Services/Ai/Cli/AtlasCliSessionService.php');
        $builderPath = app_path('Services/Ai/Programming/AtlasProgrammingSurfaceCommandBuilder.php');
        $testPath = base_path('tests/Feature/AtlasCliContinueCommandTest.php');

        $continue = File::exists($continuePath) ? File::get($continuePath) : '';
        $session = File::exists($sessionPath) ? File::get($sessionPath) : '';
        $builder = File::exists($builderPath) ? File::get($builderPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';

        $violations = [];

        foreach ([
            'AtlasProgrammingSurfaceCommandBuilder',
            'ProgrammingSurfaceContractFactory',
            '$commands->resumeDevCommand($resume, $operatorOptions, $this->openBrainOptions($operatorOptions))',
            "'resume_contract' => app(ProgrammingSurfaceContractFactory::class)->resume(",
        ] as $token) {
            if (! str_contains($continue, $token)) {
                $violations[] = "app/Console/Commands/AtlasCliContinueCommand.php: atlas continue must remain a thin resume alias into atlas:cli:dev with a structured resume_contract [{$token}]";
            }
        }

        foreach ([
            "'dev_execution_plan'",
            "'programming_session_plan'",
            "'programming_message_plan'",
            'programmingProfileFromPlan(',
            "'operator_options' => is_array(\$plan['operator_options'] ?? null) ? (array) \$plan['operator_options'] : []",
        ] as $token) {
            if (! str_contains($session, $token)) {
                $violations[] = "app/Services/Ai/Cli/AtlasCliSessionService.php: atlas continue must resume canonical dev/programming plans without legacy-only coupling [{$token}]";
            }
        }

        foreach ([
            'public function resumeDevCommand(',
            "'atlas:cli:dev'",
            "'--resume='.(string) \$resume['plan_id']",
            "'--repair'",
            "'--forge'",
        ] as $token) {
            if (! str_contains($builder, $token)) {
                $violations[] = "app/Services/Ai/Programming/AtlasProgrammingSurfaceCommandBuilder.php: resumeDevCommand must translate continue into canonical atlas:cli:dev flags [{$token}]";
            }
        }

        foreach ([
            'test_continue_dry_run_uses_configured_php_binary_for_resume_command',
            'test_continue_resumes_programming_session_plan_without_legacy_dev_plan',
            "'atlas.cli_continue.resume_contract.v1'",
            "'atlas_cli_continue'",
            "'resume_contract.canonical_surface'",
            "'resume_contract.target_surface'",
            'AtlasProgrammingSurfaceCommandBuilder::class',
            "'resume_contract.dev_flags.resume'",
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/AtlasCliContinueCommandTest.php: atlas continue needs coverage proving resume_contract and canonical dev resume flags [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanCliForgeProgrammingHarnessContract(): array
    {
        $devPath = app_path('Console/Commands/AtlasCliDevCommand.php');
        $adapterPath = app_path('Services/Ai/Surface/Adapters/AtlasCliForgeSurfaceAdapter.php');
        $registryPath = app_path('Services/Ai/Policy/AtlasDomainProfileRegistry.php');
        $orchestratorPath = app_path('Services/Ai/Programming/AtlasProgrammingOrchestrator.php');
        $testPath = base_path('tests/Feature/EngineeringHarnessRunnerTest.php');

        $dev = File::exists($devPath) ? File::get($devPath) : '';
        $adapter = File::exists($adapterPath) ? File::get($adapterPath) : '';
        $registry = File::exists($registryPath) ? File::get($registryPath) : '';
        $orchestrator = File::exists($orchestratorPath) ? File::get($orchestratorPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';

        $violations = [];

        foreach ([
            "\$programmingProfile === 'forge'",
            'executeWithHarness(ProgrammingExecutionRequest::fromArray',
            "'profile' => 'forge'",
            "'phase' => 'forge_harness'",
            'ProgrammingSurfaceContractFactory',
            "'forge_contract' => app(ProgrammingSurfaceContractFactory::class)->forge(\$devPlan, \$result)",
        ] as $token) {
            if (! str_contains($dev, $token)) {
                $violations[] = "app/Console/Commands/AtlasCliDevCommand.php: atlas forge must remain programming.forge routed through Engineering Harness with a structured forge_contract [{$token}]";
            }
        }

        foreach ([
            "protected const SURFACE_ID = 'atlas_cli_forge'",
            "'default_flow_id' => 'programming.forge'",
            "'prefer_default_flow' => true",
            "'forge' => 'programming.forge'",
            "'heavy' => 'programming.forge'",
        ] as $token) {
            if (! str_contains($adapter, $token)) {
                $violations[] = "app/Services/Ai/Surface/Adapters/AtlasCliForgeSurfaceAdapter.php: forge surface must default to programming.forge [{$token}]";
            }
        }

        foreach ([
            "'programming.forge' => [",
            "'runtime' => 'EngineeringHarness'",
            "'execution_policy' => ['executor_preference' => 'engineering_harness']",
            "'required_bundles' => ['engineering-blueprint', 'dev-quality-gate', 'code-reviewer']",
        ] as $token) {
            if (! str_contains($registry, $token)) {
                $violations[] = "app/Services/Ai/Policy/AtlasDomainProfileRegistry.php: programming.forge domain profile must require Engineering Harness and enterprise skill bundles [{$token}]";
            }
        }

        foreach ([
            "'programming.forge'",
            "\$profile = str_ends_with(\$flow, '.forge') || \$flow === 'forge' ? 'forge' : 'dev'",
            'public function execute(array $plan, array $context = []): array',
        ] as $token) {
            if (! str_contains($orchestrator, $token)) {
                $violations[] = "app/Services/Ai/Programming/AtlasProgrammingOrchestrator.php: Programming orchestrator must own programming.forge planning [{$token}]";
            }
        }

        foreach ([
            'test_atlas_cli_forge_routes_to_harness_without_task_id',
            "'atlas.cli_forge.contract.v1'",
            "'forge_contract.surface'",
            "'forge_contract.flow'",
            "'forge_contract.runtime'",
            "'forge_contract.kernel_pipeline_flow'",
            "'forge_contract.evidence_required'",
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/EngineeringHarnessRunnerTest.php: forge needs coverage proving programming.forge and engineering_harness contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanCliDevModelSelectionContract(): array
    {
        $devPath = app_path('Console/Commands/AtlasCliDevCommand.php');
        $testPath = base_path('tests/Feature/AtlasCliDevCommandTest.php');

        $dev = File::exists($devPath) ? File::get($devPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';

        $violations = [];

        foreach ([
            'ModelSelectionContractFactory',
            "\$preflight['model_selection_contract'] = \$this->modelSelectionContract(",
            "\$devPlan['model_selection_contract'] = \$this->modelSelectionContract(",
            'private function modelSelectionContract(?string $provider, ?array $modelSelection, ?string $modelOverride, bool $fairMode, array $context = []): array',
            '->forCliDev($provider, $modelSelection, $modelOverride, $fairMode, $context)',
        ] as $token) {
            if (! str_contains($dev, $token)) {
                $violations[] = "app/Console/Commands/AtlasCliDevCommand.php: atlas dev must expose model_selection_contract with Atlas Decide authority [{$token}]";
            }
        }

        foreach ([
            "'workflow.model_selection_contract.schema_version'",
            "'workflow.model_selection_contract.authority'",
            "'workflow.model_selection_contract.selection_mode'",
            "'workflow.model_selection_contract.available_selection_modes'",
            "'workflow.model_selection_contract.operator_requested_provider'",
            "'workflow.model_selection_contract.requested_model'",
            "'workflow.model_selection_contract.requested_model_alias'",
            "'dev_execution_plan.model_selection_contract'",
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/AtlasCliDevCommandTest.php: atlas dev needs tests for model selection contract in auto and manual override paths [{$token}]";
            }
        }

        return $violations;
    }
}
