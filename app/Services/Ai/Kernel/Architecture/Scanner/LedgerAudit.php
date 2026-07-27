<?php

namespace App\Services\Ai\Kernel\Architecture\Scanner;

use App\Support\PeeledSource;
use Illuminate\Support\Facades\File;

class LedgerAudit
{
    public function __construct(private ScanPrimitivesSupport $primitives) {}

    /**
     * @return array<string,callable(): array<int,string>>
     */
    public function checks(): array
    {
        return [
            'ap140_ledger_replay_command_surface' => fn (): array => $this->scanLedgerReplayCommandSurface(),
            'ap141_ledger_projection_registry_contract' => fn (): array => $this->scanLedgerProjectionRegistryContract(),
            'ap142_ledger_projection_inbox_action' => fn (): array => $this->scanLedgerProjectionInboxAction(),
            'ap143_ledger_projection_curator_action_emission' => fn (): array => $this->scanLedgerProjectionCuratorActionEmission(),
            'ap74_ledger_envelope_input_contract' => fn (): array => $this->scanLedgerEnvelopeInputContract(),
            'ap75_ledger_envelope_report_contract' => fn (): array => $this->scanLedgerEnvelopeReportContract(),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function scanLedgerEnvelopeInputContract(): array
    {
        $inputPath = app_path('Services/Ai/Kernel/Evidence/KernelLedgerEnvelopeInput.php');
        $reportPath = app_path('Services/Ai/Kernel/Evidence/KernelLedgerEnvelopeReportService.php');
        $controllerPath = app_path('Http/Controllers/AtlasAiLedgerController.php');
        $unitTestPath = base_path('tests/Unit/Ai/Kernel/KernelLedgerEnvelopeInputTest.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiLedgerCommandTest.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiLedgerApiTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $input = PeeledSource::read($inputPath);
        $report = PeeledSource::read($reportPath);
        $controller = PeeledSource::read($controllerPath);
        $unitTest = File::exists($unitTestPath) ? File::get($unitTestPath) : '';
        $commandTest = File::exists($commandTestPath) ? File::get($commandTestPath) : '';
        $apiTest = File::exists($apiTestPath) ? File::get($apiTestPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();

        foreach ([
            'final class KernelLedgerEnvelopeInput',
            'public const DEFAULT_EVENT_LIMIT = 100',
            'public const MAX_EVENT_LIMIT = 500',
            'return max(1, min(self::MAX_EVENT_LIMIT, (int) $value))',
        ] as $token) {
            if (! str_contains($input, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/KernelLedgerEnvelopeInput.php: ledger envelope input contract is incomplete [{$token}]";
            }
        }

        foreach ([
            'private readonly KernelLedgerEnvelopeInput $input',
            '$this->input->eventLimit($limit)',
        ] as $token) {
            if (! str_contains($report, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/KernelLedgerEnvelopeReportService.php: Ledger report must use shared envelope input [{$token}]";
            }
        }

        foreach ([
            "'max:'.KernelLedgerEnvelopeInput::MAX_EVENT_LIMIT",
            'KernelLedgerEnvelopeReportService $reports',
        ] as $token) {
            if (! str_contains($controller, $token)) {
                $violations[] = "app/Http/Controllers/AtlasAiLedgerController.php: Ledger API must use shared envelope input [{$token}]";
            }
        }

        foreach ([
            'test_event_limit_normalizes_with_canonical_ledger_limits',
            'KernelLedgerEnvelopeInput::MAX_EVENT_LIMIT',
            'KernelLedgerEnvelopeInput::DEFAULT_EVENT_LIMIT',
        ] as $token) {
            if (! str_contains($unitTest, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/KernelLedgerEnvelopeInputTest.php: ledger envelope input contract must be covered [{$token}]";
            }
        }

        foreach ([
            'test_command_uses_canonical_ledger_event_limit_contract',
            'KernelLedgerEnvelopeInput::MAX_EVENT_LIMIT',
        ] as $token) {
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiLedgerCommandTest.php: Ledger CLI limit contract must be covered [{$token}]";
            }
        }

        foreach ([
            'test_ledger_api_uses_canonical_event_limit_contract',
            'KernelLedgerEnvelopeInput::MAX_EVENT_LIMIT',
        ] as $token) {
            if (! str_contains($apiTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiLedgerApiTest.php: Ledger API limit contract must be covered [{$token}]";
            }
        }

        foreach ([
            'ledger envelope input contract',
            'AP-74',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe ledger envelope input contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanLedgerEnvelopeReportContract(): array
    {
        $reportPath = app_path('Services/Ai/Kernel/Evidence/KernelLedgerEnvelopeReportService.php');
        $commandPath = app_path('Console/Commands/AtlasAiLedgerCommand.php');
        $controllerPath = app_path('Http/Controllers/AtlasAiLedgerController.php');
        $unitTestPath = base_path('tests/Unit/Ai/Kernel/KernelLedgerEnvelopeReportServiceTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $report = PeeledSource::read($reportPath);
        $command = PeeledSource::read($commandPath);
        $controller = PeeledSource::read($controllerPath);
        $unitTest = File::exists($unitTestPath) ? File::get($unitTestPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();

        foreach ([
            'class KernelLedgerEnvelopeReportService',
            'public function report(string $envelopeId, mixed $limit = null, bool $includeSlo = false, bool $includeRepair = false, bool $includeKernel = false): array',
            "'status' => 'ledger_table_missing'",
            '\'filters\' => $filters',
            'private function eventPayload(array $event): array',
            '$this->replay->sloReportForEnvelope($envelopeId)',
            '$this->replay->repairReportForEnvelope($envelopeId)',
            '$this->replay->kernelPipelineReportForEnvelope($envelopeId)',
        ] as $token) {
            if (! str_contains($report, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/KernelLedgerEnvelopeReportService.php: ledger envelope report contract is incomplete [{$token}]";
            }
        }

        foreach ([
            'KernelLedgerEnvelopeReportService $reports',
            '$reports->report(',
            'includeKernel: (bool) $this->option(\'kernel\')',
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiLedgerCommand.php: Ledger CLI must consume shared report service [{$token}]";
            }
        }

        foreach ([
            'KernelLedgerEnvelopeReportService $reports',
            '$reports->report(',
            "\$payload['status'] === 'ledger_table_missing' ? 503 : 200",
        ] as $token) {
            if (! str_contains($controller, $token)) {
                $violations[] = "app/Http/Controllers/AtlasAiLedgerController.php: Ledger API must consume shared report service [{$token}]";
            }
        }

        foreach ([
            'test_report_projects_envelope_events_with_canonical_filters',
            'test_report_preserves_shape_when_ledger_table_is_missing',
            'KernelLedgerEnvelopeReportService::class',
        ] as $token) {
            if (! str_contains($unitTest, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/KernelLedgerEnvelopeReportServiceTest.php: ledger envelope report service must be covered [{$token}]";
            }
        }

        foreach ([
            'ledger envelope report contract',
            'AP-75',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe ledger envelope report contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanLedgerReplayCommandSurface(): array
    {
        $commandPath = app_path('Console/Commands/AtlasLedgerReplayCommand.php');
        $catalogPath = app_path('Services/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalog.php');
        $runtimePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $testPath = base_path('tests/Feature/Ai/AtlasLedgerReplayCommandTest.php');
        $catalogTestPath = base_path('tests/Unit/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalogTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-140-ledger-replay-command-surface.md');

        $command = PeeledSource::read($commandPath);
        $catalog = PeeledSource::read($catalogPath);
        $runtime = PeeledSource::read($runtimePath);
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $catalogTest = File::exists($catalogTestPath) ? File::get($catalogTestPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        $violations = [];

        foreach ([
            "protected \$signature = 'atlas:ledger:replay",
            'KernelLedgerEnvelopeReportService',
            '{--envelope=',
            'envelope_required',
            'includeSlo',
            'includeRepair',
            'includeKernel',
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasLedgerReplayCommand.php: AP-140 ledger replay command must expose canonical envelope replay [{$token}]";
            }
        }

        foreach ([
            "'id' => 'ledger_replay'",
            "'command' => 'php artisan atlas:ai:ledger <id> --json'",
            "'kind' => 'evidence_report'",
        ] as $token) {
            if (! str_contains($catalog, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalog.php: AP-140 ledger replay must be discoverable [{$token}]";
            }
        }

        if (! str_contains($runtime, 'php artisan atlas:ai:ledger <id> --json')) {
            $violations[] = 'app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: AP-140 Self-Improvement must protect ledger replay in architecture operations expected commands';
        }

        foreach ([
            'test_command_replays_envelope_from_named_option_as_json',
            'test_command_requires_envelope_option',
            "Artisan::call('atlas:ledger:replay'",
            'envelope_required',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasLedgerReplayCommandTest.php: AP-140 command behavior must be tested [{$token}]";
            }
        }

        foreach ([
            'ledger_replay',
            'php artisan atlas:ai:ledger <id> --json',
        ] as $token) {
            if (! str_contains($catalogTest, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalogTest.php: AP-140 catalog discovery must be tested [{$token}]";
            }
        }

        foreach ([
            'AP-140',
            'Ledger Replay Command Surface',
            'atlas:ledger:replay',
            'ap140_ledger_replay_command_surface',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-140 ledger replay command must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-140-ledger-replay-command-surface.md: AP-140 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanLedgerProjectionRegistryContract(): array
    {
        $registryPath = app_path('Services/Ai/Kernel/Evidence/LedgerProjectionRegistry.php');
        $validationPath = app_path('Services/Ai/Kernel/Architecture/AtlasAiArchitectureValidationService.php');
        $testPath = base_path('tests/Unit/Ai/Kernel/LedgerProjectionRegistryTest.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiArchitectureValidateCommandTest.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiArchitectureValidateApiTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-141-ledger-projection-registry-contract.md');

        $registry = PeeledSource::read($registryPath);
        $validation = PeeledSource::read($validationPath);
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $commandTest = File::exists($commandTestPath) ? File::get($commandTestPath) : '';
        $apiTest = File::exists($apiTestPath) ? File::get($apiTestPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        $violations = [];

        foreach ([
            'final class LedgerProjectionRegistry',
            "'schema_version' => 'atlas.ledger_projection_registry.v1'",
            "'id' => 'ai_traces'",
            "'id' => 'atlas_engineering_runs'",
            "'id' => 'atlas_tool_runs'",
            'LedgerEventType::ProviderCalled',
            'LedgerEventType::ToolEvidenceRecorded',
            'LedgerEventType::RepairCompleted',
            'required_columns',
            'identity_keys',
            'readinessWarnings',
        ] as $token) {
            if (! str_contains($registry, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/LedgerProjectionRegistry.php: AP-141 ledger projection registry contract is incomplete [{$token}]";
            }
        }

        foreach ([
            'LedgerProjectionRegistry',
            'ledger_projections',
            "'schema_version' => \$ledgerProjectionReport['schema_version']",
            "'projection_ids' => \$ledgerProjectionReport['projection_ids']",
        ] as $token) {
            if (! str_contains($validation, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Architecture/AtlasAiArchitectureValidationService.php: AP-141 architecture validate must expose ledger projections [{$token}]";
            }
        }

        foreach ([
            'test_registry_declares_core_ledger_projections',
            'test_registry_reports_projection_readiness_from_schema',
            'test_registry_reports_missing_table_without_column_noise',
            'atlas.ledger_projection_registry.v1',
            'atlas_engineering_runs',
            'atlas_tool_runs',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/LedgerProjectionRegistryTest.php: AP-141 projection registry must be unit tested [{$token}]";
            }
        }

        foreach ([
            'kernel.ledger_projections.valid',
            'kernel.ledger_projections.schema_version',
            'ap141_ledger_projection_registry_contract',
        ] as $token) {
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiArchitectureValidateCommandTest.php: AP-141 command validate payload must be covered [{$token}]";
            }
            if (! str_contains($apiTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiArchitectureValidateApiTest.php: AP-141 API validate payload must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-141',
            'Ledger Projection Registry Contract',
            'LedgerProjectionRegistry',
            'ap141_ledger_projection_registry_contract',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-141 ledger projection registry must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-141-ledger-projection-registry-contract.md: AP-141 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanLedgerProjectionInboxAction(): array
    {
        $registryPath = app_path('Services/Ai/Mobile/InboxActionRegistry.php');
        $cliPath = app_path('Console/Commands/AtlasCliInboxCommand.php');
        $testPath = base_path('tests/Feature/Ai/InboxLedgerProjectionActionTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-142-ledger-projection-inbox-action.md');

        $registry = PeeledSource::read($registryPath);
        $cli = PeeledSource::read($cliPath);
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        $violations = [];

        foreach ([
            'LedgerProjectionWorker',
            "'run_ledger_projection' => \$this->runLedgerProjection(\$locked, \$input)",
            'private function runLedgerProjection(AiInboxItem $item, array $input): array',
            "'schema_version' => 'atlas.inbox_action.ledger_projection.v1'",
            "'ledger_projection_action' => \$payload['ledger_projection_action']",
            "'status' => \$applied ? 'resolved'",
            'LedgerEventType::InboxActionRecorded',
        ] as $token) {
            if (! str_contains($registry, $token)) {
                $violations[] = "app/Services/Ai/Mobile/InboxActionRegistry.php: AP-142 ledger projection Inbox action is incomplete [{$token}]";
            }
        }

        foreach ([
            '{--projection-hours= : Hours window for run_ledger_projection}',
            '{--projection-limit= : Max ledger events for run_ledger_projection}',
            '{--dry-run : Preview run_ledger_projection without writing projection tables}',
            "'projection_hours' => \$this->option('projection-hours')",
            "'projection_limit' => \$this->option('projection-limit')",
        ] as $token) {
            if (! str_contains($cli, $token)) {
                $violations[] = "app/Console/Commands/AtlasCliInboxCommand.php: AP-142 CLI must expose projection action inputs [{$token}]";
            }
        }

        foreach ([
            'class InboxLedgerProjectionActionTest',
            'test_inbox_action_runs_ledger_projection_and_records_reviewable_evidence',
            'test_inbox_action_can_preview_ledger_projection_without_resolving_item',
            'run_ledger_projection',
            'atlas.inbox_action.ledger_projection.v1',
            'LedgerEventType::InboxActionRecorded',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/InboxLedgerProjectionActionTest.php: AP-142 Inbox projection action must be tested [{$token}]";
            }
        }

        foreach ([
            'AP-142',
            'Ledger Projection Inbox Action',
            'run_ledger_projection',
            'atlas.inbox_action.ledger_projection.v1',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-142 ledger projection Inbox action must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-142-ledger-projection-inbox-action.md: AP-142 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanLedgerProjectionCuratorActionEmission(): array
    {
        $runtimePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $emitterPath = app_path('Services/Ai/Mobile/ProposalInboxEmitter.php');
        $runtimeTestPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $emitterTestPath = base_path('tests/Unit/Ai/ProposalInboxEmitterTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-143-ledger-projection-curator-action-emission.md');

        $runtime = PeeledSource::read($runtimePath);
        $emitter = PeeledSource::read($emitterPath);
        $runtimeTest = File::exists($runtimeTestPath) ? File::get($runtimeTestPath) : '';
        $emitterTest = File::exists($emitterTestPath) ? File::get($emitterTestPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        $violations = [];

        foreach ([
            'ledgerProjectionDriftFindings',
            "'available_actions' => [",
            "'id' => 'run_ledger_projection'",
            "'projection_health' => [",
            "'ledger_projection' => [",
            "'recommended_action' => 'open_reviewable_ledger_projection_backfill_proposal'",
        ] as $token) {
            if (! str_contains($runtime, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: AP-143 Curator must emit actionable ledger projection proposal [{$token}]";
            }
        }

        foreach ([
            '$availableActions = $this->availableActions($data)',
            'private function availableActions(array $data): array',
            "\$this->array(\$data['available_actions'] ?? [])",
            "\$payload = \$this->array(\$data['payload'] ?? [])",
            'array_replace_recursive($payload',
        ] as $token) {
            if (! str_contains($emitter, $token)) {
                $violations[] = "app/Services/Ai/Mobile/ProposalInboxEmitter.php: AP-143 Proposal emitter must preserve custom actions and payload [{$token}]";
            }
        }

        foreach ([
            'test_self_improvement_emits_ledger_projection_drift_proposal_with_assisted_action',
            'run_ledger_projection',
            'payload.projection_health.status',
            'payload.ledger_projection.hours',
        ] as $token) {
            if (! str_contains($runtimeTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: AP-143 Curator emission must be feature tested [{$token}]";
            }
        }

        foreach ([
            'test_proposal_preserves_custom_actions_and_payload_for_assisted_operations',
            'run_ledger_projection',
            'available_actions.0.id',
            'raw_payload.projection_health.status',
        ] as $token) {
            if (! str_contains($emitterTest, $token)) {
                $violations[] = "tests/Unit/Ai/ProposalInboxEmitterTest.php: AP-143 emitter payload/action preservation must be unit tested [{$token}]";
            }
        }

        foreach ([
            'AP-143',
            'Ledger Projection Curator Action Emission',
            'run_ledger_projection',
            'available_actions',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-143 Curator action emission must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-143-ledger-projection-curator-action-emission.md: AP-143 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }
}
