<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\EngineeringCompany;

use App\Models\AiEngineeringCompanyCycle;
use App\Models\AiEngineeringCompanyEngagement;
use App\Services\Ai\EngineeringCompany\AtlasRealEngineeringCompanyRuntimeService;
use App\Services\Ai\EngineeringCompany\EngineeringCompanyHash;
use App\Services\Ai\EngineeringKernel\EngineeringRoleRoster;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * GOD-DEBULK golden characterization (2026-07-22) — Ai/Company step 1.
 *
 * Freezes the CURRENT EngineeringCompany runtime surface consumed by the
 * EngineeringKernel before the EnterpriseExecutorUnification restructure:
 * the exact constants (schemas, ROLES, QUALITY_ROLES, depth profiles), the
 * public API fingerprint, and the createEngagement/createCycle record shapes
 * (attribute sets, id patterns, receipt key order, recomputable hashes).
 *
 * Determinism: no provider calls, no real-execution kernel run. The only
 * nondeterministic input is microtime() inside the engagement id, so the id
 * is frozen by PATTERN and every hash is frozen by RECOMPUTATION (the receipt
 * minus its 'hash' key must re-hash to receipt_hash). The cycle id IS
 * deterministic given the engagement id and is recomputed exactly.
 */
final class EngineeringCompanyRuntimeGoldenCharacterizationTest extends TestCase
{
    /** Exact snapshot: the legacy execution roster (migration debt, still shipped). */
    private const ROLES = [
        'product_intent_owner',
        'architect',
        'planner',
        'senior_engineer',
        'debugger',
        'independent_reviewer',
        'qa_test_engineer',
        'release_delivery_manager',
        'learning_memory_manager',
    ];

    /** Exact snapshot: the canonical Quality Foundry certification roster (22). */
    private const QUALITY_ROLES = [
        'product_strategy', 'product_management', 'domain_research', 'ux_research',
        'interaction_design', 'visual_design', 'architecture', 'backend', 'frontend',
        'mobile', 'data', 'qa_testing', 'appsec_privacy', 'performance_resilience',
        'devops_sre', 'observability', 'release', 'documentation_dx',
        'maintenance_simplification', 'outcome_analysis', 'evidence_audit', 'final_certification',
    ];

    /** Exact snapshot: risk changes depth, never membership. */
    private const DEPTH_PROFILES = [
        'R0' => 'minimal_evidence',
        'R1' => 'light_independent_review_local_tests',
        'R2' => 'standard_review_contracts_integration',
        'R3' => 'multiple_verifiers_regression_compatibility_controlled_release',
        'R4' => 'security_mutation_property_chaos_rollback',
        'R5' => 'competing_candidates_different_family_verifiers_disaster_drills',
    ];

    private const ENGAGEMENT_ATTRIBUTE_KEYS = [
        'engagement_id', 'goal', 'status', 'target_runtime', 'roles', 'evidence_refs',
        'receipt', 'receipt_hash', 'id', 'updated_at', 'created_at',
    ];

    private const CYCLE_ATTRIBUTE_KEYS = [
        'engagement_record_id', 'cycle_id', 'cycle_index', 'status', 'plan',
        'evidence_refs', 'receipt', 'cycle_hash', 'id', 'updated_at', 'created_at',
    ];

    /** Exact snapshot of the static cycle plan (deep, order-sensitive). */
    private const CYCLE_PLAN = [
        'mode' => 'single_cycle_company_runtime',
        'roles' => self::QUALITY_ROLES,
        'execution_kernel' => 'atlas_real_engineering_execution_kernel',
        'gates' => ['independent_review', 'qa', 'release_pack', 'benchmark', 'certification'],
        'multi_worktree_policy' => 'delegate_patch_execution_to_real_execution_kernel',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCompanySchema();
    }

    protected function tearDown(): void
    {
        $this->dropCompanySchema();
        parent::tearDown();
    }

