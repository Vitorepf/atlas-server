<?php

namespace App\Services\Ai\Kernel\Architecture\Scanner;

use Illuminate\Support\Facades\File;

class ScheduleReplayAudit
{
    public function __construct(private ScanPrimitivesSupport $primitives) {}

    /**
     * @return array<string,callable(): array<int,string>>
     */
    public function checks(): array
    {
        return [
            'ap63_schedule_replay_unavailable_shape_parity' => fn (): array => $this->scanScheduleReplayUnavailableShapeParity(),
            'ap110_schedule_replay_inbox_refs_contract' => fn (): array => $this->scanScheduleReplayInboxRefsContract(),
            'ap111_schedule_replay_inbox_refs_surface_parity' => fn (): array => $this->scanScheduleReplayInboxRefsSurfaceParity(),
            'ap112_schedule_replay_inbox_item_hydration' => fn (): array => $this->scanScheduleReplayInboxItemHydration(),
            'ap113_schedule_replay_inbox_item_hydration_surface_parity' => fn (): array => $this->scanScheduleReplayInboxItemHydrationSurfaceParity(),
            'ap114_schedule_replay_inbox_hydration_gap_signal' => fn (): array => $this->scanScheduleReplayInboxHydrationGapSignal(),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function scanScheduleReplayInboxHydrationGapSignal(): array
    {
        $violations = [];
        $replayPath = app_path('Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php');
        $commandPath = app_path('Console/Commands/AtlasAiSelfImprovementScheduleReportCommand.php');
        $unitTestPath = base_path('tests/Unit/Ai/Kernel/LedgerReplayServiceTest.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiSelfImprovementScheduleReportCommandTest.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiSelfImprovementScheduleReportApiTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-114-schedule-replay-inbox-hydration-gap-signal-contract.md');

        $replay = File::exists($replayPath) ? File::get($replayPath) : '';
        $command = File::exists($commandPath) ? File::get($commandPath) : '';
        $unitTest = File::exists($unitTestPath) ? File::get($unitTestPath) : '';
        $commandTest = File::exists($commandTestPath) ? File::get($commandTestPath) : '';
        $apiTest = File::exists($apiTestPath) ? File::get($apiTestPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        foreach ([
            "'emitted_inbox_item_hydration_available' => \$hydrationAvailable",
            "'emitted_inbox_item_missing_ids' => \$missingIds",
            "'emitted_inbox_item_hydration_available' => DatabaseTableAvailability::has('ai_inbox_items')",
            "'emitted_inbox_item_missing_ids' => \$missingInboxItemIds",
        ] as $token) {
            if (! str_contains($replay, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php: AP-114 replay must expose inbox hydration availability and missing ids [{$token}]";
            }
        }

        foreach ([
            'Inbox hydration',
            'Missing inbox refs',
            "'missing refs'",
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiSelfImprovementScheduleReportCommand.php: AP-114 CLI must show inbox hydration gaps [{$token}]";
            }
        }

        foreach ([
            'test_self_improvement_schedule_window_report_exposes_missing_inbox_refs',
            'emitted_inbox_item_hydration_available',
            'emitted_inbox_item_missing_ids',
        ] as $token) {
            if (! str_contains($unitTest, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/LedgerReplayServiceTest.php: AP-114 missing inbox refs must be unit tested [{$token}]";
            }
        }

        foreach ([
            'missing_inbox_refs',
            'emitted_inbox_item_hydration_available',
            'emitted_inbox_item_missing_ids',
        ] as $token) {
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiSelfImprovementScheduleReportCommandTest.php: AP-114 CLI/API JSON gap signals must be covered [{$token}]";
            }
            if (! str_contains($apiTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiSelfImprovementScheduleReportApiTest.php: AP-114 API gap signals must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-114',
            'Schedule Replay Inbox Hydration Gap Signal',
            'emitted_inbox_item_hydration_available',
            'emitted_inbox_item_missing_ids',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-114 hydration gap signal must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-114-schedule-replay-inbox-hydration-gap-signal-contract.md: AP-114 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanScheduleReplayInboxItemHydrationSurfaceParity(): array
    {
        $violations = [];
        $observabilityTestPath = base_path('tests/Feature/Ai/AiObservabilityKernelSloTest.php');
        $mcpTestPath = base_path('tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-113-schedule-replay-inbox-item-hydration-surface-parity-contract.md');

        $observabilityTest = File::exists($observabilityTestPath) ? File::get($observabilityTestPath) : '';
        $mcpTest = File::exists($mcpTestPath) ? File::get($mcpTestPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        foreach ([
            'AiInboxItem::unguarded',
            'self_improvement_schedule_replay.emitted_inbox_items.0.title',
            'self_improvement_schedule_replay.emitted_inbox_items.0.review_signal.recommended_action',
            'self_improvement_schedule_replay.recent_events.0.emitted_inbox_items.0.title',
        ] as $token) {
            if (! str_contains($observabilityTest, $token)) {
                $violations[] = "tests/Feature/Ai/AiObservabilityKernelSloTest.php: AP-113 Observability must expose hydrated schedule replay inbox items [{$token}]";
            }
            if (! str_contains($mcpTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php: AP-113 MCP must expose hydrated schedule replay inbox items [{$token}]";
            }
        }

        foreach ([
            'AP-113',
            'Schedule Replay Inbox Item Hydration Surface Parity',
            'Observability',
            'MCP',
            'emitted_inbox_items',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-113 hydration surface parity must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-113-schedule-replay-inbox-item-hydration-surface-parity-contract.md: AP-113 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanScheduleReplayInboxItemHydration(): array
    {
        $violations = [];
        $replayPath = app_path('Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php');
        $commandPath = app_path('Console/Commands/AtlasAiSelfImprovementScheduleReportCommand.php');
        $unitTestPath = base_path('tests/Unit/Ai/Kernel/LedgerReplayServiceTest.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiSelfImprovementScheduleReportCommandTest.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiSelfImprovementScheduleReportApiTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-112-schedule-replay-inbox-item-hydration-contract.md');

        $replay = File::exists($replayPath) ? File::get($replayPath) : '';
        $command = File::exists($commandPath) ? File::get($commandPath) : '';
        $unitTest = File::exists($unitTestPath) ? File::get($unitTestPath) : '';
        $commandTest = File::exists($commandTestPath) ? File::get($commandTestPath) : '';
        $apiTest = File::exists($apiTestPath) ? File::get($apiTestPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        foreach ([
            'use App\\Models\\AiInboxItem;',
            'private function selfImprovementInboxItemsById(array $ids): array',
            'private function selfImprovementInboxItemSummary(AiInboxItem $item): array',
            'private function withSelfImprovementInboxItems(array $event, array $inboxItemsById, bool $hydrationAvailable): array',
            "'emitted_inbox_items' => \$emittedInboxItems",
            "'review_signal' => data_get(\$item->payload, 'proposal_contract.review_signal')",
        ] as $token) {
            if (! str_contains($replay, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php: AP-112 schedule replay must hydrate emitted inbox item summaries [{$token}]";
            }
        }

        foreach ([
            'Emitted inbox items',
            'compactInboxItems(',
            "'inbox items'",
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiSelfImprovementScheduleReportCommand.php: AP-112 CLI human output must expose hydrated inbox item summaries [{$token}]";
            }
        }

        foreach ([
            'AiInboxItem::unguarded',
            'emitted_inbox_items.0.title',
            'review_schedule_repair',
        ] as $token) {
            if (! str_contains($unitTest, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/LedgerReplayServiceTest.php: AP-112 replay hydration must be unit tested [{$token}]";
            }
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiSelfImprovementScheduleReportCommandTest.php: AP-112 CLI hydration must be covered [{$token}]";
            }
            if (! str_contains($apiTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiSelfImprovementScheduleReportApiTest.php: AP-112 API hydration must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-112',
            'Schedule Replay Inbox Item Hydration',
            'emitted_inbox_items',
            'review_signal',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-112 hydration contract must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-112-schedule-replay-inbox-item-hydration-contract.md: AP-112 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanScheduleReplayInboxRefsSurfaceParity(): array
    {
        $violations = [];
        $commandPath = app_path('Console/Commands/AtlasAiSelfImprovementScheduleReportCommand.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiSelfImprovementScheduleReportCommandTest.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiSelfImprovementScheduleReportApiTest.php');
        $observabilityTestPath = base_path('tests/Feature/Ai/AiObservabilityKernelSloTest.php');
        $mcpTestPath = base_path('tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-111-schedule-replay-inbox-refs-surface-parity-contract.md');

        $command = File::exists($commandPath) ? File::get($commandPath) : '';
        $commandTest = File::exists($commandTestPath) ? File::get($commandTestPath) : '';
        $apiTest = File::exists($apiTestPath) ? File::get($apiTestPath) : '';
        $observabilityTest = File::exists($observabilityTestPath) ? File::get($observabilityTestPath) : '';
        $mcpTest = File::exists($mcpTestPath) ? File::get($mcpTestPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        foreach ([
            'Completed runs',
            'Emitted proposals',
            'Emitted inbox refs',
            "'inbox refs'",
            'compactList(',
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiSelfImprovementScheduleReportCommand.php: AP-111 CLI human output must expose schedule replay completion refs [{$token}]";
            }
        }

        foreach ([
            'self_improvement_schedule_replay.completed_count',
            'self_improvement_schedule_replay.emitted_count',
            'self_improvement_schedule_replay.emitted_inbox_item_ids',
            'Emitted inbox refs',
        ] as $token) {
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiSelfImprovementScheduleReportCommandTest.php: AP-111 CLI surface parity must be covered [{$token}]";
            }
        }

        foreach ([
            'self_improvement_schedule_replay.completed_count',
            'self_improvement_schedule_replay.emitted_count',
            'self_improvement_schedule_replay.emitted_inbox_item_ids.0',
            'self_improvement_schedule_replay.recent_events.0.emitted_inbox_item_ids.0',
        ] as $token) {
            if (! str_contains($apiTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiSelfImprovementScheduleReportApiTest.php: AP-111 API surface parity must be covered [{$token}]";
            }
            if (! str_contains($observabilityTest, $token)) {
                $violations[] = "tests/Feature/Ai/AiObservabilityKernelSloTest.php: AP-111 Observability surface parity must be covered [{$token}]";
            }
            if (! str_contains($mcpTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php: AP-111 MCP surface parity must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-111',
            'Schedule Replay Inbox Refs Surface Parity',
            'Completed runs',
            'Emitted inbox refs',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-111 surface parity contract must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-111-schedule-replay-inbox-refs-surface-parity-contract.md: AP-111 contract doc must exist and define surface parity [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanScheduleReplayInboxRefsContract(): array
    {
        $violations = [];
        $replayPath = app_path('Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php');
        $testPath = base_path('tests/Unit/Ai/Kernel/LedgerReplayServiceTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');

        $replay = File::exists($replayPath) ? File::get($replayPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();

        foreach ([
            'private function selfImprovementCompletionByEnvelope(CarbonInterface $since, CarbonInterface $until): array',
            'private function withSelfImprovementCompletion(array $event, ?array $completion): array',
            "'emitted_inbox_item_ids' => array_values((array) (\$completion['emitted_inbox_item_ids'] ?? []))",
            "'completed_count' => \$events->where('completed', true)->count()",
            "'emitted_inbox_item_ids' => \$emittedInboxItemIds",
        ] as $token) {
            if (! str_contains($replay, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php: AP-110 schedule replay must expose emitted inbox refs [{$token}]";
            }
        }

        foreach ([
            'recordSelfImprovementCompletionEvent',
            'emitted_inbox_item_ids',
            'completed_count',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/LedgerReplayServiceTest.php: AP-110 schedule replay inbox refs must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-110',
            'Schedule Replay Inbox Refs',
            'selfImprovementScheduleReportForWindow',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-110 schedule replay inbox refs contract must be documented [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanScheduleReplayUnavailableShapeParity(): array
    {
        $replayPath = app_path('Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiSelfImprovementScheduleReportCommandTest.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiSelfImprovementScheduleReportApiTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $replay = File::exists($replayPath) ? File::get($replayPath) : '';
        $commandTest = File::exists($commandTestPath) ? File::get($commandTestPath) : '';
        $apiTest = File::exists($apiTestPath) ? File::get($apiTestPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();

        foreach ([
            "...array_diff_key(\$this->selfImprovementScheduleEventSummary(collect()), ['events' => true])",
            "'recent_events' => []",
        ] as $token) {
            if (! str_contains($replay, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php: unavailable schedule replay must match public shape and omit raw events [{$token}]";
            }
        }

        foreach ([
            "\$this->assertArrayNotHasKey('events', data_get(\$payload, 'self_improvement_schedule_replay'))",
            "data_get(\$payload, 'self_improvement_schedule_replay.review_signal.status')",
            "data_get(\$payload, 'self_improvement_schedule_replay.recent_events')",
        ] as $token) {
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiSelfImprovementScheduleReportCommandTest.php: CLI unavailable schedule replay shape must be covered [{$token}]";
            }
        }

        foreach ([
            "\$this->assertArrayNotHasKey('events', \$response->json('self_improvement_schedule_replay'))",
            "self_improvement_schedule_replay.review_signal.status', 'unknown'",
            "self_improvement_schedule_replay.recent_events', []",
        ] as $token) {
            if (! str_contains($apiTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiSelfImprovementScheduleReportApiTest.php: API unavailable schedule replay shape must be covered [{$token}]";
            }
        }

        foreach ([
            'schedule replay unavailable shape parity',
            'AP-63',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe schedule replay unavailable shape parity [{$token}]";
            }
        }

        return $violations;
    }
}
