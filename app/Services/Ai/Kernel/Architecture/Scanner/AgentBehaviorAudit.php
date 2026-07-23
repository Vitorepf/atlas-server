<?php

namespace App\Services\Ai\Kernel\Architecture\Scanner;

use Illuminate\Support\Facades\File;

class AgentBehaviorAudit
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
            'ap148_agent_behavior_identity_fragment' => fn (): array => $this->scanAgentBehaviorIdentityFragment(),
            'ap149_agent_behavior_execution_plan' => fn (): array => $this->scanAgentBehaviorExecutionPlan(),
            'ap150_agent_behavior_quality_gate' => fn (): array => $this->scanAgentBehaviorQualityGate(),
            'ap151_agent_behavior_review_action_surface' => fn (): array => $this->scanAgentBehaviorReviewActionSurface(),
            'ap154_agent_behavior_evidence_ledger' => fn (): array => $this->scanAgentBehaviorEvidenceLedger(),
            'ap155_agent_behavior_replay_read_model' => fn (): array => $this->scanAgentBehaviorReplayReadModel(),
            'ap156_agent_behavior_mcp_report' => fn (): array => $this->scanAgentBehaviorMcpReport(),
            'ap157_agent_behavior_self_improvement_review' => fn (): array => $this->scanAgentBehaviorSelfImprovementReview(),
            'ap158_agent_behavior_direct_surfaces' => fn (): array => $this->scanAgentBehaviorDirectSurfaces(),
            'ap159_agent_behavior_dedicated_curator_flow' => fn (): array => $this->scanAgentBehaviorDedicatedCuratorFlow(),
            'ap160_agent_behavior_curator_filter_surface' => fn (): array => $this->scanAgentBehaviorCuratorFilterSurface(),
            'ap161_agent_behavior_recurring_schedule' => fn (): array => $this->scanAgentBehaviorRecurringSchedule(),
            'ap162_agent_behavior_proposal_governance' => fn (): array => $this->scanAgentBehaviorProposalGovernance(),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function scanAgentBehaviorIdentityFragment(): array
    {
        $contractPath = app_path('Services/Ai/Kernel/Provider/AgentBehaviorContract.php');
        $projectorPath = app_path('Services/Ai/Kernel/Provider/AtlasProviderIdentityProjector.php');
        $providerTestPath = base_path('tests/Unit/Ai/Provider/ProviderDriverWrappersTest.php');
        $contractDocPath = base_path('docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md');
        $apDocPath = base_path('docs/ap/AP-148-agent-behavior-identity-fragment.md');

        $contract = File::exists($contractPath) ? File::get($contractPath) : '';
        $projector = File::exists($projectorPath) ? File::get($projectorPath) : '';
        $providerTest = File::exists($providerTestPath) ? File::get($providerTestPath) : '';
        $contractDoc = File::exists($contractDocPath) ? File::get($contractDocPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';
        $violations = [];

        foreach ([
            'final readonly class AgentBehaviorContract',
            "public const CONTRACT_ID = 'atlas-ai.agent-behavior.v1'",
            'Assumption Management',
            'Simplicity Bias',
            'Surgical Diff Discipline',
            'Verifiable Goal Loop',
            'contentHash()',
        ] as $token) {
            if (! str_contains($contract, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Provider/AgentBehaviorContract.php: AP-148 behavior contract must be centralized and hashable [{$token}]";
            }
        }

        foreach ([
            'private readonly AgentBehaviorContract $agentBehavior',
            "'agent_behavior_contract' => \$this->agentBehavior->toArray()",
            'Behavior contract:',
            '$this->agentBehavior->text()',
        ] as $token) {
            if (! str_contains($projector, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Provider/AtlasProviderIdentityProjector.php: AP-148 behavior contract must be injected through IdentityFragment [{$token}]";
            }
        }

        foreach ([
            'Atlas AI Agent Behavior Contract v1',
            'Surgical Diff Discipline',
            'Verifiable Goal Loop',
            'agent_behavior_contract.contract_id',
            'atlas-ai.agent-behavior.v1',
        ] as $token) {
            if (! str_contains($providerTest, $token)) {
                $violations[] = "tests/Unit/Ai/Provider/ProviderDriverWrappersTest.php: AP-148 provider tests must prove behavior contract injection [{$token}]";
            }
        }

        foreach ([
            'ABC-1',
            'implemented',
            'AgentBehaviorContract',
            'atlas-ai.agent-behavior.v1',
            'AP-148',
        ] as $token) {
            if (! str_contains($contractDoc, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md: AP-148 must be reflected in the behavior contract doc [{$token}]";
            }
        }

        foreach ([
            'AP-148',
            'implemented-identity-contract',
            'AgentBehaviorContract',
            'AtlasProviderIdentityProjector',
            'identity_fragment.metadata.agent_behavior_contract',
            'ProviderDriverWrappersTest::test_prepare_request_injects_identity_fragment_into_payload',
        ] as $token) {
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-148-agent-behavior-identity-fragment.md: AP-148 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanAgentBehaviorExecutionPlan(): array
    {
        $executionPlanPath = app_path('Services/Ai/ValueObjects/AiPromptExecutionPlan.php');
        $harnessTestPath = base_path('tests/Unit/AiHarnessContractsTest.php');
        $contractDocPath = base_path('docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md');
        $apDocPath = base_path('docs/ap/AP-149-agent-behavior-execution-plan.md');

        $executionPlan = File::exists($executionPlanPath) ? File::get($executionPlanPath) : '';
        $harnessTest = File::exists($harnessTestPath) ? File::get($harnessTestPath) : '';
        $contractDoc = File::exists($contractDocPath) ? File::get($contractDocPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';
        $violations = [];

        foreach ([
            'use App\Services\Ai\Kernel\Provider\AgentBehaviorContract',
            '$agentBehavior = app(AgentBehaviorContract::class)',
            "'agent_behavior_contract' => \$agentBehavior->toArray()",
            '## Agent Behavior Contract',
            "'principles'",
        ] as $token) {
            if (! str_contains($executionPlan, $token)) {
                $violations[] = "app/Services/Ai/ValueObjects/AiPromptExecutionPlan.php: AP-149 execution plan must carry agent behavior contract [{$token}]";
            }
        }

        foreach ([
            'test_execution_plan_prompt_renders_agent_behavior_contract',
            'agent_behavior_contract.contract_id',
            'atlas-ai.agent-behavior.v1',
            'Surgical Diff Discipline',
            'Verifiable Goal Loop',
        ] as $token) {
            if (! str_contains($harnessTest, $token)) {
                $violations[] = "tests/Unit/AiHarnessContractsTest.php: AP-149 execution plan behavior contract must be covered [{$token}]";
            }
        }

        foreach ([
            'ABC-2',
            'implemented-initial',
            'AiExecutionPlan',
            'agent_behavior_contract',
            'AP-149',
        ] as $token) {
            if (! str_contains($contractDoc, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md: AP-149 must be reflected in behavior contract doc [{$token}]";
            }
        }

        foreach ([
            'AP-149',
            'implemented-initial-contract',
            'AiExecutionPlan::fromTask',
            'agent_behavior_contract',
            'KernelArchitectureStaticScanner::scanAgentBehaviorExecutionPlan',
        ] as $token) {
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-149-agent-behavior-execution-plan.md: AP-149 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanAgentBehaviorQualityGate(): array
    {
        $gatePath = app_path('Services/Ai/Kernel/Behavior/AgentBehaviorQualityGate.php');
        $evaluatorPath = app_path('Services/Ai/Analysis/AiQualityEvaluator.php');
        $gateTestPath = base_path('tests/Unit/Ai/Kernel/Behavior/AgentBehaviorQualityGateTest.php');
        $qualityTestPath = base_path('tests/Unit/AiQualityEvaluatorTest.php');
        $contractDocPath = base_path('docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md');
        $apDocPath = base_path('docs/ap/AP-150-agent-behavior-quality-gate.md');

        $gate = File::exists($gatePath) ? File::get($gatePath) : '';
        $evaluator = File::exists($evaluatorPath) ? File::get($evaluatorPath) : '';
        $gateTest = File::exists($gateTestPath) ? File::get($gateTestPath) : '';
        $qualityTest = File::exists($qualityTestPath) ? File::get($qualityTestPath) : '';
        $contractDoc = File::exists($contractDocPath) ? File::get($contractDocPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';
        $violations = [];

        foreach ([
            'final readonly class AgentBehaviorQualityGate',
            'AgentBehaviorContract',
            'agent.verification_missing',
            'agent.unsurgical_diff',
            'atlas.agent_behavior.finding.v1',
            'Surgical Diff Discipline',
            'Verifiable Goal Loop',
            'contract_hash',
        ] as $token) {
            if (! str_contains($gate, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Behavior/AgentBehaviorQualityGate.php: AP-150 behavior findings must be centralized and contract-hashable [{$token}]";
            }
        }

        foreach ([
            'private readonly AgentBehaviorQualityGate $agentBehaviorGate',
            '$agentBehaviorFindings = $this->agentBehaviorGate->evaluate',
            'agent_behavior_findings',
            'verification_missing',
        ] as $token) {
            if (! str_contains($evaluator, $token)) {
                $violations[] = "app/Services/Ai/Analysis/AiQualityEvaluator.php: AP-150 quality evaluator must consume behavior gate findings [{$token}]";
            }
        }

        foreach ([
            'AgentBehaviorQualityGateTest',
            'test_emits_verification_missing_finding_for_development_without_verification_signal',
            'test_emits_unsurgical_diff_finding_when_changed_file_is_outside_allowed_paths',
            'atlas.agent_behavior.finding.v1',
            'agent.unsurgical_diff',
        ] as $token) {
            if (! str_contains($gateTest, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/Behavior/AgentBehaviorQualityGateTest.php: AP-150 behavior gate must be covered [{$token}]";
            }
        }

        foreach ([
            'agent_behavior_findings.0.code',
            'agent.verification_missing',
        ] as $token) {
            if (! str_contains($qualityTest, $token)) {
                $violations[] = "tests/Unit/AiQualityEvaluatorTest.php: AP-150 evaluator integration must be covered [{$token}]";
            }
        }

        foreach ([
            'ABC-3',
            'implemented-initial',
            'AgentBehaviorQualityGate',
            'agent.verification_missing',
            'agent.unsurgical_diff',
            'AP-150',
        ] as $token) {
            if (! str_contains($contractDoc, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md: AP-150 must be reflected in behavior contract doc [{$token}]";
            }
        }

        foreach ([
            'AP-150',
            'implemented-initial-gate',
            'AgentBehaviorQualityGate',
            'agent.verification_missing',
            'agent.unsurgical_diff',
            'KernelArchitectureStaticScanner::scanAgentBehaviorQualityGate',
        ] as $token) {
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-150-agent-behavior-quality-gate.md: AP-150 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanAgentBehaviorReviewActionSurface(): array
    {
        $actionServicePath = app_path('Services/Ai/Analysis/AiQualityActionService.php');
        $actionTestPath = base_path('tests/Unit/AiQualityActionServiceTest.php');
        $contractDocPath = base_path('docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md');
        $apDocPath = base_path('docs/ap/AP-151-agent-behavior-review-action-surface.md');

        $actionService = File::exists($actionServicePath) ? File::get($actionServicePath) : '';
        $actionTest = File::exists($actionTestPath) ? File::get($actionTestPath) : '';
        $contractDoc = File::exists($contractDocPath) ? File::get($contractDocPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';
        $violations = [];

        foreach ([
            "'agent_behavior_findings' => \$this->agentBehaviorFindings(\$evaluation)",
            'private function agentBehaviorFindings(AiQualityEvaluation $evaluation): array',
            'evidence.agent_behavior_findings',
            "str_starts_with((string) (\$finding['code'] ?? ''), 'agent.')",
        ] as $token) {
            if (! str_contains($actionService, $token)) {
                $violations[] = "app/Services/Ai/Analysis/AiQualityActionService.php: AP-151 review actions must carry structured agent behavior findings [{$token}]";
            }
        }

        foreach ([
            'test_verification_action_carries_agent_behavior_findings_for_review',
            'agent_behavior_findings.0.code',
            'agent.verification_missing',
            'atlas.agent_behavior.finding.v1',
        ] as $token) {
            if (! str_contains($actionTest, $token)) {
                $violations[] = "tests/Unit/AiQualityActionServiceTest.php: AP-151 review action payload must be covered [{$token}]";
            }
        }

        foreach ([
            'ABC-4',
            'implemented-initial',
            'AiQualityActionService',
            'agent_behavior_findings',
            'AP-151',
        ] as $token) {
            if (! str_contains($contractDoc, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md: AP-151 must be reflected in behavior contract doc [{$token}]";
            }
        }

        foreach ([
            'AP-151',
            'implemented-initial-review-surface',
            'AiQualityActionService',
            'agent_behavior_findings',
            'KernelArchitectureStaticScanner::scanAgentBehaviorReviewActionSurface',
        ] as $token) {
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-151-agent-behavior-review-action-surface.md: AP-151 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanAgentBehaviorEvidenceLedger(): array
    {
        $ledgerPath = app_path('Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php');
        $evaluatorPath = app_path('Services/Ai/Analysis/AiQualityEvaluator.php');
        $testPath = base_path('tests/Unit/AiQualityEvaluatorTest.php');
        $contractDocPath = base_path('docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md');
        $apDocPath = base_path('docs/ap/AP-154-agent-behavior-evidence-ledger.md');

        $ledger = File::exists($ledgerPath) ? File::get($ledgerPath) : '';
        $evaluator = File::exists($evaluatorPath) ? File::get($evaluatorPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $contractDoc = File::exists($contractDocPath) ? File::get($contractDocPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';
        $violations = [];

        foreach ([
            'public function recordAgentBehaviorGateEvaluation(',
            'LedgerEventType::GateEvaluated',
            "'gate_id' => 'atlas.agent_behavior'",
            "'schema_version' => 'atlas.agent_behavior.gate_evaluation.v1'",
            "'agent_behavior_findings' => array_values(\$findings)",
            "'emitter_stage' => 'atlas.agent_behavior_quality_gate'",
        ] as $token) {
            if (! str_contains($ledger, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php: AP-154 must record agent behavior gate findings in the Evidence Ledger [{$token}]";
            }
        }

        foreach ([
            'private readonly AtlasEvidenceLedger $ledger',
            'recordAgentBehaviorGateEvaluation($trace, $evaluation, $findings)',
            'evidence.agent_behavior_findings',
        ] as $token) {
            if (! str_contains($evaluator, $token)) {
                $violations[] = "app/Services/Ai/Analysis/AiQualityEvaluator.php: AP-154 evaluator must emit agent behavior ledger signal [{$token}]";
            }
        }

        foreach ([
            'LedgerEventType::GateEvaluated->value',
            'atlas.agent_behavior_quality_gate',
            'atlas.agent_behavior.gate_evaluation.v1',
            'agent_behavior_findings.0.code',
            'atlas-ai.agent-behavior.v1',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/AiQualityEvaluatorTest.php: AP-154 ledger signal must be covered [{$token}]";
            }
        }

        foreach ([
            'ABC-7',
            'implemented',
            'AtlasEvidenceLedger::recordAgentBehaviorGateEvaluation',
            'GATE_EVALUATED',
            'AP-154',
        ] as $token) {
            if (! str_contains($contractDoc, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md: AP-154 must be reflected in behavior contract doc [{$token}]";
            }
        }

        foreach ([
            'AP-154',
            'implemented-ledger-signal',
            'AtlasEvidenceLedger::recordAgentBehaviorGateEvaluation',
            'GATE_EVALUATED',
            'atlas.agent_behavior.gate_evaluation.v1',
            'KernelArchitectureStaticScanner::scanAgentBehaviorEvidenceLedger',
        ] as $token) {
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-154-agent-behavior-evidence-ledger.md: AP-154 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanAgentBehaviorReplayReadModel(): array
    {
        $replayPath = app_path('Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php');
        $testPath = base_path('tests/Unit/Ai/Kernel/LedgerReplayServiceTest.php');
        $contractDocPath = base_path('docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md');
        $apDocPath = base_path('docs/ap/AP-155-agent-behavior-replay-read-model.md');

        $replay = File::exists($replayPath) ? File::get($replayPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $contractDoc = File::exists($contractDocPath) ? File::get($contractDocPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';
        $violations = [];

        foreach ([
            'public function agentBehaviorReportForWindow(',
            'agentBehaviorEventFromEvent',
            'agentBehaviorSummary',
            'agentBehaviorReviewSignal',
            'normalizedAgentBehaviorFilters',
            'matchesAgentBehaviorFilters',
            'open_reviewable_agent_behavior_quality_proposal',
        ] as $token) {
            if (! str_contains($replay, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php: AP-155 must expose agent behavior replay read model [{$token}]";
            }
        }

        foreach ([
            'test_agent_behavior_window_report_projects_gate_findings',
            'recordAgentBehaviorGateEvent',
            'agentBehaviorReportForWindow',
            'finding_code_counts',
            'open_reviewable_agent_behavior_quality_proposal',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/LedgerReplayServiceTest.php: AP-155 replay read model must be covered [{$token}]";
            }
        }

        foreach ([
            'ABC-8',
            'implemented',
            'AtlasLedgerReplayService::agentBehaviorReportForWindow',
            'open_reviewable_agent_behavior_quality_proposal',
            'AP-155',
        ] as $token) {
            if (! str_contains($contractDoc, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md: AP-155 must be reflected in behavior contract doc [{$token}]";
            }
        }

        foreach ([
            'AP-155',
            'implemented-read-model',
            'AtlasLedgerReplayService::agentBehaviorReportForWindow',
            'finding_code_counts',
            'KernelArchitectureStaticScanner::scanAgentBehaviorReplayReadModel',
        ] as $token) {
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-155-agent-behavior-replay-read-model.md: AP-155 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanAgentBehaviorMcpReport(): array
    {
        $mcpPath = app_path('Services/Ai/AtlasOpenBrainMcpService.php');
        $testPath = base_path('tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php');
        $contractDocPath = base_path('docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md');
        $apDocPath = base_path('docs/ap/AP-156-agent-behavior-mcp-report.md');

        $reportToolsPath = app_path('Services/Ai/OpenBrainMcp/ReportTools.php');

        $mcp = File::exists($mcpPath) ? File::get($mcpPath) : '';
        $reportTools = File::exists($reportToolsPath) ? File::get($reportToolsPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $contractDoc = File::exists($contractDocPath) ? File::get($contractDocPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';
        $violations = [];

        // Façade keeps the tools() schema + dispatch; the handler was relocated under
        // GOD-DEBULK D3 to OpenBrainMcp/ReportTools (invariant unchanged).
        foreach ([
            "'name' => 'atlas_agent_behavior_report'",
            "'atlas_agent_behavior_report' => \$this->toolResponse(\$id, \$this->reportTools->agentBehaviorReport(\$arguments))",
        ] as $token) {
            if (! str_contains($mcp, $token)) {
                $violations[] = "app/Services/Ai/AtlasOpenBrainMcpService.php: AP-156 must expose agent behavior MCP report [{$token}]";
            }
        }

        foreach ([
            'public function agentBehaviorReport(array $arguments): array',
            'agentBehaviorReportForWindow',
            "'agent_behavior' => \$report",
        ] as $token) {
            if (! str_contains($reportTools, $token)) {
                $violations[] = "app/Services/Ai/OpenBrainMcp/ReportTools.php: AP-156 must expose agent behavior MCP report [{$token}]";
            }
        }

        foreach ([
            'test_agent_behavior_report_summarizes_gate_findings',
            'recordAgentBehaviorForMcp',
            'atlas_agent_behavior_report',
            'agent_behavior.finding_code_counts',
            'open_reviewable_agent_behavior_quality_proposal',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php: AP-156 MCP report must be covered [{$token}]";
            }
        }

        foreach ([
            'ABC-9',
            'implemented',
            'atlas_agent_behavior_report',
            'AP-156',
        ] as $token) {
            if (! str_contains($contractDoc, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md: AP-156 must be reflected in behavior contract doc [{$token}]";
            }
        }

        foreach ([
            'AP-156',
            'implemented-mcp-report',
            'atlas_agent_behavior_report',
            'AtlasLedgerReplayService::agentBehaviorReportForWindow',
            'KernelArchitectureStaticScanner::scanAgentBehaviorMcpReport',
        ] as $token) {
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-156-agent-behavior-mcp-report.md: AP-156 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanAgentBehaviorSelfImprovementReview(): array
    {
        $runtimePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $testPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $contractDocPath = base_path('docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md');
        $apDocPath = base_path('docs/ap/AP-157-agent-behavior-self-improvement-review.md');

        $runtime = File::exists($runtimePath) ? File::get($runtimePath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $contractDoc = File::exists($contractDocPath) ? File::get($contractDocPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';
        $violations = [];

        foreach ([
            'private function agentBehaviorReplayFindings(',
            'agentBehaviorReportForWindow',
            'atlas.self_improvement.agent_behavior_replay.v1',
            'open_reviewable_agent_behavior_quality_proposal',
            'normalizedAgentBehaviorFilters',
            'self-improvement:agent-behavior-replay',
        ] as $token) {
            if (! str_contains($runtime, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: AP-157 must let Self-Improvement consume agent behavior replay [{$token}]";
            }
        }

        foreach ([
            'test_self_improvement_detects_agent_behavior_replay_patterns',
            'recordAgentBehaviorGateEvent',
            'agent.verification_missing',
            'atlas.self_improvement.agent_behavior_replay.v1',
            'open_reviewable_agent_behavior_quality_proposal',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: AP-157 Self-Improvement review must be covered [{$token}]";
            }
        }

        foreach ([
            'ABC-10',
            'implemented',
            'agentBehaviorReplayFindings',
            'AP-157',
        ] as $token) {
            if (! str_contains($contractDoc, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md: AP-157 must be reflected in behavior contract doc [{$token}]";
            }
        }

        foreach ([
            'AP-157',
            'implemented-self-improvement-review',
            'agentBehaviorReportForWindow',
            'atlas.self_improvement.agent_behavior_replay.v1',
            'KernelArchitectureStaticScanner::scanAgentBehaviorSelfImprovementReview',
        ] as $token) {
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-157-agent-behavior-self-improvement-review.md: AP-157 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanAgentBehaviorDirectSurfaces(): array
    {
        $commandPath = app_path('Console/Commands/AtlasAiAgentBehaviorReportCommand.php');
        $controllerPath = app_path('Http/Controllers/AtlasAiAgentBehaviorReportController.php');
        $routesPath = base_path('routes/api.php');
        $catalogPath = app_path('Services/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalog.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiAgentBehaviorReportCommandTest.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiAgentBehaviorReportApiTest.php');
        $contractDocPath = base_path('docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md');
        $apDocPath = base_path('docs/ap/AP-158-agent-behavior-direct-surfaces.md');

        $command = File::exists($commandPath) ? File::get($commandPath) : '';
        $controller = File::exists($controllerPath) ? File::get($controllerPath) : '';
        $routes = File::exists($routesPath) ? File::get($routesPath) : '';
        $catalog = File::exists($catalogPath) ? File::get($catalogPath) : '';
        $commandTest = File::exists($commandTestPath) ? File::get($commandTestPath) : '';
        $apiTest = File::exists($apiTestPath) ? File::get($apiTestPath) : '';
        $contractDoc = File::exists($contractDocPath) ? File::get($contractDocPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';
        $violations = [];

        foreach ([
            'atlas:ai:agent-behavior-report',
            'agentBehaviorReportForWindow',
            "'agent_behavior' => \$report",
            'finding-code',
            'contract-id',
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiAgentBehaviorReportCommand.php: AP-158 must expose CLI report [{$token}]";
            }
        }

        foreach ([
            'class AtlasAiAgentBehaviorReportController',
            'agentBehaviorReportForWindow',
            "'agent_behavior' => \$report",
            'finding_code',
            'contract_id',
        ] as $token) {
            if (! str_contains($controller, $token)) {
                $violations[] = "app/Http/Controllers/AtlasAiAgentBehaviorReportController.php: AP-158 must expose API report [{$token}]";
            }
        }

        foreach ([
            'AtlasAiAgentBehaviorReportController',
            '/ai/agent-behavior/report',
        ] as $token) {
            if (! str_contains($routes, $token)) {
                $violations[] = "routes/api.php: AP-158 route must be registered [{$token}]";
            }
        }

        foreach ([
            'agent_behavior_report',
            'php artisan atlas:ai:agent-behavior-report --hours=24 --json',
        ] as $token) {
            if (! str_contains($catalog, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalog.php: AP-158 must be discoverable in operations catalog [{$token}]";
            }
        }

        foreach ([
            'AtlasAiAgentBehaviorReportCommandTest',
            'test_command_summarizes_agent_behavior_report_as_json',
            'atlas:ai:agent-behavior-report',
            'open_reviewable_agent_behavior_quality_proposal',
        ] as $token) {
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiAgentBehaviorReportCommandTest.php: AP-158 CLI surface must be covered [{$token}]";
            }
        }

        foreach ([
            'AtlasAiAgentBehaviorReportApiTest',
            '/ai/agent-behavior/report',
            'test_agent_behavior_report_api_returns_filtered_window_summary',
            'open_reviewable_agent_behavior_quality_proposal',
        ] as $token) {
            if (! str_contains($apiTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiAgentBehaviorReportApiTest.php: AP-158 API surface must be covered [{$token}]";
            }
        }

        foreach ([
            'ABC-11',
            'implemented',
            'atlas:ai:agent-behavior-report',
            'AP-158',
        ] as $token) {
            if (! str_contains($contractDoc, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md: AP-158 must be reflected in behavior contract doc [{$token}]";
            }
        }

        foreach ([
            'AP-158',
            'implemented-direct-surfaces',
            'atlas:ai:agent-behavior-report',
            '/ai/agent-behavior/report',
            'KernelArchitectureStaticScanner::scanAgentBehaviorDirectSurfaces',
        ] as $token) {
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-158-agent-behavior-direct-surfaces.md: AP-158 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanAgentBehaviorDedicatedCuratorFlow(): array
    {
        $orchestratorPath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementOrchestrator.php');
        $runtimePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $profileRegistryPath = app_path('Services/Ai/Policy/AtlasDomainProfileRegistry.php');
        $configPath = config_path('atlas_ai.php');
        $catalogPath = app_path('Services/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalog.php');
        $mcpTestPath = base_path('tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php');
        $helpTestPath = base_path('tests/Feature/AtlasCliHelpCommandTest.php');
        $orchestratorTestPath = base_path('tests/Unit/Ai/AtlasSelfImprovementOrchestratorTest.php');
        $runtimeTestPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $catalogTestPath = base_path('tests/Unit/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalogTest.php');
        $domainProfileTestPath = base_path('tests/Feature/Architecture/DomainProfileComplianceTest.php');
        $domainDocPath = base_path('docs/engineering-knowledge-base/domains/self-improvement.md');
        $contractDocPath = base_path('docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md');
        $kernelDocPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-159-agent-behavior-dedicated-curator-flow.md');

        $orchestrator = File::exists($orchestratorPath) ? File::get($orchestratorPath) : '';
        $runtime = File::exists($runtimePath) ? File::get($runtimePath) : '';
        $profileRegistry = File::exists($profileRegistryPath) ? File::get($profileRegistryPath) : '';
        $config = File::exists($configPath) ? File::get($configPath) : '';
        $catalog = File::exists($catalogPath) ? File::get($catalogPath) : '';
        $mcpTest = File::exists($mcpTestPath) ? File::get($mcpTestPath) : '';
        $helpTest = File::exists($helpTestPath) ? File::get($helpTestPath) : '';
        $orchestratorTest = File::exists($orchestratorTestPath) ? File::get($orchestratorTestPath) : '';
        $runtimeTest = File::exists($runtimeTestPath) ? File::get($runtimeTestPath) : '';
        $catalogTest = File::exists($catalogTestPath) ? File::get($catalogTestPath) : '';
        $domainProfileTest = File::exists($domainProfileTestPath) ? File::get($domainProfileTestPath) : '';
        $domainDoc = $this->primitives->selfImprovementDomainDocumentationCorpus();
        $contractDoc = File::exists($contractDocPath) ? File::get($contractDocPath) : '';
        $kernelDoc = $this->primitives->kernelDocumentationCorpus();
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';
        $violations = [];

        foreach (["'self_improvement.agent_behavior_review'", 'SUPPORTED_FLOWS'] as $token) {
            if (! str_contains($orchestrator, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementOrchestrator.php: AP-159 dedicated agent behavior flow must be supported [{$token}]";
            }
        }

        foreach ([
            "'self_improvement.agent_behavior_review' => [",
            '...$this->agentBehaviorReplayFindings($hours, $filters)',
            'atlas.self_improvement.agent_behavior_replay.v1',
            'agentBehaviorReportForWindow',
        ] as $token) {
            if (! str_contains($runtime, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: AP-159 runtime must route dedicated flow to agent behavior replay only [{$token}]";
            }
        }

        foreach (["'self_improvement.agent_behavior_review'", "'Agent Behavior Review'", "'agent_behavior_review_runtime'"] as $token) {
            if (! str_contains($profileRegistry, $token)) {
                $violations[] = "app/Services/Ai/Policy/AtlasDomainProfileRegistry.php: AP-159 domain profile must declare agent behavior review [{$token}]";
            }
        }

        if (! str_contains($config, "'self_improvement.agent_behavior_review'")) {
            $violations[] = "config/atlas_ai.php: AP-159 orchestrator config must declare agent behavior review ['self_improvement.agent_behavior_review']";
        }

        foreach ([
            'agent_behavior_curator_review',
            'php artisan atlas:ai:self-improve --flow=agent_behavior_review --hours=168 --json',
            'curator_review',
        ] as $token) {
            if (! str_contains($catalog, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalog.php: AP-159 operation must be discoverable [{$token}]";
            }
            if (! str_contains($catalogTest, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalogTest.php: AP-159 catalog operation must be tested [{$token}]";
            }
        }

        foreach (['php artisan atlas:ai:self-improve --flow=agent_behavior_review --hours=168 --json'] as $token) {
            if (! str_contains($mcpTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php: AP-159 MCP catalog parity must be covered [{$token}]";
            }
            if (! str_contains($helpTest, $token)) {
                $violations[] = "tests/Feature/AtlasCliHelpCommandTest.php: AP-159 CLI help discovery must be covered [{$token}]";
            }
        }

        foreach ([
            'test_agent_behavior_review_plan_uses_dedicated_executor_contract',
            'self_improvement.agent_behavior_review',
            'agent_behavior_review_runtime',
        ] as $token) {
            if (! str_contains($orchestratorTest, $token)) {
                $violations[] = "tests/Unit/Ai/AtlasSelfImprovementOrchestratorTest.php: AP-159 orchestrator plan must be covered [{$token}]";
            }
        }

        foreach ([
            'test_self_improvement_detects_agent_behavior_replay_patterns',
            "flow: 'self_improvement.agent_behavior_review'",
            'self_improvement.agent_behavior_review',
            'open_reviewable_agent_behavior_quality_proposal',
        ] as $token) {
            if (! str_contains($runtimeTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: AP-159 runtime flow must be covered [{$token}]";
            }
        }

        foreach (['self_improvement.agent_behavior_review'] as $token) {
            if (! str_contains($domainProfileTest, $token)) {
                $violations[] = "tests/Feature/Architecture/DomainProfileComplianceTest.php: AP-159 domain profile compliance must include flow [{$token}]";
            }
            if (! str_contains($domainDoc, $token)) {
                $violations[] = "docs/engineering-knowledge-base/domains/self-improvement.md: AP-159 domain docs must include flow [{$token}]";
            }
        }

        foreach (['ABC-12', 'AP-159', 'self_improvement.agent_behavior_review', 'php artisan atlas:ai:self-improve --flow=agent_behavior_review --hours=168 --json'] as $token) {
            if (! str_contains($contractDoc, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md: AP-159 behavior contract doc must include dedicated flow [{$token}]";
            }
        }

        foreach (['AP-159', 'agent_behavior_curator_review', 'self_improvement.agent_behavior_review'] as $token) {
            if (! str_contains($kernelDoc, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-159 kernel architecture doc must include flow [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-159-agent-behavior-dedicated-curator-flow.md: AP-159 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanAgentBehaviorCuratorFilterSurface(): array
    {
        $commandPath = app_path('Console/Commands/AtlasAiSelfImproveCommand.php');
        $orchestratorPath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementOrchestrator.php');
        $runtimePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $testPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $apDocPath = base_path('docs/ap/AP-160-agent-behavior-curator-filter-surface.md');
        $ap159DocPath = base_path('docs/ap/AP-159-agent-behavior-dedicated-curator-flow.md');

        $command = File::exists($commandPath) ? File::get($commandPath) : '';
        $orchestrator = File::exists($orchestratorPath) ? File::get($orchestratorPath) : '';
        $runtime = File::exists($runtimePath) ? File::get($runtimePath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';
        $ap159Doc = File::exists($ap159DocPath) ? File::get($ap159DocPath) : '';
        $violations = [];

        foreach ([
            '{--agent-status= : Filter Agent Behavior findings by gate status}',
            '{--agent-slug= : Filter Agent Behavior findings by agent slug}',
            '{--finding-code= : Filter Agent Behavior findings by finding code}',
            '{--contract-id= : Filter Agent Behavior findings by behavior contract id}',
            "'agent-status' => 'status'",
            "'agent-slug' => 'agent_slug'",
            "'finding-code' => 'finding_code'",
            "'contract-id' => 'contract_id'",
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiSelfImproveCommand.php: AP-160 self-improve CLI must expose agent behavior filters [{$token}]";
            }
        }

        foreach ([
            "'agent_slug' => ['agent_slug']",
            "'finding_code' => ['finding_code']",
            "'contract_id' => ['contract_id']",
            "'agent_slug', 'finding_code', 'contract_id'",
        ] as $token) {
            if (! str_contains($orchestrator, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementOrchestrator.php: AP-160 orchestrator must preserve agent behavior filters [{$token}]";
            }
        }

        foreach ([
            'normalizedAgentBehaviorFilters',
            "'agent_slug'",
            "'finding_code'",
            "'contract_id'",
        ] as $token) {
            if (! str_contains($runtime, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: AP-160 runtime must normalize agent behavior filters [{$token}]";
            }
        }

        foreach ([
            'test_command_filters_agent_behavior_review_by_behavior_dimensions',
            "'--agent-slug' => 'programming_agent'",
            "'--finding-code' => 'agent.verification_missing'",
            "'--contract-id' => 'atlas-ai.agent-behavior.v1'",
            "'--agent-status' => 'needs_review'",
            "'agent_slug' => 'programming_agent'",
            "'finding_code' => 'agent.verification_missing'",
            "'contract_id' => 'atlas-ai.agent-behavior.v1'",
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: AP-160 CLI filter contract must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-160',
            'agent behavior filters',
            '--agent-slug',
            '--finding-code',
            '--contract-id',
            '--agent-status',
            'ap160_agent_behavior_curator_filter_surface',
        ] as $token) {
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-160-agent-behavior-curator-filter-surface.md: AP-160 contract doc must exist [{$token}]";
            }
        }

        foreach (['AP-160', '--agent-slug', '--finding-code', '--contract-id', '--agent-status'] as $token) {
            if (! str_contains($ap159Doc, $token)) {
                $violations[] = "docs/ap/AP-159-agent-behavior-dedicated-curator-flow.md: AP-160 must update AP-159 flow usage docs [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanAgentBehaviorRecurringSchedule(): array
    {
        $configPath = config_path('atlas_ai.php');
        $schedulePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementScheduleService.php');
        $unitTestPath = base_path('tests/Unit/Ai/AtlasSelfImprovementScheduleServiceTest.php');
        $featureTestPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiSelfImprovementScheduleApiTest.php');
        $mcpTestPath = base_path('tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php');
        $observabilityTestPath = base_path('tests/Feature/Ai/AiObservabilityKernelSloTest.php');
        $kernelDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $indexDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md');
        $domainDocsPath = base_path('docs/engineering-knowledge-base/domains/self-improvement.md');
        $apDocPath = base_path('docs/ap/AP-161-agent-behavior-recurring-schedule.md');

        $config = File::exists($configPath) ? File::get($configPath) : '';
        $schedule = File::exists($schedulePath) ? File::get($schedulePath) : '';
        $unitTest = File::exists($unitTestPath) ? File::get($unitTestPath) : '';
        $featureTest = File::exists($featureTestPath) ? File::get($featureTestPath) : '';
        $apiTest = File::exists($apiTestPath) ? File::get($apiTestPath) : '';
        $mcpTest = File::exists($mcpTestPath) ? File::get($mcpTestPath) : '';
        $observabilityTest = File::exists($observabilityTestPath) ? File::get($observabilityTestPath) : '';
        $kernelDocs = $this->primitives->kernelDocumentationCorpus();
        $indexDocs = File::exists($indexDocsPath) ? File::get($indexDocsPath) : '';
        $domainDocs = $this->primitives->selfImprovementDomainDocumentationCorpus();
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';
        $tests = implode("\n", [$unitTest, $featureTest, $apiTest, $mcpTest, $observabilityTest]);
        $docs = implode("\n", [$kernelDocs, $indexDocs, $domainDocs, $apDoc]);
        $violations = [];

        foreach ([
            'nightly_review,weekly_architecture_audit,repair_loop_review,kernel_pipeline_review,agent_behavior_review,provider_release_review,voice_realtime_review',
            "'self_improvement.agent_behavior_review'",
        ] as $token) {
            if (! str_contains($config, $token)) {
                $violations[] = "config/atlas_ai.php: AP-161 default config must keep agent behavior in the recurring Self-Improvement schedule [{$token}]";
            }
        }

        foreach ([
            "'agent_behavior_review'",
            "'provider_release_review'",
            "'voice_realtime_review'",
            'Restore the default nightly_review, weekly_architecture_audit, repair_loop_review, kernel_pipeline_review, agent_behavior_review, provider_release_review, and voice_realtime_review schedule.',
            'public function defaultFlows(): array',
        ] as $token) {
            if (! str_contains($schedule, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementScheduleService.php: AP-161 schedule service must include agent_behavior_review by default [{$token}]";
            }
        }

        foreach ([
            'atlas:ai:self-improve --flow=agent_behavior_review --hours=24 --limit=5 --json',
            'registered_command_count',
            "['daily' => 4, 'weekly' => 1]",
            'agent_behavior_review',
            'commands.4.command',
        ] as $token) {
            if (! str_contains($tests, $token)) {
                $violations[] = "tests: AP-161 recurring agent behavior schedule must be locked by schedule/API/MCP/observability tests [{$token}]";
            }
        }

        foreach ([
            'AP-161',
            'agent_behavior_review',
            '13 flow profiles',
            'default recorrente',
            'ap161_agent_behavior_recurring_schedule',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs: AP-161 recurring agent behavior schedule must be documented in AP/kernel/index/domain docs [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanAgentBehaviorProposalGovernance(): array
    {
        $runtimePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $testPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $kernelDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $behaviorDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md');
        $apDocPath = base_path('docs/ap/AP-162-agent-behavior-proposal-governance.md');

        $runtime = File::exists($runtimePath) ? File::get($runtimePath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $kernelDocs = $this->primitives->kernelDocumentationCorpus();
        $behaviorDocs = File::exists($behaviorDocsPath) ? File::get($behaviorDocsPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';
        $docs = implode("\n", [$kernelDocs, $behaviorDocs, $apDoc]);
        $violations = [];

        foreach ([
            "'available_actions' => \$availableActions",
            "'auto_apply_behavior_change' => false",
            "'requires_operator_review' => true",
            "'requires_architecture_validate' => true",
            "'agent_behavior_replay' => [",
            'atlas.self_improvement.agent_behavior_replay.proposal_payload.v1',
            "'critical_behavior_change_requires_human_review' => true",
        ] as $token) {
            if (! str_contains($runtime, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: AP-162 agent behavior proposal must preserve review governance [{$token}]";
            }
        }

        foreach ([
            'test_self_improvement_emits_agent_behavior_replay_proposal_with_review_governance',
            'atlas.self_improvement.agent_behavior_replay.v1',
            'open_reviewable_agent_behavior_quality_proposal',
            "'review_patch'",
            "'discuss'",
            "'discard'",
            'auto_apply_behavior_change',
            'requires_architecture_validate',
            'emitted_to_inbox',
            'emitted_inbox_item_id',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: AP-162 inbox emission and governance must be tested [{$token}]";
            }
        }

        foreach ([
            'AP-162',
            'Agent Behavior Proposal Governance',
            'auto_apply_behavior_change=false',
            'atlas.self_improvement.agent_behavior_replay.proposal_payload.v1',
            'ap162_agent_behavior_proposal_governance',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs: AP-162 agent behavior proposal governance must be documented [{$token}]";
            }
        }

        return $violations;
    }
}