    public function test_schema_constants_are_frozen(): void
    {
        $this->assertSame('atlas.ai.engineering_company.engagement.v1', AtlasRealEngineeringCompanyRuntimeService::ENGAGEMENT_SCHEMA);
        $this->assertSame('atlas.ai.engineering_company.cycle.v1', AtlasRealEngineeringCompanyRuntimeService::CYCLE_SCHEMA);
        $this->assertSame('atlas.ai.engineering_company.role_run.v1', AtlasRealEngineeringCompanyRuntimeService::ROLE_SCHEMA);
        $this->assertSame('atlas.engineering_company.quality_role.v1', AtlasRealEngineeringCompanyRuntimeService::QUALITY_ROLE_PRODUCER);
        $this->assertSame('atlas.ai.engineering_company.review.v1', AtlasRealEngineeringCompanyRuntimeService::REVIEW_SCHEMA);
        $this->assertSame('atlas.ai.engineering_company.qa_run.v1', AtlasRealEngineeringCompanyRuntimeService::QA_SCHEMA);
        $this->assertSame('atlas.ai.engineering_company.release_pack.v1', AtlasRealEngineeringCompanyRuntimeService::RELEASE_SCHEMA);
        $this->assertSame('atlas.ai.engineering_company.benchmark.v1', AtlasRealEngineeringCompanyRuntimeService::BENCHMARK_SCHEMA);
        $this->assertSame('atlas.ai.engineering_company.certification.v1', AtlasRealEngineeringCompanyRuntimeService::CERTIFICATION_SCHEMA);
    }

    public function test_role_rosters_and_depth_profiles_are_frozen(): void
    {
        $this->assertSame(self::ROLES, AtlasRealEngineeringCompanyRuntimeService::ROLES);
        $this->assertSame(self::QUALITY_ROLES, AtlasRealEngineeringCompanyRuntimeService::QUALITY_ROLES);
        $this->assertCount(22, AtlasRealEngineeringCompanyRuntimeService::QUALITY_ROLES);

        // The kernel roster IS the runtime's quality roster (same constant, by reference).
        $this->assertSame(AtlasRealEngineeringCompanyRuntimeService::QUALITY_ROLES, EngineeringRoleRoster::OFFICIAL_ROLES);
        $this->assertSame(self::DEPTH_PROFILES, EngineeringRoleRoster::DEPTH_PROFILES);
        $this->assertSame(
            'multiple_verifiers_regression_compatibility_controlled_release',
            EngineeringRoleRoster::depthProfile('R3'),
        );
    }

    public function test_public_api_fingerprint_is_frozen(): void
    {
        $reflection = new \ReflectionClass(AtlasRealEngineeringCompanyRuntimeService::class);

        $publicMethods = collect($reflection->getMethods(\ReflectionMethod::IS_PUBLIC))
            ->map(fn (\ReflectionMethod $method): string => sprintf(
                '%s(%s): %s',
                $method->getName(),
                collect($method->getParameters())
                    ->map(fn (\ReflectionParameter $parameter): string => (string) $parameter->getType().' $'.$parameter->getName())
                    ->implode(', '),
                (string) ($method->getReturnType() ?? 'mixed'),
            ))
            ->sort()
            ->values()
            ->all();

        $this->assertSame([
            'adjudicateMutativeCandidate(App\Models\AiEngineeringCompanyEngagement $engagement, App\Models\AiEngineeringCompanyCycle $cycle, App\Services\Ai\EngineeringKernel\CandidateQualityCase $case): App\Services\Ai\EngineeringKernel\QualityCourtVerdict',
            'certify(?App\Models\AiEngineeringCompanyEngagement $engagement): App\Models\AiEngineeringCompanyCertification',
            'controlPlane(): array',
            'createBenchmark(App\Models\AiEngineeringCompanyEngagement $engagement, array $realExecution): App\Models\AiEngineeringCompanyBenchmark',
            'createCycle(App\Models\AiEngineeringCompanyEngagement $engagement): App\Models\AiEngineeringCompanyCycle',
            'createEngagement(string $goalText): App\Models\AiEngineeringCompanyEngagement',
            'createQaRun(App\Models\AiEngineeringCompanyEngagement $engagement, array $realExecution, string $status): App\Models\AiEngineeringCompanyQaRun',
            'createReleasePack(App\Models\AiEngineeringCompanyEngagement $engagement, array $realExecution, App\Models\AiEngineeringCompanyReview $review, App\Models\AiEngineeringCompanyQaRun $qa): App\Models\AiEngineeringCompanyReleasePack',
            'createReview(App\Models\AiEngineeringCompanyEngagement $engagement, array $realExecution, string $status): App\Models\AiEngineeringCompanyReview',
            'executeQualityRole(App\Models\AiEngineeringCompanyEngagement $engagement, App\Models\AiEngineeringCompanyCycle $cycle, App\Services\Ai\EngineeringKernel\ExecutionOrder $order, string $roleId, App\Models\AiRealExecutionTestRun $verification, array $priorRoleRuns): App\Models\AiEngineeringCompanyRoleRun',
            'readiness(): array',
            'run(string $goalText, array $options): array',
            'runRole(App\Models\AiEngineeringCompanyEngagement $engagement, App\Models\AiEngineeringCompanyCycle $cycle, string $roleId, string $status, array $output): App\Models\AiEngineeringCompanyRoleRun',
        ], $publicMethods);
    }

