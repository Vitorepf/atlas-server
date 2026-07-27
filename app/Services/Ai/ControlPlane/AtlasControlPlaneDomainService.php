<?php

namespace App\Services\Ai\ControlPlane;

use Illuminate\Contracts\Container\Container;
use Throwable;

class AtlasControlPlaneDomainService
{
    use ChecksControlPlaneTableAvailability;

    public const COMPONENT = 'domain';

    private const REQUIRED_TABLES = [
        'ai_domain_manifests',
        'ai_domain_capabilities',
        'ai_domain_runtime_records',
        'ai_domain_handoffs',
        'ai_domain_maturity_assessments',
    ];

    public function __construct(private readonly Container $container) {}

    /**
     * @return array<string,mixed>
     */
    public function snapshot(): array
    {
        $availability = $this->tableAvailability();
        if ($availability['status'] === AtlasControlPlaneStatus::MISSING) {
            return [
                'component' => self::COMPONENT,
                'status' => AtlasControlPlaneStatus::MISSING,
                'detail' => 'Domain Runtime tables not present',
                'tables' => $availability['tables'],
            ];
        }

        $service = '\\App\\Services\\Ai\\DomainRuntime\\DomainRuntimeControlPlaneService';
        if (! class_exists($service)) {
            return [
                'component' => self::COMPONENT,
                'status' => AtlasControlPlaneStatus::DEGRADED,
                'detail' => 'DomainRuntimeControlPlaneService not available',
                'tables' => $availability['tables'],
            ];
        }

        try {
            $resolved = $this->container->make($service);
            $payload = $resolved->snapshot();
            $payload['component'] = self::COMPONENT;
            $payload['status'] = $availability['status'];

            return $payload;
        } catch (Throwable $e) {
            return [
                'component' => self::COMPONENT,
                'status' => AtlasControlPlaneStatus::DEGRADED,
                'detail' => 'domain snapshot failed: '.$e->getMessage(),
            ];
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function summary(): array
    {
        $availability = $this->tableAvailability();
        if ($availability['status'] === AtlasControlPlaneStatus::MISSING) {
            return [
                'component' => self::COMPONENT,
                'status' => AtlasControlPlaneStatus::MISSING,
                'detail' => 'Domain Runtime tables not present',
            ];
        }

        try {
            $manifestModel = '\\App\\Models\\AiDomainManifest';
            $recordModel = '\\App\\Models\\AiDomainRuntimeRecord';
            $handoffModel = '\\App\\Models\\AiDomainHandoff';
            $capabilityModel = '\\App\\Models\\AiDomainCapability';
            $maturityModel = '\\App\\Models\\AiDomainMaturityAssessment';

            $manifestCount = class_exists($manifestModel) ? $manifestModel::query()->count() : 0;
            $capabilityCount = class_exists($capabilityModel) ? $capabilityModel::query()->count() : 0;
            $recordCount = class_exists($recordModel) ? $recordModel::query()->count() : 0;
            $handoffCount = class_exists($handoffModel) ? $handoffModel::query()->count() : 0;
            $maturityCount = class_exists($maturityModel) ? $maturityModel::query()->count() : 0;

            return [
                'component' => self::COMPONENT,
                'status' => $availability['status'],
                'tables' => $availability['tables'],
                'totals' => [
                    'manifests' => $manifestCount,
                    'capabilities' => $capabilityCount,
                    'runtime_records' => $recordCount,
                    'handoffs' => $handoffCount,
                    'maturity_assessments' => $maturityCount,
                ],
            ];
        } catch (Throwable $e) {
            return [
                'component' => self::COMPONENT,
                'status' => AtlasControlPlaneStatus::DEGRADED,
                'detail' => 'domain summary failed: '.$e->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function tableAvailability(): array
    {
        return $this->tableAvailabilityFor(self::REQUIRED_TABLES);
    }
}
