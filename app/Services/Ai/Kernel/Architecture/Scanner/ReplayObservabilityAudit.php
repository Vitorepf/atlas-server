<?php

namespace App\Services\Ai\Kernel\Architecture\Scanner;

use Illuminate\Support\Facades\File;

class ReplayObservabilityAudit
{
    public function __construct(private ScanPrimitivesSupport $primitives)
    {
    }

    /**
     * @return array<string,callable(): array<int,string>>
     */
    public function checks(): array
    {
        return [
            'ap66_replay_report_input_contract' => fn (): array => $this->scanReplayReportInputContract(),
            'ap68_replay_report_validation_limit_contract' => fn (): array => $this->scanReplayReportValidationLimitContract(),
            'ap67_observability_replay_input_contract' => fn (): array => $this->scanObservabilityReplayInputContract(),
            'ap124_observability_inbox_action_replay' => fn (): array => $this->scanObservabilityInboxActionReplay(),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function scanReplayReportInputContract(): array
    {
        $servicePath = app_path('Services/Ai/Kernel/Evidence/KernelReplayReportInput.php');
        $mcpPath = app_path('Services/Ai/AtlasOpenBrainMcpService.php');
        $commandPaths = [
            app_path('Console/Commands/AtlasAiSelfImprovementScheduleReportCommand.php'),
            app_path('Console/Commands/AtlasAiSloCommand.php'),
            app_path('Console/Commands/AtlasAiKernelPipelineReportCommand.php'),
            app_path('Console/Commands/AtlasAiRepairReportCommand.php'),
        ];
        $controllerPaths = [
            app_path('Http/Controllers/AtlasAiSelfImprovementScheduleReportController.php'),
            app_path('Http/Controllers/AtlasAiSloController.php'),
            app_path('Http/Controllers/AtlasAiKernelPipelineReportController.php'),
            app_path('Http/Controllers/AtlasAiRepairReportController.php'),
        ];
        $testPath = base_path('tests/Unit/Ai/Kernel/KernelReplayReportInputTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $reportToolsPath = app_path('Services/Ai/OpenBrainMcp/ReportTools.php');

        $service = File::exists($servicePath) ? File::get($servicePath) : '';
        $mcp = File::exists($mcpPath) ? File::get($mcpPath) : '';
        $reportTools = File::exists($reportToolsPath) ? File::get($reportToolsPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();

        foreach ([
            'final class KernelReplayReportInput',
            'public const DEFAULT_WINDOW_HOURS = 24',
            'public const MAX_WINDOW_HOURS = 720',
            'public function hours(mixed $value): int',
            'public function scalarFilters(array $input, array $allowed): array',
            'public function aliasedScalarFilters(array $input, array $aliases): array',
            'return max(1, min(self::MAX_WINDOW_HOURS, (int) $value))',
            "trim((string) \$value) !== ''",
        ] as $token) {
            if (! str_contains($service, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/KernelReplayReportInput.php: replay report input contract is incomplete [{$token}]";
            }
        }

        // Façade still declares the KernelReplayReportInput dependency; the replay tool
        // consumers were relocated under GOD-DEBULK D3 to OpenBrainMcp/ReportTools.
        if (! str_contains($mcp, 'private readonly KernelReplayReportInput $replayInput')) {
            $violations[] = "app/Services/Ai/AtlasOpenBrainMcpService.php: MCP replay tools must consume KernelReplayReportInput [private readonly KernelReplayReportInput \$replayInput]";
        }

        foreach ([
            '$this->replayInput->hours',
            '$this->replayInput->scalarFilters',
        ] as $token) {
            if (! str_contains($reportTools, $token)) {
                $violations[] = "app/Services/Ai/OpenBrainMcp/ReportTools.php: MCP replay tools must consume KernelReplayReportInput [{$token}]";
            }
        }

        foreach (array_merge($commandPaths, $controllerPaths) as $path) {
            $contents = File::exists($path) ? File::get($path) : '';
            if (! str_contains($contents, 'KernelReplayReportInput')) {
                $violations[] = "{$path}: replay report surface must consume KernelReplayReportInput";
            }
        }

        foreach ([
            'test_hours_normalizes_window_with_canonical_limits',
            'test_scalar_filters_trim_values_and_drop_empty_or_non_scalar_values',
            'test_aliased_scalar_filters_use_first_non_empty_alias',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/KernelReplayReportInputTest.php: replay report input contract must be covered [{$token}]";
            }
        }

        foreach ([
            'replay report input contract',
            'AP-66',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe replay report input contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanReplayReportValidationLimitContract(): array
    {
        $controllerPaths = [
            app_path('Http/Controllers/AiObservabilityController.php'),
            app_path('Http/Controllers/AtlasAiSelfImprovementScheduleReportController.php'),
            app_path('Http/Controllers/AtlasAiSloController.php'),
            app_path('Http/Controllers/AtlasAiKernelPipelineReportController.php'),
            app_path('Http/Controllers/AtlasAiRepairReportController.php'),
        ];
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        foreach ($controllerPaths as $path) {
            $contents = File::exists($path) ? File::get($path) : '';

            if (! str_contains($contents, "'between:1,'.KernelReplayReportInput::MAX_WINDOW_HOURS")) {
                $violations[] = "{$path}: replay report API hours validation must use KernelReplayReportInput::MAX_WINDOW_HOURS";
            }

            if (str_contains($contents, "'between:1,720'") || str_contains($contents, '"between:1,720"')) {
                $violations[] = "{$path}: replay report API hours validation must not duplicate literal between:1,720";
            }
        }

        $docs = $this->primitives->kernelDocumentationCorpus();
        foreach ([
            'replay report validation limit contract',
            'AP-68',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe replay report validation limit contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanObservabilityReplayInputContract(): array
    {
        $controllerPath = app_path('Http/Controllers/AiObservabilityController.php');
        $testPath = base_path('tests/Feature/Ai/AiObservabilityKernelSloTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $controller = File::exists($controllerPath) ? File::get($controllerPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();

        foreach ([
            'use App\Services\Ai\Kernel\Evidence\KernelReplayReportInput;',
            'KernelReplayReportInput $replayInput',
            "\$hours = \$replayInput->hours(\$data['hours'] ?? null)",
        ] as $token) {
            if (! str_contains($controller, $token)) {
                $violations[] = "app/Http/Controllers/AiObservabilityController.php: observability must use shared replay input contract [{$token}]";
            }
        }

        foreach ([
            'test_observability_payload_uses_default_replay_window_contract',
            'CarbonImmutable::parse',
            '$this->assertEqualsWithDelta(1440',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AiObservabilityKernelSloTest.php: observability replay window contract must be covered [{$token}]";
            }
        }

        foreach ([
            'observability replay input contract',
            'AP-67',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe observability replay input contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanObservabilityInboxActionReplay(): array
    {
        $violations = [];
        $controllerPath = app_path('Http/Controllers/AiObservabilityController.php');
        $testPath = base_path('tests/Feature/Ai/AiObservabilityKernelSloTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-124-observability-inbox-action-replay.md');

        $controller = File::exists($controllerPath) ? File::get($controllerPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        foreach ([
            '$inboxActions = $ledgerReplay->inboxActionReportForWindow($since)',
            "'inbox_actions' => \$inboxActions",
        ] as $token) {
            if (! str_contains($controller, $token)) {
                $violations[] = "app/Http/Controllers/AiObservabilityController.php: AP-124 observability must expose Inbox action replay [{$token}]";
            }
        }

        foreach ([
            'test_observability_payload_includes_inbox_action_replay_summary',
            'LedgerEventType::InboxActionRecorded',
            "assertJsonPath('inbox_actions.available', true)",
            "assertJsonPath('inbox_actions.review_signal.recommended_action', 'open_reviewable_inbox_action_evidence_proposal')",
            'review_patch_action_without_diff_refs',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AiObservabilityKernelSloTest.php: AP-124 observability Inbox action replay must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-124',
            'Observability Inbox Action Replay',
            'inbox_actions',
            'inboxActionReportForWindow',
            'open_reviewable_inbox_action_evidence_proposal',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-124 observability Inbox action replay must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-124-observability-inbox-action-replay.md: AP-124 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }
}
