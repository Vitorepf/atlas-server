<?php

namespace App\Services\Ai\Kernel\Architecture\Scanner;

use App\Support\PeeledSource;
use App\Support\RoutesApiSource;
use Illuminate\Support\Facades\File;

class InboxActionAudit
{
    public function __construct(private ScanPrimitivesSupport $primitives) {}

    /**
     * @return array<string,callable(): array<int,string>>
     */
    public function checks(): array
    {
        return [
            'ap120_inbox_action_evidence_ledger_contract' => fn (): array => $this->scanInboxActionEvidenceLedgerContract(),
            'ap121_inbox_action_replay_read_model' => fn (): array => $this->scanInboxActionReplayReadModel(),
            'ap122_inbox_action_mcp_report' => fn (): array => $this->scanInboxActionMcpReport(),
            'ap125_inbox_action_report_surfaces' => fn (): array => $this->scanInboxActionReportSurfaces(),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function scanInboxActionReportSurfaces(): array
    {
        $violations = [];
        $commandPath = app_path('Console/Commands/AtlasAiInboxActionReportCommand.php');
        $controllerPath = app_path('Http/Controllers/AtlasAiInboxActionReportController.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiInboxActionReportCommandTest.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiInboxActionReportApiTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-125-inbox-action-report-surfaces.md');

        $command = PeeledSource::read($commandPath);
        $controller = PeeledSource::read($controllerPath);
        $routes = RoutesApiSource::read();
        $commandTest = File::exists($commandTestPath) ? File::get($commandTestPath) : '';
        $apiTest = File::exists($apiTestPath) ? File::get($apiTestPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        foreach ([
            'atlas:ai:inbox-action-report',
            'KernelReplayReportInput $input',
            'inboxActionReportForWindow(now()->subHours($hours), filters: $filters)',
            "'inbox_actions' => \$report",
            "'actor_type' => ['actor-type']",
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiInboxActionReportCommand.php: AP-125 command must expose Inbox action replay via shared input contract [{$token}]";
            }
        }

        foreach ([
            'class AtlasAiInboxActionReportController',
            'KernelReplayReportInput $input',
            "'recommended_action' => ['nullable', 'string', 'max:180']",
            '$replay->inboxActionReportForWindow(now()->subHours($hours), filters: $filters)',
            "'inbox_actions' => \$report",
        ] as $token) {
            if (! str_contains($controller, $token)) {
                $violations[] = "app/Http/Controllers/AtlasAiInboxActionReportController.php: AP-125 API must expose Inbox action replay via shared input contract [{$token}]";
            }
        }

        foreach ([
            'AtlasAiInboxActionReportController',
            "Route::get('/ai/inbox-actions/report', AtlasAiInboxActionReportController::class)",
        ] as $token) {
            if (! str_contains($routes, $token)) {
                $violations[] = "routes/api.php: AP-125 Inbox action report API route must be registered [{$token}]";
            }
        }

        foreach ([
            'test_command_summarizes_inbox_action_window_as_json',
            'test_command_filters_inbox_action_report_as_json',
            'test_command_reports_unavailable_when_ledger_table_is_missing',
            'LedgerEventType::InboxActionRecorded',
            'atlas:ai:inbox-action-report',
            'open_reviewable_inbox_action_evidence_proposal',
        ] as $token) {
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiInboxActionReportCommandTest.php: AP-125 command surface must be covered [{$token}]";
            }
        }

        foreach ([
            'test_inbox_action_report_api_returns_window_summary',
            'test_inbox_action_report_api_filters_window_summary',
            'test_inbox_action_report_api_requires_atlas_token',
            '/ai/inbox-actions/report',
            'LedgerEventType::InboxActionRecorded',
            'wait_for_inbox_action_evidence',
        ] as $token) {
            if (! str_contains($apiTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiInboxActionReportApiTest.php: AP-125 API surface must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-125',
            'Inbox Action Report Surfaces',
            'atlas:ai:inbox-action-report',
            '/ai/inbox-actions/report',
            'inboxActionReportForWindow',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-125 Inbox action report surfaces must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-125-inbox-action-report-surfaces.md: AP-125 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanInboxActionMcpReport(): array
    {
        $violations = [];
        $mcpPath = app_path('Services/Ai/AtlasOpenBrainMcpService.php');
        $testPath = base_path('tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-122-inbox-action-mcp-report.md');

        $reportToolsPath = app_path('Services/Ai/OpenBrainMcp/ReportTools.php');

        $mcp = PeeledSource::read($mcpPath);
        $reportTools = PeeledSource::read($reportToolsPath);
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        // Façade keeps the tools() schema + dispatch; the handler + its filter contract
        // were relocated under GOD-DEBULK D3 to OpenBrainMcp/ReportTools (invariant unchanged).
        foreach ([
            "'name' => 'atlas_inbox_action_report'",
            "'atlas_inbox_action_report' => \$this->toolResponse(\$id, \$this->reportTools->inboxActionReport(\$arguments))",
        ] as $token) {
            if (! str_contains($mcp, $token)) {
                $violations[] = "app/Services/Ai/AtlasOpenBrainMcpService.php: AP-122 Inbox action replay must be exposed as read-only MCP report [{$token}]";
            }
        }

        foreach ([
            'public function inboxActionReport(array $arguments): array',
            '$this->ledgerReplay->inboxActionReportForWindow(',
            "'inbox_actions' => \$report",
            "'action'",
            "'actor_type'",
            "'inbox_item_category'",
            "'recommended_action'",
        ] as $token) {
            if (! str_contains($reportTools, $token)) {
                $violations[] = "app/Services/Ai/OpenBrainMcp/ReportTools.php: AP-122 Inbox action replay must be exposed as read-only MCP report [{$token}]";
            }
        }

        foreach ([
            'test_inbox_action_report_tool_exposes_replay_read_model',
            'recordInboxActionForMcp(',
            'atlas_inbox_action_report',
            'inbox_actions.review_signal.status',
            'wait_for_inbox_action_evidence',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php: AP-122 MCP Inbox action report must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-122',
            'Inbox Action MCP Report',
            'atlas_inbox_action_report',
            'inboxActionReportForWindow',
            'wait_for_inbox_action_evidence',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-122 Inbox action MCP report must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-122-inbox-action-mcp-report.md: AP-122 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanInboxActionReplayReadModel(): array
    {
        $violations = [];
        $replayPath = app_path('Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php');
        $testPath = base_path('tests/Unit/Ai/Kernel/LedgerReplayServiceTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-121-inbox-action-replay-read-model.md');

        $replay = PeeledSource::read($replayPath);
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        foreach ([
            'public function inboxActionReportForWindow(CarbonInterface $since, ?CarbonInterface $until = null, array $filters = []): array',
            'LedgerEventType::InboxActionRecorded',
            'inboxActionEventFromEvent(',
            'inboxActionSummary(',
            'inboxActionReviewSignal(',
            'normalizedInboxActionFilters(',
            'matchesInboxActionFilters(',
            "'open_reviewable_inbox_action_evidence_proposal'",
            "'wait_for_inbox_action_evidence'",
        ] as $token) {
            if (! str_contains($replay, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php: AP-121 Inbox action events must be projectable through replay [{$token}]";
            }
        }

        foreach ([
            'test_inbox_action_window_report_projects_human_review_evidence',
            'test_inbox_action_window_report_filters_and_warns_when_patch_review_lacks_diff_refs',
            'recordInboxActionEvent(',
            'LedgerEventType::InboxActionRecorded',
            'inboxActionReportForWindow(',
            'review_patch_action_without_diff_refs',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/LedgerReplayServiceTest.php: AP-121 Inbox action replay read model must be tested [{$token}]";
            }
        }

        foreach ([
            'AP-121',
            'Inbox Action Replay Read Model',
            'inboxActionReportForWindow',
            'LedgerEventType::InboxActionRecorded',
            'review_patch_action_without_diff_refs',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-121 Inbox action replay read model must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-121-inbox-action-replay-read-model.md: AP-121 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanInboxActionEvidenceLedgerContract(): array
    {
        $violations = [];
        $eventTypePath = app_path('Services/Ai/Kernel/Evidence/LedgerEventType.php');
        $actionsPath = app_path('Services/Ai/Mobile/InboxActionRegistry.php');
        $testPath = base_path('tests/Feature/MobileGatewayTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-120-inbox-action-evidence-ledger-contract.md');

        $eventType = PeeledSource::read($eventTypePath);
        $actions = PeeledSource::read($actionsPath);
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        if (! str_contains($eventType, "case InboxActionRecorded = 'INBOX_ACTION_RECORDED'")) {
            $violations[] = 'app/Services/Ai/Kernel/Evidence/LedgerEventType.php: AP-120 must define INBOX_ACTION_RECORDED';
        }

        foreach ([
            'private readonly AtlasEvidenceLedger $ledger',
            'private function recordInboxActionLedgerEvent',
            'LedgerEventType::InboxActionRecorded',
            "'schema_version' => 'atlas.inbox_action.v1'",
            "'recommended_action' => \$this->string(data_get(\$item->payload ?? [], 'proposal_contract.review_signal.recommended_action'))",
            "'emitter_stage' => 'atlas.inbox'",
        ] as $token) {
            if (! str_contains($actions, $token)) {
                $violations[] = "app/Services/Ai/Mobile/InboxActionRegistry.php: AP-120 Inbox actions must be recorded in Evidence Ledger [{$token}]";
            }
        }

        foreach ([
            'LedgerEventType::InboxActionRecorded',
            'atlas.inbox_action.v1',
            "data_get(\$ledgerEvent->payload, 'action')",
            "data_get(\$ledgerEvent->payload, 'result.payload.diff_refs.0.path')",
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/MobileGatewayTest.php: AP-120 Inbox action ledger event must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-120',
            'Inbox Action Evidence Ledger Contract',
            'LedgerEventType::InboxActionRecorded',
            'atlas.inbox_action.v1',
            'review_patch',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-120 Inbox action ledger contract must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-120-inbox-action-evidence-ledger-contract.md: AP-120 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }
}
