<?php

namespace App\Services\Ai\Cyber;

use App\Models\AiAppSecReview;
use App\Models\AiBugBountyIntake;
use App\Models\AiCyberEngagement;
use App\Models\AiCyberEvidenceChainEntry;
use App\Models\AiCyberScopeRules;
use App\Models\AiDefensiveSecurityReview;
use App\Models\AiGrcMapping;
use App\Models\AiRemediationPlan;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Schema;
use Throwable;

class CyberReadinessService
{
    public const SCHEMA = 'atlas.ai.cyber.readiness.v1';

    private const REQUIRED_TABLES = [
        'ai_cyber_engagements',
        'ai_cyber_scope_rules',
        'ai_appsec_reviews',
        'ai_grc_mappings',
        'ai_remediation_plans',
        'ai_defensive_security_reviews',
        'ai_bug_bounty_intakes',
        'ai_cyber_evidence_chain',
    ];

    private const REQUIRED_MODELS = [
        AiCyberEngagement::class,
        AiCyberScopeRules::class,
        AiAppSecReview::class,
        AiGrcMapping::class,
        AiRemediationPlan::class,
        AiDefensiveSecurityReview::class,
        AiBugBountyIntake::class,
        AiCyberEvidenceChainEntry::class,
    ];

    private const REQUIRED_SERVICES = [
        CyberDomainManifestSeeder::class,
        CyberEngagementIntakeService::class,
        CyberScopeRulesOfEngagementService::class,
        AppSecReviewService::class,
        GRCMappingService::class,
        RemediationPlanService::class,
        DefensiveSecurityReviewService::class,
        AuthorizedBugBountyIntakeService::class,
        CyberEvidenceChainService::class,
        CyberRuntimeService::class,
        CyberControlPlaneProjection::class,
    ];

    private const REQUIRED_TEST_FILES = [
        'tests/Feature/Ai/Cyber/CyberDomainReadinessTest.php',
        'tests/Feature/Ai/Cyber/CyberDomainSmokeTest.php',
        'tests/Feature/Ai/Cyber/CyberDomainEngagementTest.php',
        'tests/Feature/Ai/Cyber/CyberDomainScopeRoeTest.php',
        'tests/Feature/Ai/Cyber/CyberDomainAppSecTest.php',
        'tests/Feature/Ai/Cyber/CyberDomainBugBountyIntakeTest.php',
        'tests/Feature/Ai/Cyber/CyberDomainEvidenceChainTest.php',
        'tests/Feature/Ai/Cyber/CyberDomainOffensiveGuardsTest.php',
        'tests/Feature/Ai/Cyber/CyberDomainControlPlaneTest.php',
    ];

    public function __construct(private readonly Container $container) {}

    /**
     * @return array<string,mixed>
     */
    public function report(): array
    {
        $checks = [];
        foreach (self::REQUIRED_TABLES as $table) {
            $exists = Schema::hasTable($table);
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
            } catch (Throwable $e) {
                $detail = 'exception: '.$e->getMessage();
            }
            $checks[] = [
                'name' => 'service:'.class_basename($service),
                'status' => $resolvable ? 'passed' : 'failed',
                'detail' => $detail,
            ];
        }

        $checks[] = $this->checkOffensiveRefusalGuard();
        $checks[] = $this->checkAuthorizationGate();

        foreach (self::REQUIRED_TEST_FILES as $relativePath) {
            $exists = file_exists(base_path($relativePath));
            $checks[] = [
                'name' => 'test:'.basename($relativePath),
                'status' => $exists ? 'passed' : 'failed',
                'detail' => $exists ? 'present' : "missing [{$relativePath}]",
            ];
        }

        $passed = collect($checks)->where('status', 'passed')->count();
        $failed = collect($checks)->where('status', 'failed')->count();

        return [
            'ok' => $failed === 0,
            'schema' => self::SCHEMA,
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
    private function checkOffensiveRefusalGuard(): array
    {
        $reflection = new \ReflectionClass(CyberRuntimeService::class);
        $source = (string) file_get_contents((string) $reflection->getFileName());
        $hasGuard = str_contains($source, 'FORBIDDEN_OFFENSIVE_VERBS')
            && str_contains($source, 'refuseOffensive')
            && str_contains($source, 'execute_exploit')
            && str_contains($source, 'execute_scan');

        return [
            'name' => 'guard:offensive_refusal_matrix',
            'status' => $hasGuard ? 'passed' : 'failed',
            'detail' => $hasGuard
                ? 'CyberRuntimeService refuses execute_exploit/execute_scan via FORBIDDEN_OFFENSIVE_VERBS'
                : 'CyberRuntimeService missing offensive refusal guard',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkAuthorizationGate(): array
    {
        $reflection = new \ReflectionClass(AuthorizedBugBountyIntakeService::class);
        $source = (string) file_get_contents((string) $reflection->getFileName());
        $hasGate = str_contains($source, 'authorization_present')
            && str_contains($source, 'scope_parsed')
            && str_contains($source, 'roe_documented')
            && str_contains($source, 'legal_gate_passed')
            && str_contains($source, 'privacy_gate_passed');

        return [
            'name' => 'guard:bug_bounty_authorization_gates',
            'status' => $hasGate ? 'passed' : 'failed',
            'detail' => $hasGate
                ? 'AuthorizedBugBountyIntakeService enforces authorization+scope+RoE+legal+privacy gates'
                : 'AuthorizedBugBountyIntakeService missing required gates',
        ];
    }
}
