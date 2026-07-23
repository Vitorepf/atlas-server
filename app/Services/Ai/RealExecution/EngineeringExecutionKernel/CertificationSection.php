<?php

namespace App\Services\Ai\RealExecution\EngineeringExecutionKernel;

use App\Models\AiAutonomousEngineeringGoal;
use App\Models\AiEngineeringCompanyCycle;
use App\Models\AiEngineeringCompanyEngagement;
use App\Models\AiEngineeringCompanyRoleRun;
use App\Models\AiRealExecutionCertification;
use App\Models\AiRealExecutionDeliveryPack;
use App\Models\AiRealExecutionForgeHandoff;
use App\Models\AiRealExecutionPatchRun;
use App\Models\AiRealExecutionRepairAttempt;
use App\Models\AiRealExecutionRivalsBenchmark;
use App\Models\AiRealExecutionTestRun;
use App\Models\AiRealExecutionWorktree;
use App\Models\AtlasLedgerEvent;
use App\Services\Ai\AutonomousEngineering\AtlasAutonomousEngineeringService;
use App\Services\Ai\EngineeringCompany\AtlasRealEngineeringCompanyRuntimeService;
use App\Services\Ai\EngineeringCompany\EngineeringCompanyHash;
use App\Services\Ai\EngineeringKernel\AcceptanceBundle;
use App\Services\Ai\EngineeringKernel\Adapters\AtlasDevGateAdapter;
use App\Services\Ai\EngineeringKernel\CandidateQualityCase;
use App\Services\Ai\EngineeringKernel\CertVerdict;
use App\Services\Ai\EngineeringKernel\ExecutionOrder;
use App\Services\Ai\EngineeringKernel\MutativeVerificationReference;
use App\Services\Ai\EngineeringKernel\NonFunctional\ArchitectureRegressionProbe;
use App\Services\Ai\EngineeringKernel\NonFunctional\MigrationSafetyProbe;
use App\Services\Ai\EngineeringKernel\RoleDisposition;
use App\Services\Ai\EngineeringKernel\RoleEvidenceReceipt;
use App\Services\Ai\EngineeringKernel\Spec\AtlasSpecGateAdapter;
use App\Services\Ai\EngineeringKernel\Spec\IntentEnvelope;
use App\Services\Ai\EngineeringKernel\Spec\SpecDraft;
use App\Services\Ai\EngineeringKernel\TrustLevel;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Ai\Support\JsonFileStore;
use App\Services\Engineering\CodeGraph\CodeGraphSecretScanner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use Symfony\Component\Process\Process;
use App\Services\Ai\RealExecution\AtlasRealEngineeringExecutionKernelService;
use App\Services\Ai\RealExecution\CandidatePerformancePolicy;
use App\Services\Ai\RealExecution\RealExecutionHash;

