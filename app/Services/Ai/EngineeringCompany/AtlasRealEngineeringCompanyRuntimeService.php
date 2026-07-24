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
use App\Models\AiRealExecutionTestRun;
use App\Services\Ai\EngineeringKernel\CandidateQualityCase;
use App\Services\Ai\EngineeringKernel\EngineeringFinalCertifier;
use App\Services\Ai\EngineeringKernel\EngineeringQualityCourt;
use App\Services\Ai\EngineeringKernel\EngineeringRoleRoster;
use App\Services\Ai\EngineeringKernel\ExecutionOrder;
use App\Services\Ai\EngineeringKernel\KernelEvidenceAuthority;
use App\Services\Ai\EngineeringKernel\QualityCourtVerdict;
use App\Services\Ai\EngineeringKernel\RoleDisposition;
use App\Services\Ai\EngineeringKernel\RoleEvidenceReceipt;
use App\Services\Ai\RealExecution\AtlasRealEngineeringExecutionKernelService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class AtlasRealEngineeringCompanyRuntimeService
{
    public const ENGAGEMENT_SCHEMA = 'atlas.ai.engineering_company.engagement.v1';

    public const CYCLE_SCHEMA = 'atlas.ai.engineering_company.cycle.v1';

    public const ROLE_SCHEMA = 'atlas.ai.engineering_company.role_run.v1';

    public const QUALITY_ROLE_PRODUCER = 'atlas.engineering_company.quality_role.v1';

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

    /** Canonical Quality Foundry certification roster; legacy ROLES remains execution migration debt. */
    public const QUALITY_ROLES = [
        'product_strategy', 'product_management', 'domain_research', 'ux_research',
        'interaction_design', 'visual_design', 'architecture', 'backend', 'frontend',
        'mobile', 'data', 'qa_testing', 'appsec_privacy', 'performance_resilience',
        'devops_sre', 'observability', 'release', 'documentation_dx',
        'maintenance_simplification', 'outcome_analysis', 'evidence_audit', 'final_certification',
    ];

    /**
     * @return array<string,mixed>
     */
    public function run(string $goalText, array $options = []): array
    {
        $engagement = $this->createEngagement($goalText);
        $cycle = $this->createCycle($engagement);
        $realExecution = app(AtlasRealEngineeringExecutionKernelService::class)->run($goalText, [
            'context_sufficiency' => (int) ($options['context_sufficiency'] ?? 86),
            'test_status' => (string) ($options['test_status'] ?? 'passed'),
        ]);
        $review = $this->createReview($engagement, $realExecution, (string) ($options['review_status'] ?? 'passed'));
        $qa = $this->createQaRun($engagement, $realExecution, (string) ($options['qa_status'] ?? 'passed'));
        $release = $this->createReleasePack($engagement, $realExecution, $review, $qa);
        $benchmark = $this->createBenchmark($engagement, $realExecution);
        $qualityRoleRuns = [];
        foreach (self::QUALITY_ROLES as $role) {
            $roleStatus = data_get($realExecution, 'status') === 'completed' && $review->status === 'passed'
                && $qa->status === 'passed' && $release->status === 'ready_for_internal_delivery' ? 'passed' : 'blocked';
            $qualityRoleRuns[] = $this->runRole($engagement, $cycle, $role, $roleStatus, [
                'real_execution_status' => data_get($realExecution, 'status'),
                'review_hash' => $review->review_hash,
                'qa_hash' => $qa->qa_hash,
                'release_hash' => $release->release_hash,
                'benchmark_hash' => $benchmark->benchmark_hash,
                'role_depth' => $this->qualityRoleDepth($role, (string) ($options['risk_class'] ?? 'R3')),
            ]);
        }

        $certification = $this->certify($engagement);
        $engagementStatus = $certification->status === 'passed' ? 'completed' : 'blocked';
        $evidenceRefs = $this->evidenceForEngagement($engagement);
        $receipt = [
            'schema_version' => self::ENGAGEMENT_SCHEMA,
            'engagement_id' => $engagement->engagement_id,
            'status' => $engagementStatus,
            'roles' => self::QUALITY_ROLES,
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
                ...$qualityRoleRuns,
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

    private function qualityRoleDepth(string $role, string $riskClass): string
    {
        unset($role);

        return EngineeringRoleRoster::depthProfile($riskClass);
    }

    public function createEngagement(string $goalText): AiEngineeringCompanyEngagement
    {
        $engagementId = 'aecomp_'.substr(EngineeringCompanyHash::make([$goalText, microtime(true)]), 0, 24);
        $receipt = [
            'schema_version' => self::ENGAGEMENT_SCHEMA,
            'engagement_id' => $engagementId,
            'goal' => $goalText,
            'status' => 'accepted',
            'roles' => self::QUALITY_ROLES,
        ];
        $receipt['hash'] = EngineeringCompanyHash::make($receipt);

        return AiEngineeringCompanyEngagement::query()->create([
            'engagement_id' => $engagementId,
            'goal' => $goalText,
            'status' => 'accepted',
            'target_runtime' => 'atlas_real_execution_kernel',
            'roles' => self::QUALITY_ROLES,
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
            'roles' => self::QUALITY_ROLES,
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
        $agentTaskPacket = $this->buildRoleAgentTaskPacket($engagement, $cycle, $roleRunId, $roleId, $responsibilities);
        $output = array_merge($output, [
            'selected_depth' => (string) ($output['role_depth'] ?? ''),
            'agent_runtime_mode' => 'standard_agent_control_plane_task_packet',
            'agent_control_plane_task_packet' => [
                'schema_version' => AgentControlPlaneTaskPacketBuilder::SCHEMA_VERSION,
                'mode' => AgentControlPlaneTaskPacketBuilder::MODE,
                'task_packet_id' => $agentTaskPacket['task_packet_id'] ?? null,
                'status' => $agentTaskPacket['status'] ?? null,
                'task_packet_hash' => $agentTaskPacket['task_packet_hash'] ?? null,
                'scope_hash' => $agentTaskPacket['scope_hash'] ?? null,
                'acceptance_hash' => $agentTaskPacket['acceptance_hash'] ?? null,
                'dispatch_allowed' => $agentTaskPacket['dispatch_allowed'] ?? false,
                'provider_call_allowed' => $agentTaskPacket['provider_call_allowed'] ?? false,
                'token_spend_allowed' => $agentTaskPacket['token_spend_allowed'] ?? false,
            ],
        ]);
        $receipt = [
            'schema_version' => self::ROLE_SCHEMA,
            'role_run_id' => $roleRunId,
            'role_id' => $roleId,
            'status' => $status,
            'responsibilities' => $responsibilities,
            'output' => $output,
            'selected_depth' => $output['selected_depth'],
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

    /** @param list<AiEngineeringCompanyRoleRun> $priorRoleRuns */
    public function executeQualityRole(AiEngineeringCompanyEngagement $engagement, AiEngineeringCompanyCycle $cycle, ExecutionOrder $order, string $roleId, AiRealExecutionTestRun $verification, array $priorRoleRuns = []): AiEngineeringCompanyRoleRun
    {
        if (! $engagement->exists || ! $cycle->exists || ! in_array($roleId, self::QUALITY_ROLES, true)
            || ! $verification->exists || $verification->test_hash === null) {
            throw new \InvalidArgumentException('quality_role_disposition_invalid');
        }
        $evidenceRefs = ['verification:'.$verification->test_hash];
        $disposition = $roleId === 'final_certification'
            ? app(EngineeringFinalCertifier::class)->certify($order, $verification, $priorRoleRuns)
            : app(EngineeringQualityCourt::class)->adjudicateRole($order, $verification, $roleId);
        $roleRunId = 'aecompquality_'.substr(EngineeringCompanyHash::make([$engagement->engagement_id, $cycle->cycle_id, $roleId, microtime(true)]), 0, 22);
        $binding = ['run_id' => $order->runId, 'delivery_id' => $order->deliveryId, 'order_hash' => $order->canonicalHash(), 'spec_hash' => $order->specHash,
            'engagement_record_id' => (string) $engagement->getKey(), 'cycle_record_id' => (string) $cycle->getKey()];
        $output = ['disposition' => $disposition];
        $selectedDepth = $this->qualityRoleDepth($roleId, $order->riskClass);
        $output['selected_depth'] = $selectedDepth;
        $output['context'] = [
            'mode' => $order->mode,
            'complexity_band' => $order->complexityBand,
            'duration_regime' => $order->durationRegime,
            'topology' => $order->workTopology,
        ];
        $receipt = ['schema_version' => self::ROLE_SCHEMA, 'role_run_id' => $roleRunId, 'role_id' => $roleId,
            'selected_depth' => $selectedDepth,
            'status' => match ($disposition['status']) {
                'pass' => 'passed', 'not_applicable' => 'not_applicable', default => 'blocked'
            },
            'output' => $output, 'evidence_refs' => $evidenceRefs, 'binding' => $binding, 'disposition' => $disposition];
        $receipt['producer'] = $this->qualityProducerSeal($receipt);
        $receipt['hash'] = EngineeringCompanyHash::make($receipt);

        return AiEngineeringCompanyRoleRun::query()->create([
            'engagement_record_id' => $engagement->getKey(), 'cycle_record_id' => $cycle->getKey(), 'role_run_id' => $roleRunId,
            'role_id' => $roleId, 'status' => $receipt['status'], 'responsibilities' => [], 'output' => $output,
            'evidence_refs' => $evidenceRefs, 'receipt' => $receipt, 'role_hash' => $receipt['hash'],
        ]);
    }

    public function adjudicateMutativeCandidate(AiEngineeringCompanyEngagement $engagement, AiEngineeringCompanyCycle $cycle, CandidateQualityCase $case): QualityCourtVerdict
    {
        if (! $engagement->exists || ! $cycle->exists || $cycle->engagement_record_id !== $engagement->getKey()
            || (string) $engagement->getKey() !== $case->engagementRecordId || (string) $cycle->getKey() !== $case->cycleRecordId) {
            throw new \InvalidArgumentException('mutative_quality_case_company_owner_invalid');
        }
        $dispositions = [];
        foreach (array_slice(self::QUALITY_ROLES, 0, 21) as $role) {
            // Explicit mutative_applicability N/A must win before owner-receipt
            // special paths: otherwise surface/performance owners hard-block scoped
            // Dev smoke fixtures that honestly declare no surface/runtime effect.
            if (in_array($role, ['frontend', 'mobile', 'performance_resilience', 'backend'], true)) {
                $explicitNa = app(EngineeringQualityCourt::class)->adjudicateMutativeRole($case, $role);
                if ($explicitNa->status === 'not_applicable') {
                    $this->persistMutativeDisposition(
                        $engagement,
                        $cycle,
                        $case,
                        $explicitNa,
                        EngineeringQualityCourt::MUTATIVE_ABSENCE_DOMAIN,
                        'v1',
                    );
                    $dispositions[$role] = $explicitNa;

                    continue;
                }
            }
            if (in_array($role, ['frontend', 'mobile'], true)) {
                $surfaceRun = app(AtlasRealEngineeringExecutionKernelService::class)->persistCandidateSurfaceApplicabilityOwnerReceipt($engagement, $cycle, $case, $role);
                $disposition = app(EngineeringQualityCourt::class)->adjudicateMutativeRole($case, $role);
                app(KernelEvidenceAuthority::class)->issueMutativeRoleDisposition($surfaceRun, $case, []);
                $dispositions[$role] = $disposition;

                continue;
            }
            if ($role === 'performance_resilience') {
                $performanceRun = app(AtlasRealEngineeringExecutionKernelService::class)->persistCandidatePerformanceOwnerReceipt($engagement, $cycle, $case);
                $disposition = app(EngineeringQualityCourt::class)->adjudicateMutativeRole($case, $role);
                app(KernelEvidenceAuthority::class)->issueMutativeRoleDisposition($performanceRun, $case, []);
                $dispositions[$role] = $disposition;

                continue;
            }
            if ($role === 'appsec_privacy') {
                $appsecRun = app(AtlasRealEngineeringExecutionKernelService::class)->persistCandidateAppsecPrivacyOwnerReceipt($engagement, $cycle, $case);
                $disposition = app(EngineeringQualityCourt::class)->adjudicateMutativeRole($case, $role);
                app(KernelEvidenceAuthority::class)->issueMutativeRoleDisposition($appsecRun, $case, []);
                $dispositions[$role] = $disposition;

                continue;
            }
            if ($role === 'data') {
                $dataRun = app(AtlasRealEngineeringExecutionKernelService::class)->persistCandidateDataOwnerReceipt($engagement, $cycle, $case);
                $disposition = app(EngineeringQualityCourt::class)->adjudicateMutativeRole($case, $role);
                app(KernelEvidenceAuthority::class)->issueMutativeRoleDisposition($dataRun, $case, []);
                $dispositions[$role] = $disposition;

                continue;
            }
            if ($role === 'architecture') {
                $architectureRun = app(AtlasRealEngineeringExecutionKernelService::class)->persistCandidateArchitectureOwnerReceipt($engagement, $cycle, $case);
                $disposition = app(EngineeringQualityCourt::class)->adjudicateMutativeRole($case, $role);
                app(KernelEvidenceAuthority::class)->issueMutativeRoleDisposition($architectureRun, $case, []);
                $dispositions[$role] = $disposition;

                continue;
            }
            if ($role === 'qa_testing') {
                $qaRun = app(AtlasRealEngineeringExecutionKernelService::class)->persistCandidateQaOwnerReceipt($engagement, $cycle, $case);
                $disposition = app(EngineeringQualityCourt::class)->adjudicateMutativeRole($case, $role);
                app(KernelEvidenceAuthority::class)->issueMutativeRoleDisposition($qaRun, $case, []);
                $dispositions[$role] = $disposition;

                continue;
            }
            if ($role === 'backend') {
                try {
                    $backendRun = app(AtlasRealEngineeringExecutionKernelService::class)->persistCandidateBackendOwnerReceipt($engagement, $cycle, $case);
                    $disposition = app(EngineeringQualityCourt::class)->adjudicateMutativeRole($case, $role);
                    app(KernelEvidenceAuthority::class)->issueMutativeRoleDisposition($backendRun, $case, []);
                    $dispositions[$role] = $disposition;

                    continue;
                } catch (\Throwable) {
                    // Missing backend contract authority remains a sovereign block below.
                }
            }
            $disposition = app(EngineeringQualityCourt::class)->adjudicateMutativeRole($case, $role);
            $this->persistMutativeDisposition($engagement, $cycle, $case, $disposition, EngineeringQualityCourt::MUTATIVE_ABSENCE_DOMAIN, 'v1');
            $dispositions[$role] = $disposition;
        }
        $final = app(EngineeringFinalCertifier::class)->certifyCandidate($case);
        $this->persistMutativeDisposition($engagement, $cycle, $case, $final, EngineeringFinalCertifier::MUTATIVE_DOMAIN, 'v1');
        $dispositions['final_certification'] = $final;
        $authorityEligible = ! array_any($dispositions, static fn (RoleDisposition $disposition): bool => $disposition->status === 'block')
            && $dispositions['final_certification']->status === 'pass';
        $verdict = new QualityCourtVerdict($case->caseHash, $case->candidate->candidateHash, $dispositions, $authorityEligible);
        $persisted = AiEngineeringCompanyRoleRun::query()
            ->where('engagement_record_id', $engagement->getKey())
            ->whereIn('role_id', self::QUALITY_ROLES)
            ->get();
        if ($persisted->count() !== 22 || $persisted->pluck('role_id')->unique()->count() !== 22
            || $persisted->contains(static function (AiEngineeringCompanyRoleRun $run) use ($case): bool {
                $unsigned = array_diff_key((array) $run->receipt, ['hash' => true]);

                return data_get($run->receipt, 'binding.case_hash') !== $case->caseHash
                    || ! hash_equals((string) $run->role_hash, EngineeringCompanyHash::make($unsigned));
            })) {
            throw new \InvalidArgumentException('mutative_quality_receipt_reload_invalid');
        }

        return $verdict;
    }

    private function persistMutativeDisposition(AiEngineeringCompanyEngagement $engagement, AiEngineeringCompanyCycle $cycle, CandidateQualityCase $case, RoleDisposition $disposition, string $ownerDomain, string $ownerVersion): void
    {
        $role = $disposition->role;
        $roleRunId = 'aecompmut_'.substr(EngineeringCompanyHash::make([$case->caseHash, $role]), 0, 24);
        if (AiEngineeringCompanyRoleRun::query()->where('role_run_id', $roleRunId)->exists()) {
            throw new \InvalidArgumentException('mutative_quality_role_duplicate');
        }
        $evidenceRefs = ['candidate:'.$case->candidate->candidateHash, 'verification:'.$case->verification->receiptHash];
        $issuedAt = now()->startOfSecond();
        $expiresAt = $issuedAt->copy()->addHour();
        $typed = RoleEvidenceReceipt::issue(
            $case, $disposition, $ownerDomain, $ownerVersion, $issuedAt->toAtomString(), $expiresAt->toAtomString(), $evidenceRefs,
        );
        $output = ['disposition' => $disposition->toArray(), 'role_evidence_receipt' => $typed->toArray()];
        $binding = [
            'run_id' => $case->order->runId, 'delivery_id' => $case->order->deliveryId,
            'order_hash' => $case->order->canonicalHash(), 'spec_hash' => $case->order->specHash,
            'case_hash' => $case->caseHash, 'candidate_hash' => $case->candidate->candidateHash,
            'diff_hash' => $case->candidate->diffHash, 'tree_hash' => $case->candidate->treeHash,
            'engagement_record_id' => (string) $engagement->getKey(), 'cycle_record_id' => (string) $cycle->getKey(),
        ];
        $receipt = [
            'schema_version' => self::ROLE_SCHEMA, 'purpose' => 'mutative_candidate_quality_adjudication',
            'owner_domain' => $ownerDomain, 'owner_version' => $ownerVersion,
            'issued_at' => $issuedAt->toAtomString(), 'expires_at' => $expiresAt->toAtomString(),
            'role_run_id' => $roleRunId, 'role_id' => $role,
            'status' => $disposition->status === 'pass' ? 'passed' : ($disposition->status === 'not_applicable' ? 'not_applicable' : 'blocked'),
            'output' => $output, 'evidence_refs' => $evidenceRefs, 'binding' => $binding,
            'disposition' => $disposition->toArray(),
        ];
        $receipt['producer'] = $this->mutativeOwnerSeal($receipt, $ownerDomain);
        $receipt['hash'] = EngineeringCompanyHash::make($receipt);
        $roleRun = AiEngineeringCompanyRoleRun::query()->create([
            'engagement_record_id' => $engagement->getKey(), 'cycle_record_id' => $cycle->getKey(),
            'role_run_id' => $roleRunId, 'role_id' => $role, 'status' => $receipt['status'],
            'responsibilities' => [], 'output' => $output, 'evidence_refs' => $evidenceRefs,
            'receipt' => $receipt, 'role_hash' => $receipt['hash'],
        ]);
        app(KernelEvidenceAuthority::class)->issueMutativeRoleDisposition($roleRun, $case, []);
    }

    /** @param array<string,mixed> $payload @return array<string,string> */
    private function mutativeOwnerSeal(array $payload, string $domain): array
    {
        $key = $this->qualityProducerKeyMaterial();
        $seal = ['domain' => $domain, 'key_id' => 'app-key-'.substr(hash('sha256', $key), 0, 16), 'payload_hash' => EngineeringCompanyHash::make($payload)];
        $authorityKey = hash_hmac('sha256', 'atlas.engineering_kernel.evidence_authority.v1', $key, true);
        $seal['signature'] = hash_hmac('sha256', EngineeringCompanyHash::make($seal), hash_hmac('sha256', $domain, $authorityKey, true));

        return $seal;
    }

    /** @param array<string,mixed> $payload @return array<string,string> */
    private function qualityProducerSeal(array $payload): array
    {
        $key = $this->qualityProducerKeyMaterial();
        $domain = self::QUALITY_ROLE_PRODUCER;
        $seal = ['domain' => $domain, 'key_id' => 'app-key-'.substr(hash('sha256', $key), 0, 16), 'payload_hash' => EngineeringCompanyHash::make($payload)];
        $authorityKey = hash_hmac('sha256', 'atlas.engineering_kernel.evidence_authority.v1', $key, true);
        $seal['signature'] = hash_hmac('sha256', EngineeringCompanyHash::make($seal), hash_hmac('sha256', $domain, $authorityKey, true));

        return $seal;
    }

    private function qualityProducerKeyMaterial(): string
    {
        $key = (string) config('app.key');
        $decoded = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;

        return is_string($decoded) ? $decoded : '';
    }

    /**
     * @param  list<string>  $responsibilities
     * @return array<string,mixed>
     */
    private function buildRoleAgentTaskPacket(
        AiEngineeringCompanyEngagement $engagement,
        AiEngineeringCompanyCycle $cycle,
        string $roleRunId,
        string $roleId,
        array $responsibilities,
    ): array {
        return (new AgentControlPlaneTaskPacketBuilder)->build([
            'task_packet_id' => 'aecompagent_'.substr(EngineeringCompanyHash::make([$engagement->engagement_id, $roleRunId, $roleId]), 0, 20),
            'objective' => sprintf('Execute engineering company role [%s] for engagement [%s].', $roleId, $engagement->engagement_id),
            'source' => 'atlas_real_engineering_company_runtime',
            'operator_id' => 'atlas-company-runtime',
            'parent_run_id' => $cycle->cycle_id,
            'allowed_files' => ['app/Services/Ai/EngineeringCompany/AtlasRealEngineeringCompanyRuntimeService.php'],
            'forbidden_files' => ['routes/api.php', 'app/Services/Ai/SelfImprovement/'],
            'scope_in' => ['app/Services/Ai/EngineeringCompany/AtlasRealEngineeringCompanyRuntimeService.php'],
            'acceptance_criteria' => $responsibilities,
            'required_evidence' => [
                'role_receipt_created',
                'engagement_evidence_ref_attached',
                'cycle_evidence_ref_attached',
                'agent_task_packet_hash_attached',
            ],
            'risk_level' => in_array($roleId, ['senior_engineer', 'release_delivery_manager'], true) ? 'medium' : 'low',
            'max_runtime_seconds' => 3600,
            'max_token_budget' => 0,
            'workspace_policy' => [
                'workspace_id' => 'ATLAS-ENGINEERING-COMPANY',
                'isolation' => 'role_scoped_agent_packet',
                'auto_apply' => false,
            ],
            'continuation_context' => [
                'engagement_id' => $engagement->engagement_id,
                'cycle_id' => $cycle->cycle_id,
                'role_run_id' => $roleRunId,
                'role_id' => $roleId,
            ],
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
        $evidence = array_values(array_filter(
            ['real_delivery:'.data_get($realExecution, 'delivery_pack.delivery_hash')],
            static fn (string $ref): bool => $ref !== 'real_delivery:',
        ));
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
        $evidence = array_values(array_filter(
            ['test:'.data_get($realExecution, 'test_run.test_hash')],
            static fn (string $ref): bool => $ref !== 'test:',
        ));
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
        ], static fn (string $ref): bool => ! str_ends_with($ref, ':')));
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
        $evidence = array_values(array_filter(
            ['real_rivals:'.data_get($realExecution, 'rivals_benchmark.benchmark_hash')],
            static fn (string $ref): bool => $ref !== 'real_rivals:',
        ));
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
        $engagement ??= DatabaseTableAvailability::has('ai_engineering_company_engagements')
            ? $this->latestQuery(AiEngineeringCompanyEngagement::query())->first()
            : null;
        $checks = [
            $this->check('canonical_doc', File::exists(base_path('docs/engineering-knowledge-base/atlas-real-engineering-company-runtime.md'))),
            $this->check('persistence_tables', $this->tablesReady()),
            $this->check('engagement_exists', $engagement !== null),
            $this->check('all_roles_recorded', $engagement !== null && AiEngineeringCompanyRoleRun::query()->where('engagement_record_id', $engagement->id)->distinct('role_id')->count('role_id') >= count(self::QUALITY_ROLES)),
            $this->check('all_roles_have_agent_control_plane_task_packets', $engagement !== null && $this->allRolesHaveAgentTaskPackets($engagement)),
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
                'ready_to_claim_autonomous_software_company' => $status === 'passed',
                'ready_to_claim_external_superiority' => false,
                'external_benchmark_executed' => false,
                'rivals_provider_called' => false,
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
            'latest_engagement' => DatabaseTableAvailability::has('ai_engineering_company_engagements')
                ? $this->latestQuery(AiEngineeringCompanyEngagement::query())->first()?->toArray()
                : null,
            'writes' => false,
        ];
    }

    private function tablesReady(): bool
    {
        return DatabaseTableAvailability::all([
            'ai_engineering_company_engagements',
            'ai_engineering_company_cycles',
            'ai_engineering_company_role_runs',
            'ai_engineering_company_reviews',
            'ai_engineering_company_qa_runs',
            'ai_engineering_company_release_packs',
            'ai_engineering_company_benchmarks',
            'ai_engineering_company_certifications',
        ]);
    }

    private function allRolesHaveAgentTaskPackets(AiEngineeringCompanyEngagement $engagement): bool
    {
        $roles = AiEngineeringCompanyRoleRun::query()
            ->where('engagement_record_id', $engagement->id)
            ->get();

        if ($roles->count() < count(self::QUALITY_ROLES)) {
            return false;
        }

        foreach (self::QUALITY_ROLES as $roleId) {
            $role = $roles->firstWhere('role_id', $roleId);
            if (! $role instanceof AiEngineeringCompanyRoleRun) {
                return false;
            }
            if (data_get($role->output, 'agent_runtime_mode') !== 'standard_agent_control_plane_task_packet') {
                return false;
            }
            if (data_get($role->output, 'agent_control_plane_task_packet.schema_version') !== AgentControlPlaneTaskPacketBuilder::SCHEMA_VERSION) {
                return false;
            }
            if (data_get($role->output, 'agent_control_plane_task_packet.status') !== 'planned') {
                return false;
            }
            if (! is_string(data_get($role->output, 'agent_control_plane_task_packet.task_packet_hash')) || data_get($role->output, 'agent_control_plane_task_packet.task_packet_hash') === '') {
                return false;
            }
            if ((bool) data_get($role->output, 'agent_control_plane_task_packet.provider_call_allowed') !== false) {
                return false;
            }
            if ((bool) data_get($role->output, 'agent_control_plane_task_packet.token_spend_allowed') !== false) {
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
        return DatabaseTableAvailability::has($table) ? DB::table($table)->count() : 0;
    }

    /**
     * @return array<string,int>
     */
    private function groupCounts(string $table, string $column): array
    {
        if (! DatabaseTableAvailability::has($table)) {
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
