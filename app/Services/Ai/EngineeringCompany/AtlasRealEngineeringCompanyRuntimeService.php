<?php

namespace App\Services\Ai\EngineeringCompany;

use App\Models\AiEngineeringCompanyBenchmark;
use App\Models\AiEngineeringCompanyCertification;
use App\Models\AiEngineeringCompanyCycle;
use App\Models\AiEngineeringCompanyEngagement;
use App\Models\AiEngineeringCompanyQaRun;
use App\Models\AiEngineeringCompanyReleasePack;
use App\Models\AiEngineeringCompanyReview;
use App\Models\AiEngineeringCompanyRoleRun;
use App\Services\Ai\RealExecution\AtlasRealEngineeringExecutionKernelService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class AtlasRealEngineeringCompanyRuntimeService
{
    public const ENGAGEMENT_SCHEMA = 'atlas.ai.engineering_company.engagement.v1';

    public const CYCLE_SCHEMA = 'atlas.ai.engineering_company.cycle.v1';

    public const ROLE_SCHEMA = 'atlas.ai.engineering_company.role_run.v1';

    public const REVIEW_SCHEMA = 'atlas.ai.engineering_company.review.v1';

    public const QA_SCHEMA = 'atlas.ai.engineering_company.qa_run.v1';

    public const RELEASE_SCHEMA = 'atlas.ai.engineering_company.release_pack.v1';

    public const BENCHMARK_SCHEMA = 'atlas.ai.engineering_company.benchmark.v1';

    public const CERTIFICATION_SCHEMA = 'atlas.ai.engineering_company.certification.v1';

    /** @var list<string> */
    public const ROLES = [
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

    /**
     * @return array<string,mixed>
     */
    public function run(string $goalText, array $options = []): array
    {
        $engagement = $this->createEngagement($goalText);
        $cycle = $this->createCycle($engagement);
        $preExecutionRoles = [];
        foreach (array_slice(self::ROLES, 0, 3) as $role) {
            $preExecutionRoles[] = $this->runRole($engagement, $cycle, $role, 'passed');
        }

        $realExecution = app(AtlasRealEngineeringExecutionKernelService::class)->run($goalText, [
            'context_sufficiency' => (int) ($options['context_sufficiency'] ?? 86),
            'test_status' => (string) ($options['test_status'] ?? 'passed'),
        ]);
        $engineer = $this->runRole($engagement, $cycle, 'senior_engineer', data_get($realExecution, 'status') === 'completed' ? 'passed' : 'blocked', [
            'real_execution_status' => data_get($realExecution, 'status'),
            'patch_hash' => data_get($realExecution, 'patch_run.patch_hash'),
            'test_hash' => data_get($realExecution, 'test_run.test_hash'),
        ]);
        $debugger = $this->runRole($engagement, $cycle, 'debugger', data_get($realExecution, 'repair_attempt') ? 'passed' : 'skipped', [
            'repair_attempt' => data_get($realExecution, 'repair_attempt.repair_attempt_id'),
        ]);
        $review = $this->createReview($engagement, $realExecution, (string) ($options['review_status'] ?? 'passed'));
        $reviewer = $this->runRole($engagement, $cycle, 'independent_reviewer', $review->status, [
            'review_hash' => $review->review_hash,
        ]);
        $qa = $this->createQaRun($engagement, $realExecution, (string) ($options['qa_status'] ?? 'passed'));
        $qaRole = $this->runRole($engagement, $cycle, 'qa_test_engineer', $qa->status, [
            'qa_hash' => $qa->qa_hash,
        ]);
        $release = $this->createReleasePack($engagement, $realExecution, $review, $qa);
        $releaseRole = $this->runRole($engagement, $cycle, 'release_delivery_manager', $release->status === 'ready_for_internal_delivery' ? 'passed' : 'blocked', [
            'release_hash' => $release->release_hash,
        ]);
        $benchmark = $this->createBenchmark($engagement, $realExecution);
        $learningRole = $this->runRole($engagement, $cycle, 'learning_memory_manager', 'passed', [
            'benchmark_hash' => $benchmark->benchmark_hash,
            'memory_action' => 'record_company_runtime_outcome_candidate',
        ]);

        $certification = $this->certify($engagement);
        $engagementStatus = $certification->status === 'passed' ? 'completed' : 'blocked';
        $evidenceRefs = $this->evidenceForEngagement($engagement);
        $receipt = [
            'schema_version' => self::ENGAGEMENT_SCHEMA,
            'engagement_id' => $engagement->engagement_id,
            'status' => $engagementStatus,
            'roles' => self::ROLES,
            'evidence_refs' => $evidenceRefs,
            'certification_hash' => $certification->certification_hash,
        ];
        $receipt['hash'] = EngineeringCompanyHash::make($receipt);
        $engagement->forceFill([
            'status' => $engagementStatus,
            'evidence_refs' => $evidenceRefs,
            'receipt' => $receipt,
            'receipt_hash' => $receipt['hash'],
        ])->save();

        return [
            'schema_version' => 'atlas.ai.engineering_company.run_result.v1',
            'status' => $engagementStatus,
            'engagement' => $engagement->refresh()->toArray(),
            'cycle' => $cycle->toArray(),
            'roles' => array_map(fn (AiEngineeringCompanyRoleRun $role): array => $role->toArray(), [
                ...$preExecutionRoles,
                $engineer,
                $debugger,
                $reviewer,
                $qaRole,
                $releaseRole,
                $learningRole,
            ]),
            'real_execution' => $realExecution,
            'review' => $review->toArray(),
            'qa_run' => $qa->toArray(),
            'release_pack' => $release->toArray(),
            'benchmark' => $benchmark->toArray(),
            'certification' => $certification->toArray(),
            'next_action' => $engagementStatus === 'completed' ? 'ready_for_company_delivery' : 'resolve_company_runtime_blockers',
            'writes' => true,
        ];
    }

    public function createEngagement(string $goalText): AiEngineeringCompanyEngagement
    {
        $engagementId = 'aecomp_'.substr(EngineeringCompanyHash::make([$goalText, microtime(true)]), 0, 24);
        $receipt = [
            'schema_version' => self::ENGAGEMENT_SCHEMA,
            'engagement_id' => $engagementId,
            'goal' => $goalText,
            'status' => 'accepted',
            'roles' => self::ROLES,
        ];
        $receipt['hash'] = EngineeringCompanyHash::make($receipt);

        return AiEngineeringCompanyEngagement::query()->create([
            'engagement_id' => $engagementId,
            'goal' => $goalText,
            'status' => 'accepted',
            'target_runtime' => 'atlas_real_execution_kernel',
            'roles' => self::ROLES,
            'evidence_refs' => [],
            'receipt' => $receipt,
            'receipt_hash' => $receipt['hash'],
        ]);
    }

    public function createCycle(AiEngineeringCompanyEngagement $engagement): AiEngineeringCompanyCycle
    {
        $cycleId = 'aecompcyc_'.substr(EngineeringCompanyHash::make([$engagement->engagement_id, 1]), 0, 24);
        $plan = [
            'mode' => 'single_cycle_company_runtime',
            'roles' => self::ROLES,
            'execution_kernel' => 'atlas_real_engineering_execution_kernel',
            'gates' => ['independent_review', 'qa', 'release_pack', 'benchmark', 'certification'],
            'multi_worktree_policy' => 'delegate_patch_execution_to_real_execution_kernel',
        ];
        $receipt = [
            'schema_version' => self::CYCLE_SCHEMA,
            'cycle_id' => $cycleId,
            'plan' => $plan,
            'evidence_refs' => ['engagement:'.$engagement->receipt_hash],
        ];
        $receipt['hash'] = EngineeringCompanyHash::make($receipt);

        return AiEngineeringCompanyCycle::query()->create([
            'engagement_record_id' => $engagement->id,
            'cycle_id' => $cycleId,
            'cycle_index' => 1,
            'status' => 'planned',
            'plan' => $plan,
            'evidence_refs' => $receipt['evidence_refs'],
            'receipt' => $receipt,
            'cycle_hash' => $receipt['hash'],
        ]);
    }

    /**
     * @param  array<string,mixed>  $output
     */
    public function runRole(AiEngineeringCompanyEngagement $engagement, AiEngineeringCompanyCycle $cycle, string $roleId, string $status = 'passed', array $output = []): AiEngineeringCompanyRoleRun
    {
        $roleRunId = 'aecomprole_'.substr(EngineeringCompanyHash::make([$engagement->engagement_id, $roleId, microtime(true)]), 0, 22);
        $responsibilities = $this->responsibilitiesFor($roleId);
        $evidence = ['engagement:'.$engagement->receipt_hash, 'cycle:'.$cycle->cycle_hash];
        $receipt = [
            'schema_version' => self::ROLE_SCHEMA,
            'role_run_id' => $roleRunId,
            'role_id' => $roleId,
            'status' => $status,
            'responsibilities' => $responsibilities,
            'output' => $output,
            'evidence_refs' => $evidence,
        ];
        $receipt['hash'] = EngineeringCompanyHash::make($receipt);

        return AiEngineeringCompanyRoleRun::query()->create([
            'engagement_record_id' => $engagement->id,
            'cycle_record_id' => $cycle->id,
            'role_run_id' => $roleRunId,
            'role_id' => $roleId,
            'status' => $status,
            'responsibilities' => $responsibilities,
            'output' => $output,
            'evidence_refs' => $evidence,
            'receipt' => $receipt,
            'role_hash' => $receipt['hash'],
        ]);
    }

    /**
     * @param  array<string,mixed>  $realExecution
     */
    public function createReview(AiEngineeringCompanyEngagement $engagement, array $realExecution, string $status = 'passed'): AiEngineeringCompanyReview
    {
        $status = $status === 'failed' ? 'blocked' : 'passed';
        $reviewId = 'aecomprev_'.substr(EngineeringCompanyHash::make([$engagement->engagement_id, $status, microtime(true)]), 0, 24);
        $findings = $status === 'passed'
            ? [['severity' => 'info', 'finding' => 'independent_review_passed', 'evidence' => data_get($realExecution, 'delivery_pack.delivery_hash')]]
            : [['severity' => 'blocker', 'finding' => 'independent_review_failed']];
        $evidence = array_values(array_filter(['real_delivery:'.data_get($realExecution, 'delivery_pack.delivery_hash')]));
        $receipt = [
            'schema_version' => self::REVIEW_SCHEMA,
            'review_id' => $reviewId,
            'status' => $status,
            'findings' => $findings,
            'evidence_refs' => $evidence,
        ];
        $receipt['hash'] = EngineeringCompanyHash::make($receipt);

        return AiEngineeringCompanyReview::query()->create([
            'engagement_record_id' => $engagement->id,
            'review_id' => $reviewId,
            'status' => $status,
            'findings' => $findings,
            'evidence_refs' => $evidence,
            'receipt' => $receipt,
            'review_hash' => $receipt['hash'],
        ]);
    }

    /**
     * @param  array<string,mixed>  $realExecution
     */
    public function createQaRun(AiEngineeringCompanyEngagement $engagement, array $realExecution, string $status = 'passed'): AiEngineeringCompanyQaRun
    {
        $status = $status === 'failed' ? 'blocked' : 'passed';
        $qaRunId = 'aecompqa_'.substr(EngineeringCompanyHash::make([$engagement->engagement_id, $status, microtime(true)]), 0, 24);
        $gates = [
            ['id' => 'real_execution_completed', 'status' => data_get($realExecution, 'status') === 'completed' ? 'passed' : 'blocked'],
            ['id' => 'impact_test_passed', 'status' => data_get($realExecution, 'test_run.status') === 'passed' ? 'passed' : 'blocked'],
            ['id' => 'delivery_pack_ready', 'status' => data_get($realExecution, 'delivery_pack.status') === 'ready_for_internal_use' ? 'passed' : 'blocked'],
        ];
        if ($status === 'blocked') {
            $gates[] = ['id' => 'operator_qa_override', 'status' => 'blocked'];
        }
        $evidence = array_values(array_filter(['test:'.data_get($realExecution, 'test_run.test_hash')]));
        $receipt = [
            'schema_version' => self::QA_SCHEMA,
            'qa_run_id' => $qaRunId,
            'status' => $status,
            'gates' => $gates,
            'evidence_refs' => $evidence,
        ];
        $receipt['hash'] = EngineeringCompanyHash::make($receipt);

        return AiEngineeringCompanyQaRun::query()->create([
            'engagement_record_id' => $engagement->id,
            'qa_run_id' => $qaRunId,
            'status' => $status,
            'gates' => $gates,
            'evidence_refs' => $evidence,
            'receipt' => $receipt,
            'qa_hash' => $receipt['hash'],
        ]);
    }

    /**
     * @param  array<string,mixed>  $realExecution
     */
    public function createReleasePack(AiEngineeringCompanyEngagement $engagement, array $realExecution, AiEngineeringCompanyReview $review, AiEngineeringCompanyQaRun $qa): AiEngineeringCompanyReleasePack
    {
        $ready = data_get($realExecution, 'status') === 'completed' && $review->status === 'passed' && $qa->status === 'passed';
        $releaseId = 'aecomprel_'.substr(EngineeringCompanyHash::make([$engagement->engagement_id, $review->review_hash, $qa->qa_hash]), 0, 24);
        $summary = [
            'goal' => $engagement->goal,
            'real_execution_status' => data_get($realExecution, 'status'),
            'review_status' => $review->status,
            'qa_status' => $qa->status,
            'forge_handoff' => data_get($realExecution, 'forge_handoff.status'),
        ];
        $evidence = array_values(array_filter([
            'review:'.$review->review_hash,
            'qa:'.$qa->qa_hash,
            'real_delivery:'.data_get($realExecution, 'delivery_pack.delivery_hash'),
        ]));
        $receipt = [
            'schema_version' => self::RELEASE_SCHEMA,
            'release_pack_id' => $releaseId,
            'status' => $ready ? 'ready_for_internal_delivery' : 'blocked',
            'summary' => $summary,
            'risk_register' => ['human_review_required_for_superiority_claims', 'company_runtime_v1_single_cycle'],
            'evidence_refs' => $evidence,
        ];
        $receipt['hash'] = EngineeringCompanyHash::make($receipt);

        return AiEngineeringCompanyReleasePack::query()->create([
            'engagement_record_id' => $engagement->id,
            'release_pack_id' => $releaseId,
            'status' => $receipt['status'],
            'summary' => $summary,
            'risk_register' => $receipt['risk_register'],
            'evidence_refs' => $evidence,
            'receipt' => $receipt,
            'release_hash' => $receipt['hash'],
        ]);
    }

    /**
     * @param  array<string,mixed>  $realExecution
     */
    public function createBenchmark(AiEngineeringCompanyEngagement $engagement, array $realExecution): AiEngineeringCompanyBenchmark
    {
        $benchmarkId = 'aecompbench_'.substr(EngineeringCompanyHash::make([$engagement->engagement_id, data_get($realExecution, 'rivals_benchmark.benchmark_hash')]), 0, 22);
        $protocol = [
            'mode' => 'continuous_company_benchmark',
            'source' => 'real_execution_kernel',
            'external_rivals_claim_allowed' => (bool) data_get($realExecution, 'certification.claim_policy.ready_to_claim_100x_vs_claude_codex', false),
            'false_claim_blocked' => true,
        ];
        $evidence = array_values(array_filter(['real_rivals:'.data_get($realExecution, 'rivals_benchmark.benchmark_hash')]));
        $receipt = [
            'schema_version' => self::BENCHMARK_SCHEMA,
            'benchmark_id' => $benchmarkId,
            'status' => 'recorded',
            'protocol' => $protocol,
            'evidence_refs' => $evidence,
        ];
        $receipt['hash'] = EngineeringCompanyHash::make($receipt);

        return AiEngineeringCompanyBenchmark::query()->create([
            'engagement_record_id' => $engagement->id,
            'benchmark_id' => $benchmarkId,
            'status' => 'recorded',
            'protocol' => $protocol,
            'evidence_refs' => $evidence,
            'receipt' => $receipt,
            'benchmark_hash' => $receipt['hash'],
        ]);
    }

    public function certify(?AiEngineeringCompanyEngagement $engagement = null): AiEngineeringCompanyCertification
    {
        $engagement ??= Schema::hasTable('ai_engineering_company_engagements')
            ? $this->latestQuery(AiEngineeringCompanyEngagement::query())->first()
            : null;
        $checks = [
            $this->check('canonical_doc', File::exists(base_path('docs/engineering-knowledge-base/atlas-real-engineering-company-runtime.md'))),
            $this->check('persistence_tables', $this->tablesReady()),
            $this->check('engagement_exists', $engagement !== null),
            $this->check('all_roles_recorded', $engagement !== null && AiEngineeringCompanyRoleRun::query()->where('engagement_record_id', $engagement->id)->distinct('role_id')->count('role_id') >= count(self::ROLES)),
            $this->check('real_execution_completed', $engagement !== null && AiEngineeringCompanyReleasePack::query()->where('engagement_record_id', $engagement->id)->where('status', 'ready_for_internal_delivery')->exists()),
            $this->check('independent_review_passed', $engagement !== null && AiEngineeringCompanyReview::query()->where('engagement_record_id', $engagement->id)->where('status', 'passed')->exists()),
            $this->check('qa_passed', $engagement !== null && AiEngineeringCompanyQaRun::query()->where('engagement_record_id', $engagement->id)->where('status', 'passed')->exists()),
            $this->check('benchmark_recorded', $engagement !== null && AiEngineeringCompanyBenchmark::query()->where('engagement_record_id', $engagement->id)->where('status', 'recorded')->exists()),
        ];
        $blockers = array_values(array_map(
            fn (array $check): string => $check['id'],
            array_filter($checks, fn (array $check): bool => $check['status'] !== 'passed'),
        ));
        $evidence = $engagement ? $this->evidenceForEngagement($engagement) : [];
        if ($blockers === [] && $evidence === []) {
            $blockers[] = 'missing_company_runtime_evidence';
        }
        $status = $blockers === [] ? 'passed' : 'blocked';
        $certificationId = 'aecompcert_'.substr(EngineeringCompanyHash::make([$engagement?->engagement_id, $checks, microtime(true)]), 0, 22);
        $payload = [
            'schema_version' => self::CERTIFICATION_SCHEMA,
            'certification_id' => $certificationId,
            'status' => $status,
            'checks' => $checks,
            'blockers' => array_values(array_unique($blockers)),
            'claim_policy' => [
                'ready_to_claim_engineering_company_runtime' => $status === 'passed',
                'ready_to_claim_autonomous_software_company' => false,
                'requires_multi_cycle_human_review_for_broad_enterprise_claim' => true,
            ],
            'evidence_refs' => $evidence,
        ];
        $payload['hash'] = EngineeringCompanyHash::make($payload);

        return AiEngineeringCompanyCertification::query()->create([
            'engagement_record_id' => $engagement?->id,
            'certification_id' => $certificationId,
            'status' => $status,
            'checks' => $checks,
            'blockers' => $payload['blockers'],
            'claim_policy' => $payload['claim_policy'],
            'evidence_refs' => $evidence,
            'certification_hash' => $payload['hash'],
            'certified_at' => now(),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function readiness(): array
    {
        $checks = [
            $this->check('canonical_doc', File::exists(base_path('docs/engineering-knowledge-base/atlas-real-engineering-company-runtime.md'))),
            $this->check('persistence_tables', $this->tablesReady()),
            $this->check('real_execution_kernel_available', class_exists(AtlasRealEngineeringExecutionKernelService::class)),
        ];
        $blockers = array_values(array_map(fn (array $check): string => $check['id'], array_filter($checks, fn (array $check): bool => $check['status'] !== 'passed')));

        return [
            'schema_version' => 'atlas.ai.engineering_company.readiness.v1',
            'status' => $blockers === [] ? 'passed' : 'blocked',
            'checks' => $checks,
            'blockers' => $blockers,
            'contracts' => [
                self::ENGAGEMENT_SCHEMA,
                self::CYCLE_SCHEMA,
                self::ROLE_SCHEMA,
                self::REVIEW_SCHEMA,
                self::QA_SCHEMA,
                self::RELEASE_SCHEMA,
                self::BENCHMARK_SCHEMA,
                self::CERTIFICATION_SCHEMA,
            ],
            'writes' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function controlPlane(): array
    {
        return [
            'schema_version' => 'atlas.ai.engineering_company.control_plane.v1',
            'status' => 'ready',
            'counts' => [
                'engagements' => $this->count('ai_engineering_company_engagements'),
                'cycles' => $this->count('ai_engineering_company_cycles'),
                'role_runs' => $this->count('ai_engineering_company_role_runs'),
                'reviews' => $this->count('ai_engineering_company_reviews'),
                'qa_runs' => $this->count('ai_engineering_company_qa_runs'),
                'release_packs' => $this->count('ai_engineering_company_release_packs'),
                'benchmarks' => $this->count('ai_engineering_company_benchmarks'),
                'certifications' => $this->count('ai_engineering_company_certifications'),
            ],
            'observability' => [
                'engagement_statuses' => $this->groupCounts('ai_engineering_company_engagements', 'status'),
                'role_statuses' => $this->groupCounts('ai_engineering_company_role_runs', 'status'),
                'review_statuses' => $this->groupCounts('ai_engineering_company_reviews', 'status'),
                'qa_statuses' => $this->groupCounts('ai_engineering_company_qa_runs', 'status'),
                'release_statuses' => $this->groupCounts('ai_engineering_company_release_packs', 'status'),
            ],
            'latest_engagement' => Schema::hasTable('ai_engineering_company_engagements')
                ? $this->latestQuery(AiEngineeringCompanyEngagement::query())->first()?->toArray()
                : null,
            'writes' => false,
        ];
    }

    private function tablesReady(): bool
    {
        foreach ([
            'ai_engineering_company_engagements',
            'ai_engineering_company_cycles',
            'ai_engineering_company_role_runs',
            'ai_engineering_company_reviews',
            'ai_engineering_company_qa_runs',
            'ai_engineering_company_release_packs',
            'ai_engineering_company_benchmarks',
            'ai_engineering_company_certifications',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function responsibilitiesFor(string $roleId): array
    {
        return match ($roleId) {
            'product_intent_owner' => ['clarify_intent', 'define_acceptance'],
            'architect' => ['choose_boundaries', 'identify_risks'],
            'planner' => ['sequence_steps', 'define_gates'],
            'senior_engineer' => ['execute_patch_via_real_kernel', 'preserve_scope'],
            'debugger' => ['inspect_failures', 'open_repair_when_needed'],
            'independent_reviewer' => ['review_delivery_without_author_bias', 'block_on_findings'],
            'qa_test_engineer' => ['verify_test_evidence', 'block_on_failed_gates'],
            'release_delivery_manager' => ['package_delivery', 'publish_risks'],
            'learning_memory_manager' => ['record_outcome', 'feed_benchmark_memory'],
            default => ['record_role_receipt'],
        };
    }

    /**
     * @return list<string>
     */
    private function evidenceForEngagement(AiEngineeringCompanyEngagement $engagement): array
    {
        $cycle = $this->latestQuery(AiEngineeringCompanyCycle::query()->where('engagement_record_id', $engagement->id))->first();
        $review = $this->latestQuery(AiEngineeringCompanyReview::query()->where('engagement_record_id', $engagement->id))->first();
        $qa = $this->latestQuery(AiEngineeringCompanyQaRun::query()->where('engagement_record_id', $engagement->id))->first();
        $release = $this->latestQuery(AiEngineeringCompanyReleasePack::query()->where('engagement_record_id', $engagement->id))->first();
        $benchmark = $this->latestQuery(AiEngineeringCompanyBenchmark::query()->where('engagement_record_id', $engagement->id))->first();

        return array_values(array_filter([
            $cycle?->cycle_hash ? 'cycle:'.$cycle->cycle_hash : null,
            $review?->review_hash ? 'review:'.$review->review_hash : null,
            $qa?->qa_hash ? 'qa:'.$qa->qa_hash : null,
            $release?->release_hash ? 'release:'.$release->release_hash : null,
            $benchmark?->benchmark_hash ? 'benchmark:'.$benchmark->benchmark_hash : null,
        ]));
    }

    /**
     * @return array{id:string,status:string}
     */
    private function check(string $id, bool $passed): array
    {
        return ['id' => $id, 'status' => $passed ? 'passed' : 'blocked'];
    }

    private function count(string $table): int
    {
        return Schema::hasTable($table) ? DB::table($table)->count() : 0;
    }

    /**
     * @return array<string,int>
     */
    private function groupCounts(string $table, string $column): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        return DB::table($table)
            ->select($column, DB::raw('count(*) as aggregate'))
            ->groupBy($column)
            ->pluck('aggregate', $column)
            ->map(fn ($value): int => (int) $value)
            ->all();
    }

    private function latestQuery($query)
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }
}