    public function test_create_engagement_record_shape_is_frozen(): void
    {
        $goal = 'golden: freeze the engagement record shape';
        $engagement = app(AtlasRealEngineeringCompanyRuntimeService::class)->createEngagement($goal);

        $this->assertSame(self::ENGAGEMENT_ATTRIBUTE_KEYS, array_keys($engagement->getAttributes()));
        $this->assertMatchesRegularExpression('/^aecomp_[0-9a-f]{24}$/', $engagement->engagement_id);
        $this->assertSame($goal, $engagement->goal);
        $this->assertSame('accepted', $engagement->status);
        $this->assertSame('atlas_real_execution_kernel', $engagement->target_runtime);
        $this->assertSame(self::QUALITY_ROLES, $engagement->roles);
        $this->assertSame([], $engagement->evidence_refs);

        // Receipt: exact key order + values, hash recomputable from the receipt body.
        $receipt = $engagement->receipt;
        $this->assertSame(['schema_version', 'engagement_id', 'goal', 'status', 'roles', 'hash'], array_keys($receipt));
        $this->assertSame(AtlasRealEngineeringCompanyRuntimeService::ENGAGEMENT_SCHEMA, $receipt['schema_version']);
        $this->assertSame($engagement->engagement_id, $receipt['engagement_id']);
        $this->assertSame($goal, $receipt['goal']);
        $this->assertSame('accepted', $receipt['status']);
        $this->assertSame(self::QUALITY_ROLES, $receipt['roles']);
        $this->assertSame($engagement->receipt_hash, $receipt['hash']);
        $body = $receipt;
        unset($body['hash']);
        $this->assertSame(EngineeringCompanyHash::make($body), $engagement->receipt_hash);

        // Persisted (not an in-memory-only model).
        $this->assertTrue(
            AiEngineeringCompanyEngagement::query()->whereKey($engagement->id)->exists(),
        );
    }

    public function test_create_cycle_record_shape_is_frozen_and_cycle_id_is_deterministic(): void
    {
        $service = app(AtlasRealEngineeringCompanyRuntimeService::class);
        $engagement = $service->createEngagement('golden: freeze the cycle record shape');
        $cycle = $service->createCycle($engagement);

        $this->assertSame(self::CYCLE_ATTRIBUTE_KEYS, array_keys($cycle->getAttributes()));
        // The cycle id is DETERMINISTIC from the engagement id (index 1).
        $this->assertSame(
            'aecompcyc_'.substr(EngineeringCompanyHash::make([$engagement->engagement_id, 1]), 0, 24),
            $cycle->cycle_id,
        );
        $this->assertSame($engagement->id, $cycle->engagement_record_id);
        $this->assertSame(1, $cycle->cycle_index);
        $this->assertSame('planned', $cycle->status);
        $this->assertSame(self::CYCLE_PLAN, $cycle->plan);
        $this->assertSame(['engagement:'.$engagement->receipt_hash], $cycle->evidence_refs);

        // Receipt: exact key order, deterministic body, recomputable hash.
        $receipt = $cycle->receipt;
        $this->assertSame(['schema_version', 'cycle_id', 'plan', 'evidence_refs', 'hash'], array_keys($receipt));
        $this->assertSame(AtlasRealEngineeringCompanyRuntimeService::CYCLE_SCHEMA, $receipt['schema_version']);
        $this->assertSame($cycle->cycle_id, $receipt['cycle_id']);
        $this->assertSame(self::CYCLE_PLAN, $receipt['plan']);
        $this->assertSame(['engagement:'.$engagement->receipt_hash], $receipt['evidence_refs']);
        $this->assertSame($cycle->cycle_hash, $receipt['hash']);
        $body = $receipt;
        unset($body['hash']);
        $this->assertSame(EngineeringCompanyHash::make($body), $cycle->cycle_hash);

        $this->assertTrue(AiEngineeringCompanyCycle::query()->whereKey($cycle->id)->exists());
    }

    private function bootCompanySchema(): void
    {
        $this->dropCompanySchema();
        (require database_path('migrations/2026_05_17_232000_create_ai_engineering_company_runtime_tables.php'))->up();
    }

    private function dropCompanySchema(): void
    {
        foreach ([
            'ai_engineering_company_certifications',
            'ai_engineering_company_benchmarks',
            'ai_engineering_company_release_packs',
            'ai_engineering_company_qa_runs',
            'ai_engineering_company_reviews',
            'ai_engineering_company_role_runs',
            'ai_engineering_company_cycles',
            'ai_engineering_company_engagements',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
}
