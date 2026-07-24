<?php

namespace App\Services\Ai\Kernel\Architecture\Scanner;

use Illuminate\Support\Facades\File;

class DecisionReceiptAudit
{
    public function __construct(private ScanPrimitivesSupport $primitives) {}

    /**
     * @return array<string,callable(): array<int,string>>
     */
    public function checks(): array
    {
        return [
            'ap134_decision_receipt_hash_runtime_guard' => fn (): array => $this->scanDecisionReceiptHashRuntimeGuard(),
            'ap135_decision_receipt_determinism_test' => fn (): array => $this->scanDecisionReceiptDeterminismTest(),
            'ap136_decision_receipt_chain_replay' => fn (): array => $this->scanDecisionReceiptChainReplay(),
            'ap137_decision_receipt_replay_surfaces' => fn (): array => $this->scanDecisionReceiptReplaySurfaces(),
            'ap138_decision_receipt_replay_curator_review' => fn (): array => $this->scanDecisionReceiptReplayCuratorReview(),
            'ap139_decision_receipt_replay_inbox_emission' => fn (): array => $this->scanDecisionReceiptReplayInboxEmission(),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function scanDecisionReceiptHashRuntimeGuard(): array
    {
        $guardPath = app_path('Services/Ai/Kernel/Decision/DecisionReceiptRuntimeGuard.php');
        $hashPath = app_path('Services/Ai/Kernel/Decision/DecisionReceiptHash.php');
        $issuerPath = app_path('Services/Ai/Kernel/Decision/DecisionReceiptIssuer.php');
        $workerPath = app_path('Services/Ai/AiWorker.php');
        $testPath = base_path('tests/Unit/Ai/Kernel/DecisionReceiptRuntimeGuardTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-134-decision-receipt-hash-runtime-guard.md');

        $guard = File::exists($guardPath) ? File::get($guardPath) : '';
        $hash = File::exists($hashPath) ? File::get($hashPath) : '';
        $issuer = File::exists($issuerPath) ? File::get($issuerPath) : '';
        $worker = File::exists($workerPath) ? File::get($workerPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        $violations = [];

        foreach ([
            'final class DecisionReceiptHash',
            'public static function hash(array $payload): string',
            'public static function canonicalize(array $payload): array',
            'JSON_THROW_ON_ERROR',
        ] as $token) {
            if (! str_contains($hash, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Decision/DecisionReceiptHash.php: AP-134 shared receipt hasher must exist [{$token}]";
            }
        }

        foreach ([
            'DecisionReceiptHash::hash($payload)',
            "'envelope_input_hash'",
            "'parent_chain_hash'",
        ] as $token) {
            if (! str_contains($issuer, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Decision/DecisionReceiptIssuer.php: AP-134 issuer must use shared hash contract and persist verification hints [{$token}]";
            }
        }

        foreach ([
            'private function hashIntegrityViolation(array $receiptV2, array $base): ?DecisionReceiptRuntimeViolation',
            'decision_receipt_hash_mismatch',
            'DecisionReceiptHash::hash([',
            'inputs_hash',
            'receipt_hash',
            'chain_hash',
            'hash_equals(',
        ] as $token) {
            if (! str_contains($guard, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Decision/DecisionReceiptRuntimeGuard.php: AP-134 runtime guard must reject tampered DecisionReceipt hashes [{$token}]";
            }
        }

        if (! str_contains($worker, "'decision_receipt_hash_mismatch'")) {
            $violations[] = 'app/Services/Ai/AiWorker.php: AP-134 worker must treat hash mismatch as pre-provider block [decision_receipt_hash_mismatch]';
        }

        foreach ([
            'test_accepts_issued_receipt_with_matching_hashes',
            'test_blocks_issued_receipt_when_signed_payload_is_tampered',
            'decision_receipt_hash_mismatch',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/DecisionReceiptRuntimeGuardTest.php: AP-134 hash runtime guard must be unit tested [{$token}]";
            }
        }

        foreach ([
            'AP-134',
            'Decision Receipt Hash Runtime Guard',
            'decision_receipt_hash_mismatch',
            'ap134_decision_receipt_hash_runtime_guard',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-134 receipt hash runtime guard must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-134-decision-receipt-hash-runtime-guard.md: AP-134 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanDecisionReceiptDeterminismTest(): array
    {
        $testPath = base_path('tests/Feature/Architecture/DecisionReceiptDeterminismTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-135-decision-receipt-determinism-test.md');

        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        $violations = [];

        foreach ([
            'class DecisionReceiptDeterminismTest extends TestCase',
            'test_receipt_hashes_are_stable_for_same_envelope_and_decision_contract',
            'test_receipt_hash_changes_when_authorized_provider_changes',
            'test_runtime_guard_rejects_replayed_receipt_after_signed_payload_mutation',
            'DecisionReceiptIssuer',
            'DecisionReceiptRuntimeGuard',
            'decision_receipt_hash_mismatch',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Architecture/DecisionReceiptDeterminismTest.php: AP-135 must prove DecisionReceipt determinism and tamper rejection [{$token}]";
            }
        }

        foreach ([
            'AP-135',
            'Decision Receipt Determinism Test',
            'DecisionReceiptDeterminismTest',
            'ap135_decision_receipt_determinism_test',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-135 DecisionReceipt determinism test must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-135-decision-receipt-determinism-test.md: AP-135 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanDecisionReceiptChainReplay(): array
    {
        $ledgerPath = app_path('Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php');
        $replayPath = app_path('Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php');
        $testPath = base_path('tests/Unit/Ai/Kernel/LedgerReplayServiceTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-136-decision-receipt-chain-replay.md');

        $ledger = File::exists($ledgerPath) ? File::get($ledgerPath) : '';
        $replay = File::exists($replayPath) ? File::get($replayPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        $violations = [];

        foreach ([
            "'parent_receipt_id' => \$receipt['parent_receipt_id'] ?? null",
            "'parent_chain_hash' => \$receipt['parent_chain_hash'] ?? data_get(\$receipt, 'metadata.parent_chain_hash')",
        ] as $token) {
            if (! str_contains($ledger, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php: AP-136 DECISION_ISSUED must preserve receipt chain fields [{$token}]";
            }
        }

        foreach ([
            'public function decisionReceiptReportForEnvelope(string $envelopeId): array',
            'private function decisionReceiptEventFromEvent(array $event): array',
            'private function decisionReceiptEventSummary(Collection $events): array',
            'private function decisionReceiptReviewSignal(Collection $events, Collection $invalidEvents): array',
            'DecisionReceiptHash::hash([',
            'decision_receipt_chain_hash_mismatch',
            'open_reviewable_decision_receipt_replay_proposal',
        ] as $token) {
            if (! str_contains($replay, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php: AP-136 replay must verify DecisionReceipt chain integrity [{$token}]";
            }
        }

        foreach ([
            'test_decision_receipt_report_projects_chain_integrity_for_envelope',
            'test_decision_receipt_report_flags_hash_mismatch_for_review',
            'recordDecisionReceiptEvent',
            'decisionReceiptReportForEnvelope',
            'decision_receipt_chain_hash_mismatch',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/LedgerReplayServiceTest.php: AP-136 DecisionReceipt chain replay must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-136',
            'Decision Receipt Chain Replay',
            'decisionReceiptReportForEnvelope',
            'ap136_decision_receipt_chain_replay',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-136 DecisionReceipt chain replay must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-136-decision-receipt-chain-replay.md: AP-136 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanDecisionReceiptReplaySurfaces(): array
    {
        $commandPath = app_path('Console/Commands/AtlasAiDecisionReceiptReportCommand.php');
        $controllerPath = app_path('Http/Controllers/AtlasAiDecisionReceiptReportController.php');
        $routesPath = base_path('routes/api.php');
        $mcpPath = app_path('Services/Ai/AtlasOpenBrainMcpService.php');
        $catalogPath = app_path('Services/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalog.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiDecisionReceiptReportCommandTest.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiDecisionReceiptReportApiTest.php');
        $mcpTestPath = base_path('tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-137-decision-receipt-replay-surfaces.md');

        $command = File::exists($commandPath) ? File::get($commandPath) : '';
        $controller = File::exists($controllerPath) ? File::get($controllerPath) : '';
        $routes = File::exists($routesPath) ? File::get($routesPath) : '';
        $mcp = File::exists($mcpPath) ? File::get($mcpPath) : '';
        $reportToolsPath = app_path('Services/Ai/OpenBrainMcp/ReportTools.php');
        $reportTools = File::exists($reportToolsPath) ? File::get($reportToolsPath) : '';
        $catalog = File::exists($catalogPath) ? File::get($catalogPath) : '';
        $commandTest = File::exists($commandTestPath) ? File::get($commandTestPath) : '';
        $apiTest = File::exists($apiTestPath) ? File::get($apiTestPath) : '';
        $mcpTest = File::exists($mcpTestPath) ? File::get($mcpTestPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        $violations = [];

        foreach ([
            "protected \$signature = 'atlas:ai:decision-receipt-report",
            'decisionReceiptReportForEnvelope($envelopeId)',
            "'decision_receipt_replay' => \$report",
            'envelope_required',
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiDecisionReceiptReportCommand.php: AP-137 CLI surface must expose DecisionReceipt replay [{$token}]";
            }
        }

        foreach ([
            'class AtlasAiDecisionReceiptReportController',
            "'envelope' => ['required', 'string', 'max:160']",
            'decisionReceiptReportForEnvelope($data',
            "'decision_receipt_replay'",
        ] as $token) {
            if (! str_contains($controller, $token)) {
                $violations[] = "app/Http/Controllers/AtlasAiDecisionReceiptReportController.php: AP-137 API surface must expose DecisionReceipt replay [{$token}]";
            }
        }

        foreach ([
            'AtlasAiDecisionReceiptReportController::class',
            '/ai/decision-receipts/report',
        ] as $token) {
            if (! str_contains($routes, $token)) {
                $violations[] = "routes/api.php: AP-137 API route must be registered [{$token}]";
            }
        }

        // Façade keeps the tools() schema + dispatch; the handler was relocated under
        // GOD-DEBULK D3 to OpenBrainMcp/ReportTools (invariant unchanged).
        foreach ([
            "'name' => 'atlas_decision_receipt_report'",
            "'required' => ['envelope']",
            "'atlas_decision_receipt_report' => \$this->toolResponse(\$id, \$this->reportTools->decisionReceiptReport(\$arguments))",
        ] as $token) {
            if (! str_contains($mcp, $token)) {
                $violations[] = "app/Services/Ai/AtlasOpenBrainMcpService.php: AP-137 MCP tool must expose DecisionReceipt replay [{$token}]";
            }
        }

        foreach ([
            'public function decisionReceiptReport(array $arguments): array',
            "'decision_receipt_replay' => \$this->ledgerReplay->decisionReceiptReportForEnvelope(\$envelopeId)",
        ] as $token) {
            if (! str_contains($reportTools, $token)) {
                $violations[] = "app/Services/Ai/OpenBrainMcp/ReportTools.php: AP-137 MCP tool must expose DecisionReceipt replay [{$token}]";
            }
        }

        foreach ([
            "'id' => 'decision_receipt_report'",
            'php artisan atlas:ai:decision-receipt-report --envelope=<id> --json',
            'DecisionReceipt',
        ] as $token) {
            if (! str_contains($catalog, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalog.php: AP-137 architecture operations catalog must include DecisionReceipt replay [{$token}]";
            }
        }

        foreach ([
            'test_command_replays_decision_receipt_chain_as_json',
            'test_command_reports_invalid_input_without_envelope',
            'atlas:ai:decision-receipt-report',
        ] as $token) {
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiDecisionReceiptReportCommandTest.php: AP-137 CLI tests must cover DecisionReceipt replay [{$token}]";
            }
        }

        foreach ([
            'test_api_replays_decision_receipt_chain_for_envelope',
            'test_api_requires_atlas_token',
            '/ai/decision-receipts/report?envelope=env_api_receipt',
        ] as $token) {
            if (! str_contains($apiTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiDecisionReceiptReportApiTest.php: AP-137 API tests must cover DecisionReceipt replay [{$token}]";
            }
        }

        foreach ([
            'test_decision_receipt_report_replays_receipt_chain_for_envelope',
            'test_decision_receipt_report_requires_envelope',
            'atlas_decision_receipt_report',
            'recordDecisionReceiptForMcp',
        ] as $token) {
            if (! str_contains($mcpTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php: AP-137 MCP tests must cover DecisionReceipt replay [{$token}]";
            }
        }

        foreach ([
            'AP-137',
            'Decision Receipt Replay Surfaces',
            'atlas:ai:decision-receipt-report',
            'ap137_decision_receipt_replay_surfaces',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-137 DecisionReceipt replay surfaces must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-137-decision-receipt-replay-surfaces.md: AP-137 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanDecisionReceiptReplayCuratorReview(): array
    {
        $runtimePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $testPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-138-decision-receipt-replay-curator-review.md');

        $runtime = File::exists($runtimePath) ? File::get($runtimePath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        $violations = [];

        foreach ([
            'decisionReceiptReplayFindings($events, $filters)',
            'private function decisionReceiptReplayFindings(Collection $events, array $filters = []): array',
            'decisionReceiptReportForEnvelope($envelopeId)',
            'open_reviewable_decision_receipt_replay_proposal',
            'atlas.self_improvement.decision_receipt_replay_gap.v1',
            'matchesDecisionReceiptFilters',
            'normalizedDecisionReceiptFilters',
        ] as $token) {
            if (! str_contains($runtime, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: AP-138 Curator must consume DecisionReceipt replay findings [{$token}]";
            }
        }

        foreach ([
            'test_self_improvement_detects_decision_receipt_replay_hash_gap',
            'recordDecisionReceiptEvent',
            'atlas.self_improvement.decision_receipt_replay_gap.v1',
            'decision_receipt_chain_hash_mismatch',
            'open_reviewable_decision_receipt_replay_proposal',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: AP-138 Curator DecisionReceipt replay must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-138',
            'Decision Receipt Replay Curator Review',
            'decisionReceiptReplayFindings',
            'ap138_decision_receipt_replay_curator_review',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-138 DecisionReceipt replay Curator review must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-138-decision-receipt-replay-curator-review.md: AP-138 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanDecisionReceiptReplayInboxEmission(): array
    {
        $runtimePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $testPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-139-decision-receipt-replay-inbox-emission.md');

        $runtime = File::exists($runtimePath) ? File::get($runtimePath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        $violations = [];

        foreach ([
            '$this->proposals->emit',
            'emitted_to_inbox',
            'emitted_inbox_item_id',
            'LedgerEventType::LearningProposed',
            'emitted_inbox_item_ids',
        ] as $token) {
            if (! str_contains($runtime, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: AP-139 DecisionReceipt replay findings must flow through proposal emission [{$token}]";
            }
        }

        foreach ([
            'test_self_improvement_emits_decision_receipt_replay_hash_gap_proposal',
            'self-improvement:decision-receipt-replay:',
            'atlas.self_improvement.decision_receipt_replay_gap.v1',
            'open_reviewable_decision_receipt_replay_proposal',
            'emitted_to_inbox',
            'emitted_inbox_item_id',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: AP-139 DecisionReceipt replay proposal emission must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-139',
            'Decision Receipt Replay Inbox Emission',
            'test_self_improvement_emits_decision_receipt_replay_hash_gap_proposal',
            'ap139_decision_receipt_replay_inbox_emission',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-139 DecisionReceipt replay Inbox emission must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-139-decision-receipt-replay-inbox-emission.md: AP-139 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }
}
