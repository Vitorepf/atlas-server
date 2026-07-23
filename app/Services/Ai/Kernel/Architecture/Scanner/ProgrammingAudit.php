<?php

namespace App\Services\Ai\Kernel\Architecture\Scanner;

use Illuminate\Support\Facades\File;

class ProgrammingAudit
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
            'ap29_programming_surface_contract_factory' => fn (): array => $this->scanProgrammingSurfaceContractFactory(),
            'ap77_programming_iteration_policy_contract' => fn (): array => $this->scanProgrammingIterationPolicyContract(),
            'ap152_programming_plan_agent_behavior_contract' => fn (): array => $this->scanProgrammingPlanAgentBehaviorContract(),
            'ap153_programming_harness_agent_behavior_contract' => fn (): array => $this->scanProgrammingHarnessAgentBehaviorContract(),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function scanProgrammingSurfaceContractFactory(): array
    {
        $factoryPath = app_path('Services/Ai/Programming/ProgrammingSurfaceContractFactory.php');
        $testPath = base_path('tests/Unit/Ai/Programming/ProgrammingSurfaceContractFactoryTest.php');
        $devPath = app_path('Console/Commands/AtlasCliDevCommand.php');

        $factory = File::exists($factoryPath) ? File::get($factoryPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $dev = File::exists($devPath) ? File::get($devPath) : '';

        $violations = [];

        if (! File::exists($factoryPath)) {
            $violations[] = "missing programming surface contract factory [{$factoryPath}]";
        }

        foreach ([
            'final class ProgrammingSurfaceContractFactory',
            'public function forge(array $devPlan, array $result): array',
            "'schema_version' => 'atlas.cli_forge.contract.v1'",
            "'surface' => 'atlas_cli_forge'",
            "'profile' => 'forge'",
            "'flow' => 'programming.forge'",
            "'runtime' => 'engineering_harness'",
            "'orchestrator' => 'AtlasProgrammingOrchestrator'",
            "'executor' => data_get(\$devPlan, 'programming_session_plan.executor_decision.executor')",
            "'kernel_pipeline_flow' => data_get(\$devPlan, 'kernel_pipeline.input.safe_hints.flow')",
            "'kernel_pipeline_runtime' => data_get(\$devPlan, 'kernel_pipeline.input.safe_hints.runtime')",
            "'quality_required' => true",
            "'evidence_required' => true",
        ] as $token) {
            if (! str_contains($factory, $token)) {
                $violations[] = "app/Services/Ai/Programming/ProgrammingSurfaceContractFactory.php: Programming must own the shared forge_contract shape [{$token}]";
            }
        }

        foreach ([
            'test_forge_contract_preserves_programming_surface_flow_runtime_and_evidence_requirements',
            "'atlas.cli_forge.contract.v1'",
            "'atlas_cli_forge'",
            "'programming.forge'",
            "'engineering_harness'",
            "'evidence_required'",
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/Programming/ProgrammingSurfaceContractFactoryTest.php: forge contract factory needs explicit coverage [{$token}]";
            }
        }

        foreach ([
            "'schema_version' => 'atlas.cli_forge.contract.v1'",
            "'surface' => 'atlas_cli_forge'",
            "'kernel_pipeline_flow' => data_get(\$devPlan, 'kernel_pipeline.input.safe_hints.flow')",
        ] as $token) {
            if (str_contains($dev, $token)) {
                $violations[] = "app/Console/Commands/AtlasCliDevCommand.php: surface command must not inline forge_contract schema; use ProgrammingSurfaceContractFactory [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanProgrammingIterationPolicyContract(): array
    {
        $policyPath = app_path('Services/Ai/Programming/ProgrammingIterationPolicy.php');
        $cliPath = app_path('Console/Commands/AtlasCliDevCommand.php');
        $workflowPath = app_path('Services/Ai/Cli/AtlasCliDevWorkflowService.php');
        $orchestratorPath = app_path('Services/Ai/Programming/AtlasProgrammingOrchestrator.php');
        $surfaceFactoryPath = app_path('Services/Ai/Programming/ProgrammingSurfaceContractFactory.php');
        $surfaceBuilderPath = app_path('Services/Ai/Programming/AtlasProgrammingSurfaceCommandBuilder.php');
        $policyServicePath = app_path('Services/Ai/Policy/AtlasAiPolicyService.php');
        $workerPath = app_path('Services/Ai/AiWorker.php');
        $executionRequestPath = app_path('Services/Ai/Programming/ProgrammingExecutionRequest.php');
        $chatCommandPath = app_path('Console/Commands/AiChatCommand.php');
        $testPath = base_path('tests/Unit/Ai/Programming/ProgrammingIterationPolicyTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $policy = File::exists($policyPath) ? File::get($policyPath) : '';
        $cli = File::exists($cliPath) ? File::get($cliPath) : '';
        $workflow = File::exists($workflowPath) ? File::get($workflowPath) : '';
        $orchestrator = File::exists($orchestratorPath) ? File::get($orchestratorPath) : '';
        $surfaceFactory = File::exists($surfaceFactoryPath) ? File::get($surfaceFactoryPath) : '';
        $surfaceBuilder = File::exists($surfaceBuilderPath) ? File::get($surfaceBuilderPath) : '';
        $policyService = File::exists($policyServicePath) ? File::get($policyServicePath) : '';
        $worker = File::exists($workerPath) ? File::get($workerPath) : '';
        $executionRequest = File::exists($executionRequestPath) ? File::get($executionRequestPath) : '';
        $chatCommand = File::exists($chatCommandPath) ? File::get($chatCommandPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();

        foreach ([
            'final class ProgrammingIterationPolicy',
            'public const MAX_ITERATIONS = 10',
            'public const DEFAULT_DEV_ITERATIONS = 3',
            'public const MIN_COMPLETE_ITERATIONS = 2',
            'public const DEFAULT_FORGE_ITERATIONS = 5',
            'public const MIN_FORGE_ITERATIONS = 5',
            'public const MIN_REPAIR_ITERATIONS = 3',
            'public static function normalize(',
            'public static function forProfile(',
            'public static function forExecutionPolicy(',
            'public static function forRepairPolicy(',
        ] as $token) {
            if (! str_contains($policy, $token)) {
                $violations[] = "app/Services/Ai/Programming/ProgrammingIterationPolicy.php: programming iteration policy contract is incomplete [{$token}]";
            }
        }

        foreach ([
            ['ProgrammingIterationPolicy::forProfile($this->option(\'max-iterations\'), $programmingProfile)', $cli],
            ['ProgrammingIterationPolicy::normalize($maxIterations)', $workflow],
            ['ProgrammingIterationPolicy::forExecutionPolicy(', $orchestrator],
            ['ProgrammingIterationPolicy::forRepairPolicy(', $orchestrator],
            ['ProgrammingIterationPolicy::normalize($operatorOptions[\'max_iterations\'] ?? null)', $surfaceFactory],
            ['ProgrammingIterationPolicy::normalize($maxIterations)', $surfaceBuilder],
            ['ProgrammingIterationPolicy::forExecutionPolicy(', $policyService],
            ['ProgrammingIterationPolicy::forRepairPolicy(', $worker],
            ['ProgrammingIterationPolicy::forExecutionPolicy(', $executionRequest],
            ['ProgrammingIterationPolicy::forExecutionPolicy(', $chatCommand],
        ] as [$token, $contents]) {
            if (! str_contains($contents, $token)) {
                $violations[] = "Programming iteration policy consumer missing shared normalizer [{$token}]";
            }
        }

        foreach ([
            'test_normalizes_programming_iterations_with_canonical_caps',
            'test_profile_and_execution_policy_minimums_are_explicit',
            'test_programming_execution_request_uses_same_iteration_policy_for_harness_attempts',
            'ProgrammingIterationPolicy::MAX_ITERATIONS',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/Programming/ProgrammingIterationPolicyTest.php: programming iteration policy contract must be covered [{$token}]";
            }
        }

        foreach ([
            'programming iteration policy contract',
            'AP-77',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe programming iteration policy contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanProgrammingPlanAgentBehaviorContract(): array
    {
        $orchestratorPath = app_path('Services/Ai/Programming/AtlasProgrammingOrchestrator.php');
        $testPath = base_path('tests/Unit/Ai/AtlasProgrammingOrchestratorTest.php');
        $contractDocPath = base_path('docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md');
        $programmingDocPath = base_path('docs/engineering-knowledge-base/domains/programming.md');
        $apDocPath = base_path('docs/ap/AP-152-programming-plan-agent-behavior-contract.md');

        $orchestrator = File::exists($orchestratorPath) ? File::get($orchestratorPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $contractDoc = File::exists($contractDocPath) ? File::get($contractDocPath) : '';
        $programmingDoc = $this->programmingDomainDocumentationCorpus();
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';
        $violations = [];

        foreach ([
            'use App\Services\Ai\Kernel\Provider\AgentBehaviorContract',
            'private readonly AgentBehaviorContract $agentBehavior',
            "'agent_behavior_contract' => \$this->agentBehavior->toArray()",
            'repair_execution_contract',
        ] as $token) {
            if (! str_contains($orchestrator, $token)) {
                $violations[] = "app/Services/Ai/Programming/AtlasProgrammingOrchestrator.php: AP-152 Programming plan must carry behavior contract [{$token}]";
            }
        }

        foreach ([
            'agent_behavior_contract.contract_id',
            'atlas-ai.agent-behavior.v1',
            'Surgical Diff Discipline',
            'agent_behavior_contract.content_hash',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/AtlasProgrammingOrchestratorTest.php: AP-152 Programming plan behavior contract must be tested [{$token}]";
            }
        }

        foreach ([
            'ABC-5',
            'implemented',
            'AtlasProgrammingOrchestrator',
            'agent_behavior_contract',
            'AP-152',
        ] as $token) {
            if (! str_contains($contractDoc, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md: AP-152 must be reflected in behavior contract doc [{$token}]";
            }
        }

        foreach ([
            'agent_behavior_contract',
            'AP-152',
            'atlas-ai.agent-behavior.v1',
            'Surgical Diff Discipline',
            'Verifiable Goal Loop',
        ] as $token) {
            if (! str_contains($programmingDoc, $token)) {
                $violations[] = "docs/engineering-knowledge-base/domains/programming.md: AP-152 must be documented in Programming Domain [{$token}]";
            }
        }

        foreach ([
            'AP-152',
            'implemented-programming-plan-contract',
            'AtlasProgrammingOrchestrator::sessionPlan',
            'agent_behavior_contract',
            'KernelArchitectureStaticScanner::scanProgrammingPlanAgentBehaviorContract',
        ] as $token) {
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-152-programming-plan-agent-behavior-contract.md: AP-152 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanProgrammingHarnessAgentBehaviorContract(): array
    {
        $requestPath = app_path('Services/Ai/Programming/ProgrammingExecutionRequest.php');
        $harnessPath = app_path('Services/Engineering/EngineeringHarnessExecutionService.php');
        $testPath = base_path('tests/Feature/EngineeringHarnessRunnerTest.php');
        $contractDocPath = base_path('docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md');
        $programmingDocPath = base_path('docs/engineering-knowledge-base/domains/programming.md');
        $apDocPath = base_path('docs/ap/AP-153-programming-harness-agent-behavior-contract.md');

        $request = File::exists($requestPath) ? File::get($requestPath) : '';
        $harness = File::exists($harnessPath) ? File::get($harnessPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $contractDoc = File::exists($contractDocPath) ? File::get($contractDocPath) : '';
        $programmingDoc = $this->programmingDomainDocumentationCorpus();
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';
        $violations = [];

        foreach ([
            'use App\Services\Ai\Kernel\Provider\AgentBehaviorContract',
            'public function agentBehaviorContract(): array',
            'programming_message_plan.agent_behavior_contract',
            'dev_execution_plan.programming_session_plan.agent_behavior_contract',
            "'agent_behavior_contract' => \$this->agentBehaviorContract()",
        ] as $token) {
            if (! str_contains($request, $token)) {
                $violations[] = "app/Services/Ai/Programming/ProgrammingExecutionRequest.php: AP-153 request must expose harness behavior contract [{$token}]";
            }
        }

        foreach ([
            "'agent_behavior_contract' => \$request->agentBehaviorContract()",
            "'agent_behavior_contract' => \$options['agent_behavior_contract'] ?? null",
            "'agent_behavior_contract' => \$request->agentBehaviorContract()",
            'policy_contract_enforcement',
        ] as $token) {
            if (! str_contains($harness, $token)) {
                $violations[] = "app/Services/Engineering/EngineeringHarnessExecutionService.php: AP-153 harness must propagate behavior contract [{$token}]";
            }
        }

        foreach ([
            'policy_contract_enforcement.effective_options.agent_behavior_contract.contract_id',
            'engineering_contract.agent_behavior_contract.contract_id',
            'programming_orchestrator.agent_behavior_contract.contract_id',
            'atlas-ai.agent-behavior.v1',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/EngineeringHarnessRunnerTest.php: AP-153 harness behavior contract must be tested [{$token}]";
            }
        }

        foreach ([
            'ABC-6',
            'implemented',
            'ProgrammingExecutionRequest',
            'EngineeringHarnessExecutionService',
            'AP-153',
        ] as $token) {
            if (! str_contains($contractDoc, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md: AP-153 must be reflected in behavior contract doc [{$token}]";
            }
        }

        foreach ([
            'ProgrammingExecutionRequest',
            'EngineeringHarnessExecutionService',
            'AP-153',
            'agent_behavior_contract',
            'Harness executa e valida',
        ] as $token) {
            if (! str_contains($programmingDoc, $token)) {
                $violations[] = "docs/engineering-knowledge-base/domains/programming.md: AP-153 must be documented in Programming Domain [{$token}]";
            }
        }

        foreach ([
            'AP-153',
            'implemented-harness-contract',
            'ProgrammingExecutionRequest',
            'EngineeringHarnessExecutionService',
            'agent_behavior_contract',
            'KernelArchitectureStaticScanner::scanProgrammingHarnessAgentBehaviorContract',
        ] as $token) {
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-153-programming-harness-agent-behavior-contract.md: AP-153 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    private function programmingDomainDocumentationCorpus(): string
    {
        return $this->primitives->documentationCorpus([
            base_path('docs/engineering-knowledge-base/domains/programming.md'),
            base_path('docs/engineering-knowledge-base/domains/programming-surfaces.md'),
            base_path('docs/engineering-knowledge-base/domains/programming-repair-contract.md'),
            base_path('docs/engineering-knowledge-base/domains/programming-frontend-superpower.md'),
            base_path('docs/engineering-knowledge-base/archive/source-material/domains-programming-full-2026-05-08.md'),
        ]);
    }
}