class CertificationSection
{
    public function certify(?AiAutonomousEngineeringGoal $goal = null, string $scope = 'kernel'): AiRealExecutionCertification
    {
        $scope = in_array($scope, ['kernel', 'full'], true) ? $scope : 'kernel';
        $latestDelivery = DatabaseTableAvailability::has('ai_real_execution_delivery_packs')
            ? $this->latestQuery(AiRealExecutionDeliveryPack::query())->first()
            : null;
        $latestGoal = $goal
            ?? ($latestDelivery?->goal_record_id ? AiAutonomousEngineeringGoal::query()->find($latestDelivery->goal_record_id) : null)
            ?? (DatabaseTableAvailability::has('ai_autonomous_engineering_goals') ? $this->latestQuery(AiAutonomousEngineeringGoal::query())->first() : null);
        $checks = [
            $this->check('canonical_doc', File::exists(base_path('docs/engineering-knowledge-base/atlas-real-engineering-execution-kernel.md'))),
            $this->check('persistence_tables', $this->tablesReady()),
            $this->check('autonomous_goal_completed', $latestGoal?->status === 'completed'),
            $this->check('worktree_ready', $latestGoal !== null && DatabaseTableAvailability::has('ai_real_execution_worktrees') && AiRealExecutionWorktree::query()->where('goal_record_id', $latestGoal->id)->where('status', 'ready')->exists()),
            $this->check('patch_applied', $latestGoal !== null && DatabaseTableAvailability::has('ai_real_execution_patch_runs') && AiRealExecutionPatchRun::query()->where('goal_record_id', $latestGoal->id)->where('status', 'applied')->exists()),
            $this->check('impact_tests_passed', $latestGoal !== null && DatabaseTableAvailability::has('ai_real_execution_test_runs') && AiRealExecutionTestRun::query()->where('goal_record_id', $latestGoal->id)->where('status', 'passed')->exists()),
            $this->check('delivery_pack_ready', $latestGoal !== null && DatabaseTableAvailability::has('ai_real_execution_delivery_packs') && AiRealExecutionDeliveryPack::query()->where('goal_record_id', $latestGoal->id)->where('status', 'ready_for_internal_use')->exists()),
            $this->check('rivals_false_claim_blocked', $latestGoal !== null && DatabaseTableAvailability::has('ai_real_execution_rivals_benchmarks') && AiRealExecutionRivalsBenchmark::query()->where('goal_record_id', $latestGoal->id)->where('false_claim_blocked', true)->exists()),
            $this->check('rivals_shadow_benchmark_recorded', $latestGoal !== null && $this->rivalsShadowBenchmarkRecorded($latestGoal)),
        ];
        // SOVEREIGN GATE — the fake-green kill. The engineering green may NOT be emitted unless the
        // sovereign AcceptanceGate promotes the recorded evidence. This path records only a `php -l`
        // smoke, so the gate refuses it (false_claim_blocked): this stub can no longer certify green.
        $sovereignVerdict = $this->sovereignEngineeringVerdict($latestGoal);
        $checks[] = $this->check('sovereign_engineering_gate_promoted', $sovereignVerdict->promoted());
        if ($scope === 'full') {
            $checks[] = $this->check('external_rivals_benchmark_executed', $latestGoal !== null && $this->externalRivalsBenchmarkExecuted($latestGoal));
        }
        $blockers = array_values(array_map(
            fn (array $check): string => $check['id'],
            array_filter($checks, fn (array $check): bool => $check['status'] !== 'passed'),
        ));
        $evidence = $latestGoal ? $this->evidenceForGoal($latestGoal) : [];
        if ($blockers === [] && $evidence === []) {
            $blockers[] = 'missing_real_execution_evidence';
        }
        $status = $blockers === [] ? 'passed' : 'blocked';
        $certificationId = 'aerecert_'.substr(RealExecutionHash::make([$latestGoal?->goal_id, $checks, microtime(true)]), 0, 24);
        $payload = [
            'schema_version' => AtlasRealEngineeringExecutionKernelService::CERTIFICATION_SCHEMA,
            'certification_id' => $certificationId,
            'status' => $status,
            'scope' => $scope,
            'checks' => $checks,
            'blockers' => array_values(array_unique($blockers)),
            'claim_policy' => [
                'ready_to_claim_real_engineering_execution_kernel' => $status === 'passed',
                'ready_to_claim_100x_vs_claude_codex' => $scope === 'full' && $status === 'passed' && $latestGoal !== null && $this->externalRivalsClaimAllowed($latestGoal),
                'rivals_shadow_is_proof' => false,
                'external_rivals_benchmark_required_for_replacement_claim' => true,
            ],
            'evidence_refs' => $evidence,
        ];
        $payload['hash'] = RealExecutionHash::make($payload);

        return AiRealExecutionCertification::query()->create([
            'goal_record_id' => $latestGoal?->id,
            'certification_id' => $certificationId,
            'status' => $status,
            'scope' => $scope,
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
            $this->check('canonical_doc', File::exists(base_path('docs/engineering-knowledge-base/atlas-real-engineering-execution-kernel.md'))),
            $this->check('persistence_tables', $this->tablesReady()),
            $this->check('kernel_service', class_exists(AtlasRealEngineeringExecutionKernelService::class)),
            $this->check('autonomous_os_available', class_exists(AtlasAutonomousEngineeringService::class)),
        ];
        $blockers = array_values(array_map(fn (array $check): string => $check['id'], array_filter($checks, fn (array $check): bool => $check['status'] !== 'passed')));

        return [
            'schema_version' => 'atlas.ai.real_execution.readiness.v1',
            'status' => $blockers === [] ? 'passed' : 'blocked',
            'checks' => $checks,
            'blockers' => $blockers,
            'contracts' => [
                AtlasRealEngineeringExecutionKernelService::WORKTREE_SCHEMA,
                AtlasRealEngineeringExecutionKernelService::PATCH_SCHEMA,
                AtlasRealEngineeringExecutionKernelService::TEST_SCHEMA,
                AtlasRealEngineeringExecutionKernelService::REPAIR_SCHEMA,
                AtlasRealEngineeringExecutionKernelService::FORGE_HANDOFF_SCHEMA,
                AtlasRealEngineeringExecutionKernelService::DELIVERY_SCHEMA,
                AtlasRealEngineeringExecutionKernelService::RIVALS_BENCHMARK_SCHEMA,
                AtlasRealEngineeringExecutionKernelService::CERTIFICATION_SCHEMA,
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
            'schema_version' => 'atlas.ai.real_execution.control_plane.v1',
            'status' => 'ready',
            'counts' => [
                'worktrees' => $this->count('ai_real_execution_worktrees'),
                'patch_runs' => $this->count('ai_real_execution_patch_runs'),
                'test_runs' => $this->count('ai_real_execution_test_runs'),
                'repair_attempts' => $this->count('ai_real_execution_repair_attempts'),
                'forge_handoffs' => $this->count('ai_real_execution_forge_handoffs'),
                'delivery_packs' => $this->count('ai_real_execution_delivery_packs'),
                'rivals_benchmarks' => $this->count('ai_real_execution_rivals_benchmarks'),
                'certifications' => $this->count('ai_real_execution_certifications'),
            ],
            'observability' => [
                'patch_statuses' => $this->groupCounts('ai_real_execution_patch_runs', 'status'),
                'test_statuses' => $this->groupCounts('ai_real_execution_test_runs', 'status'),
                'repair_statuses' => $this->groupCounts('ai_real_execution_repair_attempts', 'status'),
                'delivery_statuses' => $this->groupCounts('ai_real_execution_delivery_packs', 'status'),
                'false_claims_blocked' => DatabaseTableAvailability::has('ai_real_execution_rivals_benchmarks')
                    ? AiRealExecutionRivalsBenchmark::query()->where('false_claim_blocked', true)->count()
                    : 0,
            ],
            'latest_delivery_pack' => DatabaseTableAvailability::has('ai_real_execution_delivery_packs')
                ? $this->latestQuery(AiRealExecutionDeliveryPack::query())->first()?->toArray()
                : null,
            'writes' => false,
        ];
    }
    public function createRivalsBenchmark(AiAutonomousEngineeringGoal $goal, AiRealExecutionDeliveryPack $delivery, bool $candidate): AiRealExecutionRivalsBenchmark
    {
        $benchmarkId = 'aerebench_'.substr(RealExecutionHash::make([$goal->goal_id, $delivery->delivery_hash]), 0, 24);
        $evidence = ['delivery:'.$delivery->delivery_hash];
        $arenaReadiness = $this->providerArenaReadiness();
        $receipt = [
            'schema_version' => AtlasRealEngineeringExecutionKernelService::RIVALS_BENCHMARK_SCHEMA,
            'benchmark_id' => $benchmarkId,
            'status' => 'shadow_ready',
            'rivals' => ['claude_code', 'codex'],
            'comparison_protocol' => [
                'mode' => 'audit_shadow',
                'external_provider_call' => false,
                'claim_allowed' => false,
                'requires_real_rerun_for_claim' => true,
            ],
            'provider_arena_readiness' => $arenaReadiness,
            'external_benchmark_gate' => [
                'status' => 'blocked_pending_operator_confirmations',
                'required_confirmations' => (array) ($arenaReadiness['required_confirmations_for_real_run'] ?? []),
                'external_provider_call' => false,
                'provider_tokens_spent' => false,
                'real_execution_evidence_refs' => [],
                'next_command' => $arenaReadiness['claude_codex_next_command'] ?? null,
            ],
            'false_claim_blocked' => true,
            'benchmark_candidate' => ['created' => $candidate, 'source' => 'real_execution_kernel'],
            'evidence_refs' => $evidence,
        ];
        $receipt['hash'] = RealExecutionHash::make($receipt);

        return AiRealExecutionRivalsBenchmark::query()->create([
            'goal_record_id' => $goal->id,
            'benchmark_id' => $benchmarkId,
            'status' => 'shadow_ready',
            'rivals' => $receipt['rivals'],
            'comparison_protocol' => $receipt['comparison_protocol'],
            'false_claim_blocked' => true,
            'benchmark_candidate' => $receipt['benchmark_candidate'],
            'evidence_refs' => $evidence,
            'receipt' => $receipt,
            'benchmark_hash' => $receipt['hash'],
        ]);
    }
    /**
     * @param  array<string,mixed>  $evidencePack
     * @return array<string,mixed>
     */
    public function importExternalRivalsBenchmark(array $evidencePack, ?string $goalId = null): array
    {
        $evidencePack = $this->normalizeExternalRivalsEvidence($evidencePack);
        $goal = $this->resolveGoal($goalId);
        if (! $goal) {
            return [
                'schema_version' => 'atlas.ai.real_execution.external_rivals_import.v1',
                'status' => 'blocked',
                'blockers' => ['goal_not_found'],
                'writes' => false,
            ];
        }

        if (in_array((string) data_get($evidencePack, 'status'), ['blocked', 'failed'], true)) {
            return $this->recordExternalRivalsBlocker($goal, $evidencePack);
        }

        $validation = $this->validateExternalRivalsEvidence($evidencePack);
        if ($validation['blockers'] !== []) {
            return [
                'schema_version' => 'atlas.ai.real_execution.external_rivals_import.v1',
                'status' => 'blocked',
                'goal_id' => $goal->goal_id,
                'blockers' => $validation['blockers'],
                'writes' => false,
            ];
        }

        $delivery = $this->latestQuery(AiRealExecutionDeliveryPack::query()->where('goal_record_id', $goal->id))->first();
        $benchmarkId = 'aerebench_ext_'.substr(RealExecutionHash::make([$goal->goal_id, $evidencePack]), 0, 20);
        $externalEvidenceHash = RealExecutionHash::make($evidencePack);
        $arenaReadiness = $this->providerArenaReadiness();
        $evidenceRefs = array_values(array_filter(array_merge(
            $delivery?->delivery_hash ? ['delivery:'.$delivery->delivery_hash] : [],
            ['external_rivals:'.$externalEvidenceHash],
            (array) ($validation['evidence_refs'] ?? []),
        )));
        $claimAllowed = (bool) data_get($evidencePack, 'claim_allowed', false);
        $receipt = [
            'schema_version' => AtlasRealEngineeringExecutionKernelService::RIVALS_BENCHMARK_SCHEMA,
            'benchmark_id' => $benchmarkId,
            'status' => 'external_executed',
            'rivals' => ['claude_code', 'codex'],
            'comparison_protocol' => [
                'mode' => 'external_provider_arena',
                'external_provider_call' => true,
                'claim_allowed' => $claimAllowed,
                'requires_real_rerun_for_claim' => false,
            ],
            'provider_arena_readiness' => $arenaReadiness,
            'external_benchmark_gate' => [
                'status' => 'executed',
                'run_id' => (string) data_get($evidencePack, 'run_id'),
                'external_provider_call' => true,
                'provider_tokens_spent' => true,
                'real_execution_evidence_refs' => $validation['evidence_refs'],
                'evidence_hash' => $externalEvidenceHash,
                'claim_allowed' => $claimAllowed,
            ],
            'false_claim_blocked' => ! $claimAllowed,
            'benchmark_candidate' => ['created' => false, 'source' => 'external_provider_arena_import'],
            'evidence_refs' => $evidenceRefs,
        ];
        $receipt['hash'] = RealExecutionHash::make($receipt);

        $benchmark = AiRealExecutionRivalsBenchmark::query()->create([
            'goal_record_id' => $goal->id,
            'benchmark_id' => $benchmarkId,
            'status' => 'external_executed',
            'rivals' => $receipt['rivals'],
            'comparison_protocol' => $receipt['comparison_protocol'],
            'false_claim_blocked' => ! $claimAllowed,
            'benchmark_candidate' => $receipt['benchmark_candidate'],
            'evidence_refs' => $evidenceRefs,
            'receipt' => $receipt,
            'benchmark_hash' => $receipt['hash'],
        ]);
        $certification = $this->certify($goal, 'full');

        return [
            'schema_version' => 'atlas.ai.real_execution.external_rivals_import.v1',
            'status' => $certification->status === 'passed' ? 'completed' : 'blocked',
            'goal_id' => $goal->goal_id,
            'rivals_benchmark' => $benchmark->toArray(),
            'certification' => $certification->toArray(),
            'writes' => true,
        ];
    }
    /**
     * @param  array<string,mixed>  $evidencePack
     * @return array<string,mixed>
     */
    public function recordExternalRivalsBlocker(AiAutonomousEngineeringGoal $goal, array $evidencePack): array
    {
        $blockers = array_values(array_filter((array) data_get($evidencePack, 'blockers', [])));
        if ($blockers === []) {
            $blockers = ['external_rivals_benchmark_blocked_without_blocker_detail'];
        }

        $benchmarkId = 'aerebench_block_'.substr(RealExecutionHash::make([$goal->goal_id, $evidencePack]), 0, 18);
        $evidenceHash = RealExecutionHash::make($evidencePack);
        $refs = array_values(array_filter(array_merge(
            ['external_rivals_attempt:'.$evidenceHash],
            (array) data_get($evidencePack, 'evidence_refs', []),
        )));
        $receipt = [
            'schema_version' => AtlasRealEngineeringExecutionKernelService::RIVALS_BENCHMARK_SCHEMA,
            'benchmark_id' => $benchmarkId,
            'status' => 'external_blocked',
            'rivals' => ['claude_code', 'codex'],
            'comparison_protocol' => [
                'mode' => 'external_provider_arena',
                'external_provider_call' => (bool) data_get($evidencePack, 'external_provider_call', false),
                'claim_allowed' => false,
                'requires_real_rerun_for_claim' => true,
            ],
            'provider_arena_readiness' => $this->providerArenaReadiness(),
            'external_benchmark_gate' => [
                'status' => 'blocked',
                'run_id' => (string) data_get($evidencePack, 'run_id', 'unknown'),
                'blockers' => $blockers,
                'external_provider_call' => (bool) data_get($evidencePack, 'external_provider_call', false),
                'provider_tokens_spent' => (bool) data_get($evidencePack, 'provider_tokens_spent', false),
                'real_execution_evidence_refs' => [],
                'evidence_hash' => $evidenceHash,
                'claim_allowed' => false,
            ],
            'false_claim_blocked' => true,
            'benchmark_candidate' => ['created' => true, 'source' => 'external_provider_arena_blocker'],
            'evidence_refs' => $refs,
        ];
        $receipt['hash'] = RealExecutionHash::make($receipt);

        $benchmark = AiRealExecutionRivalsBenchmark::query()->create([
            'goal_record_id' => $goal->id,
            'benchmark_id' => $benchmarkId,
            'status' => 'external_blocked',
            'rivals' => $receipt['rivals'],
            'comparison_protocol' => $receipt['comparison_protocol'],
            'false_claim_blocked' => true,
            'benchmark_candidate' => $receipt['benchmark_candidate'],
            'evidence_refs' => $refs,
            'receipt' => $receipt,
            'benchmark_hash' => $receipt['hash'],
        ]);
        $certification = $this->certify($goal, 'full');

        return [
            'schema_version' => 'atlas.ai.real_execution.external_rivals_import.v1',
            'status' => 'blocked',
            'goal_id' => $goal->goal_id,
            'blockers' => $blockers,
            'rivals_benchmark' => $benchmark->toArray(),
            'certification' => $certification->toArray(),
            'writes' => true,
        ];
    }
    /**
     * Route the recorded engineering evidence through the sovereign AcceptanceGate. This path only
     * ever records a `php -l` smoke, so the gate refuses it (false_claim_blocked) — which is exactly
     * how the fake-green is killed: the certification can no longer emit an engineering green.
     */
    public function sovereignEngineeringVerdict(?AiAutonomousEngineeringGoal $goal): CertVerdict
    {
        $test = ($goal !== null && DatabaseTableAvailability::has('ai_real_execution_test_runs'))
            ? $this->latestQuery(AiRealExecutionTestRun::query()->where('goal_record_id', $goal->id))->first()
            : null;
        $patch = ($goal !== null && DatabaseTableAvailability::has('ai_real_execution_patch_runs'))
            ? $this->latestQuery(AiRealExecutionPatchRun::query()->where('goal_record_id', $goal->id))->first()
            : null;

        $selected = $test !== null ? array_values((array) $test->selected_tests) : [];
        $changedFiles = $patch !== null ? array_values((array) $patch->changed_files) : [];

        $bundle = AcceptanceBundle::fromArray([
            'criteria_hash' => '',
            'frozen_hash' => '',
            'changed_files' => $changedFiles,
            'changed_public_symbols' => [],
            'execution' => [
                'commands' => $selected,
                'claimed_status' => ($test?->status === 'passed') ? 'passed' : 'failed',
                'tests_run' => 0,            // a lint runs zero test cases
                'assertions_executed' => 0,  // and zero assertions
                'selected_tests' => $selected,
                'artifacts' => $changedFiles,
            ],
            'mutation_report' => [],
            'security_scan' => [],
            'judges' => [],
            'context_sufficiency' => 0,
        ]);

        // Surface-aware witness-set: a forge-promoted goal is witnessed as Forge, otherwise the
        // autonomous loop's FrozenJudge witness. The invariants (the bar) are identical either way.
        $trust = ($goal?->promotion_target === 'atlas_forge') ? TrustLevel::Forge : TrustLevel::Autonomos;

        return app(AtlasDevGateAdapter::class)->certify($bundle, $trust);
    }
    public function tablesReady(): bool
    {
        foreach ([
            'ai_real_execution_worktrees',
            'ai_real_execution_patch_runs',
            'ai_real_execution_test_runs',
            'ai_real_execution_repair_attempts',
            'ai_real_execution_forge_handoffs',
            'ai_real_execution_delivery_packs',
            'ai_real_execution_rivals_benchmarks',
            'ai_real_execution_certifications',
        ] as $table) {
            if (! DatabaseTableAvailability::has($table)) {
                return false;
            }
        }

        return true;
    }
    /**
     * @return array<string,mixed>
     */
    public function providerArenaReadiness(): array
    {
        // Rivals 1.0 arena readiness service retired (see
        // atlas-rivals2-rebuild-map-v1.md): honest permanent unavailable shape.
        return [
            'status' => 'blocked',
            'blockers' => ['provider_arena_readiness_service_missing'],
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
        ];
    }
    public function rivalsShadowBenchmarkRecorded(AiAutonomousEngineeringGoal $goal): bool
    {
        $benchmark = $this->latestQuery(AiRealExecutionRivalsBenchmark::query()
            ->where('goal_record_id', $goal->id)
            ->where('status', 'shadow_ready')
            ->where('false_claim_blocked', true))->first();
        if (! $benchmark) {
            return false;
        }

        return data_get($benchmark->receipt, 'comparison_protocol.external_provider_call') === false
            && data_get($benchmark->receipt, 'comparison_protocol.claim_allowed') === false;
    }
    public function externalRivalsBenchmarkExecuted(AiAutonomousEngineeringGoal $goal): bool
    {
        $benchmark = $this->latestExternalRivalsBenchmark($goal);
        if (! $benchmark) {
            return false;
        }

        $gate = (array) data_get($benchmark->receipt, 'external_benchmark_gate', []);

        return ($gate['status'] ?? null) === 'executed'
            && ($gate['external_provider_call'] ?? false) === true
            && ($gate['provider_tokens_spent'] ?? false) === true
            && count((array) ($gate['real_execution_evidence_refs'] ?? [])) > 0;
    }
    public function externalRivalsClaimAllowed(AiAutonomousEngineeringGoal $goal): bool
    {
        $benchmark = $this->latestExternalRivalsBenchmark($goal);
        if (! $benchmark) {
            return false;
        }

        return (bool) data_get($benchmark->receipt, 'external_benchmark_gate.claim_allowed', false);
    }
    public function latestExternalRivalsBenchmark(AiAutonomousEngineeringGoal $goal): ?AiRealExecutionRivalsBenchmark
    {
        return $this->latestQuery(AiRealExecutionRivalsBenchmark::query()
            ->where('goal_record_id', $goal->id)
            ->where('status', 'external_executed'))->first();
    }
    public function resolveGoal(?string $goalId): ?AiAutonomousEngineeringGoal
    {
        if (is_string($goalId) && trim($goalId) !== '') {
            return AiAutonomousEngineeringGoal::query()
                ->where('id', $goalId)
                ->orWhere('goal_id', $goalId)
                ->first();
        }

        $latestDelivery = DatabaseTableAvailability::has('ai_real_execution_delivery_packs')
            ? $this->latestQuery(AiRealExecutionDeliveryPack::query())->first()
            : null;

        return ($latestDelivery?->goal_record_id ? AiAutonomousEngineeringGoal::query()->find($latestDelivery->goal_record_id) : null)
            ?? (DatabaseTableAvailability::has('ai_autonomous_engineering_goals') ? $this->latestQuery(AiAutonomousEngineeringGoal::query())->first() : null);
    }
    /**
     * @param  array<string,mixed>  $evidencePack
     * @return array{blockers:list<string>,evidence_refs:list<string>}
     */
    public function validateExternalRivalsEvidence(array $evidencePack): array
    {
        $blockers = [];
        if ((string) data_get($evidencePack, 'run_id') === '') {
            $blockers[] = 'run_id_missing';
        }
        if (data_get($evidencePack, 'external_provider_call') !== true) {
            $blockers[] = 'external_provider_call_not_true';
        }
        if (data_get($evidencePack, 'provider_tokens_spent') !== true) {
            $blockers[] = 'provider_tokens_spent_not_true';
        }
        if (! $this->evidencePackTargetsClaudeAndCodex($evidencePack)) {
            $blockers[] = 'claude_codex_arms_missing';
        }

        $refs = array_values(array_filter(array_merge(
            (array) data_get($evidencePack, 'evidence_refs', []),
            array_map(fn (string $path): string => 'evidence_path:'.$path, (array) data_get($evidencePack, 'evidence_paths', [])),
        )));
        if ($refs === []) {
            $blockers[] = 'external_evidence_refs_missing';
        }

        return ['blockers' => array_values(array_unique($blockers)), 'evidence_refs' => $refs];
    }
    /**
     * @param  array<string,mixed>  $evidencePack
     */
    public function evidencePackTargetsClaudeAndCodex(array $evidencePack): bool
    {
        $haystack = strtolower(json_encode([
            data_get($evidencePack, 'rivals', []),
            data_get($evidencePack, 'arm_a', []),
            data_get($evidencePack, 'arm_b', []),
            data_get($evidencePack, 'arms', []),
        ], JSON_THROW_ON_ERROR));

        return str_contains($haystack, 'claude')
            && str_contains($haystack, 'codex');
    }
    /**
     * @param  array<string,mixed>  $evidencePack
     * @return array<string,mixed>
     */
    public function normalizeExternalRivalsEvidence(array $evidencePack): array
    {
        $manifest = $this->jsonFileFromArtifact($evidencePack, 'manifest');
        $scorecard = $this->jsonFileFromArtifact($evidencePack, 'scorecard');
        $artifacts = (array) ($evidencePack['artifacts'] ?? []);
        $artifactPaths = array_values(array_filter(array_map(
            static fn ($artifact): ?string => is_array($artifact) && is_string($artifact['path'] ?? null) ? $artifact['path'] : null,
            $artifacts,
        )));
        $artifactRefs = [];
        foreach ($artifacts as $name => $artifact) {
            if (is_array($artifact) && is_string($artifact['sha256'] ?? null) && ($artifact['sha256'] ?? '') !== '') {
                $artifactRefs[] = 'artifact:'.$name.':'.$artifact['sha256'];
            }
        }

        return array_replace($evidencePack, array_filter([
            'run_id' => $evidencePack['run_id'] ?? $manifest['run_id'] ?? null,
            'external_provider_call' => $evidencePack['external_provider_call'] ?? $manifest['external_provider_call'] ?? null,
            'provider_tokens_spent' => $evidencePack['provider_tokens_spent'] ?? $manifest['provider_tokens_spent'] ?? null,
            'rivals' => $evidencePack['rivals'] ?? [
                (string) ($manifest['atlas_model'] ?? ''),
                (string) ($manifest['rival_model'] ?? ''),
            ],
            'arm_a' => $evidencePack['arm_a'] ?? ['model' => (string) ($manifest['atlas_model'] ?? '')],
            'arm_b' => $evidencePack['arm_b'] ?? ['model' => (string) ($manifest['rival_model'] ?? '')],
            'evidence_paths' => $evidencePack['evidence_paths'] ?? $artifactPaths,
            'evidence_refs' => $evidencePack['evidence_refs'] ?? $artifactRefs,
            'claim_allowed' => $evidencePack['claim_allowed'] ?? $scorecard['claim_ready'] ?? $evidencePack['claim_ready'] ?? null,
        ], static fn ($value): bool => $value !== null));
    }
    /**
     * @param  array<string,mixed>  $evidencePack
     * @return array<string,mixed>
     */
    public function jsonFileFromArtifact(array $evidencePack, string $artifactName): array
    {
        $path = data_get($evidencePack, 'artifacts.'.$artifactName.'.path');
        if (! is_string($path) || $path === '' || ! is_file($path)) {
            return [];
        }

        return JsonFileStore::readArray($path) ?? [];
    }
    public function count(string $table): int
    {
        return DatabaseTableAvailability::has($table) ? DB::table($table)->count() : 0;
    }
    /**
     * @return array<string,int>
     */
    public function groupCounts(string $table, string $column): array
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
    /**
     * @return list<string>
     */
    public function evidenceForGoal(AiAutonomousEngineeringGoal $goal): array
    {
        $worktree = $this->latestQuery(AiRealExecutionWorktree::query()->where('goal_record_id', $goal->id))->first();
        $patch = $this->latestQuery(AiRealExecutionPatchRun::query()->where('goal_record_id', $goal->id))->first();
        $test = $this->latestQuery(AiRealExecutionTestRun::query()->where('goal_record_id', $goal->id)->where('status', 'passed'))->first();
        $delivery = $this->latestQuery(AiRealExecutionDeliveryPack::query()->where('goal_record_id', $goal->id))->first();
        $benchmark = $this->latestQuery(AiRealExecutionRivalsBenchmark::query()->where('goal_record_id', $goal->id))->first();

        return array_values(array_filter([
            $worktree?->receipt_hash ? 'worktree:'.$worktree->receipt_hash : null,
            $patch?->patch_hash ? 'patch:'.$patch->patch_hash : null,
            $test?->test_hash ? 'test:'.$test->test_hash : null,
            $delivery?->delivery_hash ? 'delivery:'.$delivery->delivery_hash : null,
            $benchmark?->benchmark_hash ? 'rivals_benchmark:'.$benchmark->benchmark_hash : null,
        ]));
    }
    /**
     * @return array{id:string,status:string}
     */
    public function check(string $id, bool $passed): array
    {
        return ['id' => $id, 'status' => $passed ? 'passed' : 'blocked'];
    }
    public function latestQuery($query)
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }
}
