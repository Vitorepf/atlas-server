<?php

namespace App\Services\Ai\Programming\Kernel;

use App\Models\AiDomainManifest;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Contracts\Container\Container;

class ProgrammingAdapterReadinessService
{
    private const REQUIRED_SERVICES = [
        ProgrammingDomainManifestSeeder::class,
        ProgrammingDomainRuntimeAdapter::class,
        AtlasDevMissionAdapter::class,
        AtlasForgeHandoffAdapter::class,
        ProgrammingEvidenceBridge::class,
        ProgrammingPolicyBridge::class,
        ProgrammingToolBridge::class,
        ProgrammingControlPlaneProjection::class,
    ];

    private const REQUIRED_TEST_FILES = [
        'tests/Feature/Ai/ProgrammingAdapter/ProgrammingAdapterReadinessTest.php',
        'tests/Feature/Ai/ProgrammingAdapter/ProgrammingAdapterMissionAdapterTest.php',
        'tests/Feature/Ai/ProgrammingAdapter/ProgrammingAdapterDevRoutingTest.php',
        'tests/Feature/Ai/ProgrammingAdapter/ProgrammingAdapterForgeEscalationTest.php',
        'tests/Feature/Ai/ProgrammingAdapter/ProgrammingAdapterHandoffPayloadTest.php',
        'tests/Feature/Ai/ProgrammingAdapter/ProgrammingAdapterPolicyBridgeTest.php',
        'tests/Feature/Ai/ProgrammingAdapter/ProgrammingAdapterEvidenceBridgeTest.php',
        'tests/Feature/Ai/ProgrammingAdapter/ProgrammingAdapterCertificationTest.php',
        'tests/Feature/Ai/ProgrammingAdapter/ProgrammingAdapterControlPlaneTest.php',
        'tests/Feature/Ai/ProgrammingAdapter/ProgrammingAdapterSmokeTest.php',
    ];

    public function __construct(
        private readonly Container $container,
        private readonly ProgrammingPolicyBridge $policy,
        private readonly ProgrammingEvidenceBridge $evidence,
        private readonly ProgrammingToolBridge $tools,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function report(): array
    {
        $checks = [];

        foreach (self::REQUIRED_SERVICES as $service) {
            $resolvable = false;
            $detail = 'not resolvable';
            try {
                $resolved = $this->container->make($service);
                $resolvable = $resolved instanceof $service;
                $detail = $resolvable ? 'resolved' : 'not an instance';
            } catch (\Throwable $e) {
                $detail = 'exception: '.$e->getMessage();
            }
            $checks[] = [
                'name' => 'service:'.class_basename($service),
                'status' => $resolvable ? 'passed' : 'failed',
                'detail' => $detail,
            ];
        }

        foreach (self::REQUIRED_TEST_FILES as $relativePath) {
            $exists = file_exists(base_path($relativePath));
            $checks[] = [
                'name' => 'test:'.basename($relativePath),
                'status' => $exists ? 'passed' : 'failed',
                'detail' => $exists ? 'present' : "missing [{$relativePath}]",
            ];
        }

        $checks[] = $this->checkManifestSeeded();
        $checks[] = $this->checkUpstreamFoundation();
        $checks[] = $this->bridgeCheck('policy_runtime', $this->policy->bridgeAvailable());
        $checks[] = $this->bridgeCheck('mission_evidence', $this->evidence->missionEvidenceAvailable());
        $checks[] = $this->bridgeCheck('evidence_runtime', $this->evidence->evidenceRuntimeAvailable());
        $checks[] = $this->bridgeCheck('tool_runtime', $this->tools->bridgeAvailable());

        $passed = collect($checks)->where('status', 'passed')->count();
        $failed = collect($checks)->where('status', 'failed')->count();

        return [
            'ok' => $failed === 0,
            'schema' => ProgrammingDomainKernelCanon::SCHEMA_READINESS,
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
    private function checkManifestSeeded(): array
    {
        $hasManifest = DatabaseTableAvailability::has('ai_domain_manifests')
            && AiDomainManifest::query()->where('domain_id', ProgrammingDomainKernelCanon::DOMAIN_ID)->exists();

        return [
            'name' => 'seed:programming_manifest',
            'status' => $hasManifest ? 'passed' : 'failed',
            'detail' => $hasManifest
                ? 'manifest registered'
                : 'run ProgrammingDomainManifestSeeder::seed() (atlas:ai:programming-adapter --action=smoke will do it)',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkUpstreamFoundation(): array
    {
        $needed = [
            'ai_missions',
            'ai_objectives',
            'ai_work_orders',
            'ai_mission_events',
            'ai_domain_manifests',
            'ai_domain_runtime_records',
            'ai_domain_handoffs',
        ];

        $missing = DatabaseTableAvailability::missing($needed);

        return [
            'name' => 'foundation:mission+domain_runtime',
            'status' => $missing === [] ? 'passed' : 'failed',
            'detail' => $missing === []
                ? 'mission foundation and domain runtime tables present'
                : 'missing required tables: '.implode(',', $missing),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function bridgeCheck(string $name, bool $available): array
    {
        return [
            'name' => "bridge:{$name}",
            'status' => 'passed',
            'detail' => $available
                ? "{$name} bridge available"
                : "{$name} bridge unavailable - programming adapter falls back to safe defaults",
        ];
    }
}
