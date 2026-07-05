<?php

namespace App\Services\Ai\ToolRuntime;

use App\Models\AiToolCapability;
use App\Models\AiToolDefinition;
use App\Models\AiToolHealthCheck;
use App\Models\AiToolInvocation;
use App\Models\AiToolPlan;
use App\Models\AiToolReceipt;
use App\Models\AiToolValidationRun;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Contracts\Container\Container;

class ToolRuntimeReadinessService
{
    use \App\Services\Ai\Support\BuildsReadinessChecks;

    private const REQUIRED_TABLES = [
        'ai_tool_definitions',
        'ai_tool_capabilities',
        'ai_tool_plans',
        'ai_tool_invocations',
        'ai_tool_receipts',
        'ai_tool_health_checks',
        'ai_tool_validation_runs',
    ];

    private const REQUIRED_MODELS = [
        AiToolDefinition::class,
        AiToolCapability::class,
        AiToolPlan::class,
        AiToolInvocation::class,
        AiToolReceipt::class,
        AiToolHealthCheck::class,
        AiToolValidationRun::class,
    ];

    private const REQUIRED_SERVICES = [
        ToolDefinitionRegistryService::class,
        ToolCapabilityCatalogService::class,
        ToolPlanningService::class,
        ToolPolicyBridgeService::class,
        ToolInvocationService::class,
        ToolReceiptService::class,
        ToolHealthService::class,
        ToolValidationService::class,
        ToolRuntimeControlPlaneService::class,
    ];

    private const REQUIRED_TEST_FILES = [
        'tests/Feature/Ai/ToolRuntime/ToolRuntimeReadinessTest.php',
        'tests/Feature/Ai/ToolRuntime/ToolRuntimeSeedDefaultsTest.php',
        'tests/Feature/Ai/ToolRuntime/ToolRuntimeRegistryUniquenessTest.php',
        'tests/Feature/Ai/ToolRuntime/ToolRuntimeCapabilityCatalogTest.php',
        'tests/Feature/Ai/ToolRuntime/ToolRuntimePlanningTest.php',
        'tests/Feature/Ai/ToolRuntime/ToolRuntimePolicyBridgeTest.php',
        'tests/Feature/Ai/ToolRuntime/ToolRuntimeInvocationHashTest.php',
        'tests/Feature/Ai/ToolRuntime/ToolRuntimeReceiptHashTest.php',
        'tests/Feature/Ai/ToolRuntime/ToolRuntimeHealthDoctorTest.php',
        'tests/Feature/Ai/ToolRuntime/ToolRuntimeValidationRunTest.php',
        'tests/Feature/Ai/ToolRuntime/ToolRuntimeControlPlaneTest.php',
        'tests/Feature/Ai/ToolRuntime/ToolRuntimeNoExternalExecutionTest.php',
    ];

    public function __construct(
        private readonly Container $container,
        private readonly ToolPolicyBridgeService $policyBridge,
        private readonly ToolReceiptService $receipts,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function report(): array
    {
        $checks = [];

        $checks = array_merge($checks, $this->tableChecks(self::REQUIRED_TABLES));

        $checks = array_merge($checks, $this->modelChecks(self::REQUIRED_MODELS));

        $checks = array_merge($checks, $this->serviceChecks($this->container, self::REQUIRED_SERVICES));

        foreach (self::REQUIRED_TEST_FILES as $relativePath) {
            $exists = file_exists(base_path($relativePath));
            $checks[] = [
                'name' => 'test:'.basename($relativePath),
                'status' => $exists ? 'passed' : 'failed',
                'detail' => $exists ? 'present' : "missing [{$relativePath}]",
            ];
        }

        $checks[] = $this->checkSeedToolsPresent();
        $checks[] = $this->checkPolicyBridge();
        $checks[] = $this->checkEvidenceBridge();

        $passed = collect($checks)->where('status', 'passed')->count();
        $failed = collect($checks)->where('status', 'failed')->count();

        return [
            'ok' => $failed === 0,
            'schema' => 'atlas.ai.tool_runtime.readiness.v1',
            'summary' => [
                'total' => count($checks),
                'passed' => $passed,
                'failed' => $failed,
            ],
            'checks' => $checks,
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkSeedToolsPresent(): array
    {
        $expected = collect(ToolSeedDefinitions::all())->pluck('tool_id')->all();
        $tablePresent = DatabaseTableAvailability::has('ai_tool_definitions');
        $present = $tablePresent
            ? AiToolDefinition::query()->whereIn('tool_id', $expected)->pluck('tool_id')->all()
            : [];
        $missing = array_values(array_diff($expected, $present));
        $passes = $tablePresent && $missing === [];

        return [
            'name' => 'seed:default_tools',
            'status' => $passes ? 'passed' : 'failed',
            'detail' => $passes
                ? 'all '.count($expected).' default tools present'
                : 'missing seed tools: '.implode(',', $missing).' (run atlas:ai:tool-runtime --action=seed-defaults)',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkPolicyBridge(): array
    {
        $available = $this->policyBridge->bridgeAvailable();

        return [
            'name' => 'bridge:policy_runtime',
            'status' => 'passed',
            'detail' => $available
                ? 'atlas policy/permission gate bridge available'
                : 'policy_runtime unavailable - tool runtime falls back to safe defaults (require_approval for high-risk authority)',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkEvidenceBridge(): array
    {
        $available = $this->receipts->bridgeAvailable();

        return [
            'name' => 'bridge:evidence_runtime',
            'status' => 'passed',
            'detail' => $available
                ? 'atlas evidence receipt bridge available'
                : 'evidence_runtime unavailable - tool runtime emits local-only receipts',
        ];
    }
}
