<?php

namespace App\Services\Ai\DomainRuntime;

use App\Models\AiDomainCapability;
use App\Models\AiDomainHandoff;
use App\Models\AiDomainManifest;
use App\Models\AiDomainMaturityAssessment;
use App\Models\AiDomainRuntimeRecord;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Contracts\Container\Container;

class DomainRuntimeReadinessService
{
    private const REQUIRED_TABLES = [
        'ai_domain_manifests',
        'ai_domain_capabilities',
        'ai_domain_runtime_records',
        'ai_domain_handoffs',
        'ai_domain_maturity_assessments',
    ];

    private const REQUIRED_MODELS = [
        AiDomainManifest::class,
        AiDomainCapability::class,
        AiDomainRuntimeRecord::class,
        AiDomainHandoff::class,
        AiDomainMaturityAssessment::class,
    ];

    private const REQUIRED_SERVICES = [
        DomainManifestRegistryService::class,
        DomainCapabilityCatalogService::class,
        DomainRuntimeSelectionService::class,
        DomainRuntimeRecordService::class,
        DomainHandoffService::class,
        DomainMaturityAssessmentService::class,
        DomainRuntimeControlPlaneService::class,
    ];

    private const REQUIRED_TEST_FILES = [
        'tests/Feature/Ai/DomainRuntime/DomainRuntimeReadinessTest.php',
        'tests/Feature/Ai/DomainRuntime/DomainRuntimeSeedDefaultsTest.php',
        'tests/Feature/Ai/DomainRuntime/DomainRuntimeManifestRegistryTest.php',
        'tests/Feature/Ai/DomainRuntime/DomainRuntimeCapabilityCatalogTest.php',
        'tests/Feature/Ai/DomainRuntime/DomainRuntimeSelectionTest.php',
        'tests/Feature/Ai/DomainRuntime/DomainRuntimeHandoffTest.php',
        'tests/Feature/Ai/DomainRuntime/DomainRuntimeMaturityAssessmentTest.php',
        'tests/Feature/Ai/DomainRuntime/DomainRuntimeControlPlaneTest.php',
        'tests/Feature/Ai/DomainRuntime/DomainRuntimeDuplicateManifestTest.php',
        'tests/Feature/Ai/DomainRuntime/DomainRuntimeManifestMinimumFieldsTest.php',
    ];

    public function __construct(private readonly Container $container) {}

    /**
     * @return array<string,mixed>
     */
    public function report(): array
    {
        $checks = [];

        foreach (self::REQUIRED_TABLES as $table) {
            $exists = DatabaseTableAvailability::has($table);
            $checks[] = [
                'name' => "table:{$table}",
                'status' => $exists ? 'passed' : 'failed',
                'detail' => $exists ? 'exists' : 'missing - run php artisan migrate',
            ];
        }

        foreach (self::REQUIRED_MODELS as $model) {
            $exists = class_exists($model);
            $checks[] = [
                'name' => 'model:'.class_basename($model),
                'status' => $exists ? 'passed' : 'failed',
                'detail' => $exists ? 'class exists' : "missing class [{$model}]",
            ];
        }

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

        $checks[] = $this->checkSeedManifestsAvailable();

        $passed = collect($checks)->where('status', 'passed')->count();
        $failed = collect($checks)->where('status', 'failed')->count();

        return [
            'ok' => $failed === 0,
            'schema' => 'atlas.ai.domain_runtime.readiness.v1',
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
    private function checkSeedManifestsAvailable(): array
    {
        $expected = collect(DomainSeedManifests::all())->pluck('domain_id')->all();
        if (! DatabaseTableAvailability::has('ai_domain_manifests')) {
            return [
                'name' => 'seed:default_manifests',
                'status' => 'failed',
                'detail' => 'ai_domain_manifests table missing',
            ];
        }

        $present = AiDomainManifest::query()->whereIn('domain_id', $expected)->pluck('domain_id')->all();
        $missing = array_values(array_diff($expected, $present));
        $passes = $missing === [];

        return [
            'name' => 'seed:default_manifests',
            'status' => $passes ? 'passed' : 'failed',
            'detail' => $passes
                ? 'all '.count($expected).' default manifests present'
                : 'missing seed manifests: '.implode(',', $missing).' (run atlas:ai:domain-runtime --action=seed-defaults)',
        ];
    }
}
