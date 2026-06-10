<?php

namespace App\Services\Ai\ResearchDomain;

use App\Models\AiResearchClaim;
use App\Models\AiResearchRun;
use App\Models\AiResearchSource;
use App\Models\AiResearchSynthesis;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Contracts\Container\Container;

class ResearchReadinessService
{
    private const REQUIRED_TABLES = [
        'ai_research_runs',
        'ai_research_sources',
        'ai_research_claims',
        'ai_research_syntheses',
    ];

    private const REQUIRED_MODELS = [
        AiResearchRun::class,
        AiResearchSource::class,
        AiResearchClaim::class,
        AiResearchSynthesis::class,
    ];

    private const REQUIRED_SERVICES = [
        ResearchSourcePlanService::class,
        ResearchSourceQualityService::class,
        ResearchClaimService::class,
        ResearchSynthesisService::class,
        ResearchRuntimeService::class,
        ResearchEvidenceBridge::class,
        ResearchControlPlaneProjection::class,
        ResearchDomainManifestSeeder::class,
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

        $checks[] = $this->checkCanonEnums();
        $checks[] = $this->checkMissionFoundationTolerance();
        $checks[] = $this->checkDomainManifestRegistryTolerance();

        $passed = collect($checks)->where('status', 'passed')->count();
        $failed = collect($checks)->where('status', 'failed')->count();

        return [
            'ok' => $failed === 0,
            'schema' => 'atlas.ai.research_domain.readiness.v1',
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
    private function checkCanonEnums(): array
    {
        $ok = count(ResearchDomainCanon::SOURCE_TYPES) >= 6
            && count(ResearchDomainCanon::CLAIM_STATUSES) === 4
            && count(ResearchDomainCanon::CONTRADICTION_STATUSES) === 4;

        return [
            'name' => 'canon:research_enums',
            'status' => $ok ? 'passed' : 'failed',
            'detail' => $ok ? 'canon enums aligned' : 'canon enums drifted',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkMissionFoundationTolerance(): array
    {
        $present = DatabaseTableAvailability::has('ai_mission_evidence_refs');

        return [
            'name' => 'bridge:mission_evidence_tolerant',
            'status' => 'passed',
            'detail' => $present
                ? 'mission evidence ledger present; bridge will project sources/synthesis'
                : 'mission evidence ledger absent; bridge degrades gracefully',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkDomainManifestRegistryTolerance(): array
    {
        $present = DatabaseTableAvailability::has('ai_domain_manifests');

        return [
            'name' => 'bridge:domain_manifest_registry',
            'status' => 'passed',
            'detail' => $present
                ? 'domain manifest registry present; seeder will idempotently register research manifest'
                : 'domain manifest registry absent; seeder will skip until Meta 2 ready',
        ];
    }
}
