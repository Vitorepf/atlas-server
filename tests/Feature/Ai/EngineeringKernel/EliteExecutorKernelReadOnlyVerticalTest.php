<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\EngineeringKernel;

use App\Models\AiAutonomousEngineeringGoal;
use App\Models\AiEngineeringCompanyRoleRun;
use App\Models\AiRealExecutionPatchRun;
use App\Models\AiRealExecutionTestRun;
use App\Models\AtlasLedgerEvent;
use App\Services\Ai\AutonomousEngineering\AtlasAutonomousEngineeringService;
use App\Services\Ai\EngineeringCompany\AtlasRealEngineeringCompanyRuntimeService;
use App\Services\Ai\EngineeringCompany\EngineeringCompanyHash;
use App\Services\Ai\EngineeringKernel\AcceptanceBundle;
use App\Services\Ai\EngineeringKernel\CandidateQualityCase;
use App\Services\Ai\EngineeringKernel\CanonicalKernelPayload;
use App\Services\Ai\EngineeringKernel\EliteExecutorKernel;
use App\Services\Ai\EngineeringKernel\EngineeringFinalCertifier;
use App\Services\Ai\EngineeringKernel\EngineeringQualityCourt;
use App\Services\Ai\EngineeringKernel\EngineeringRoleRoster;
use App\Services\Ai\EngineeringKernel\ExecutionOrder;
use App\Services\Ai\EngineeringKernel\KernelEvidenceAuthority;
use App\Services\Ai\EngineeringKernel\OutcomeObservation;
use App\Services\Ai\EngineeringKernel\ProviderPort;
use App\Services\Ai\EngineeringKernel\RoleDisposition;
use App\Services\Ai\EngineeringKernel\RoleEvidenceReceipt;
use App\Services\Ai\EngineeringKernel\SovereignHonestyFloor;
use App\Services\Ai\EngineeringKernel\TrustLevel;
use App\Services\Ai\EngineeringKernel\VerifiedMutativeCandidate;
use App\Services\Ai\Kernel\Decision\DecisionReceipt;
use App\Services\Ai\Kernel\Decision\DecisionReceiptIssuer;
use App\Services\Ai\Kernel\Envelope\OperationEnvelopeFactory;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\RealExecution\AtlasRealEngineeringExecutionKernelService;
use App\Services\Ai\RealExecution\RealExecutionHash;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class EliteExecutorKernelReadOnlyVerticalTest extends TestCase
{
    private const ROLE_IDS = EngineeringRoleRoster::OFFICIAL_ROLES;

    private ?string $canonicalRunId = null;

    public function test_mutative_candidate_provider_refusal_has_zero_sandbox_authority(): void
    {
        $provider = $this->createMock(ProviderPort::class);
        $provider->expects($this->once())->method('invoke')->willReturn([
            'status' => 'unavailable', 'provider_invoked' => true, 'executes_provider' => true,
        ]);
        $this->app->instance(ProviderPort::class, $provider);
        $this->app->forgetInstance(EliteExecutorKernel::class);
        $data = $this->orderData();
        $data['tool_permissions']['mutate'] = true;

        $candidate = $this->app->make(EliteExecutorKernel::class)
            ->prepareMutativeCandidate(ExecutionOrder::fromArray($data));

        $this->assertSame('blocked', $candidate->status);
        $this->assertFalse($candidate->authorityEligible);
        $this->assertSame('', $candidate->sandboxRoot);
        $this->assertContains('provider_unavailable', $candidate->blockers);
    }

    public function test_mutative_candidate_provider_timeout_has_zero_sandbox_candidate(): void
    {
        $provider = $this->createMock(ProviderPort::class);
        $provider->method('invoke')->willThrowException(new \RuntimeException('timeout'));
        $this->app->instance(ProviderPort::class, $provider);
        $this->app->forgetInstance(EliteExecutorKernel::class);
        $data = $this->orderData();
        $data['tool_permissions']['mutate'] = true;

        $kernel = $this->app->make(EliteExecutorKernel::class);
        $candidate = $kernel->prepareMutativeCandidate(ExecutionOrder::fromArray($data));

        $this->assertSame('blocked', $candidate->status);
        $this->assertSame('', $candidate->sandboxRoot);
        $this->assertFalse($candidate->authorityEligible);
        $this->assertContains('provider_exception:RuntimeException', $candidate->blockers);
    }

    public function test_mutative_candidate_malformed_ok_provider_contract_has_zero_sandbox(): void
    {
        $provider = $this->createMock(ProviderPort::class);
        $provider->method('invoke')->willReturn(['status' => 'ok', 'provider_invoked' => true]);
        $this->app->instance(ProviderPort::class, $provider);
        $this->app->forgetInstance(EliteExecutorKernel::class);
        $data = $this->orderData();
        $data['tool_permissions']['mutate'] = true;

        $kernel = $this->app->make(EliteExecutorKernel::class);
        $candidate = $kernel->prepareMutativeCandidate(ExecutionOrder::fromArray($data));

        $this->assertSame('blocked', $candidate->status);
        $this->assertSame('', $candidate->sandboxRoot);
        $this->assertContains('provider_scope_mismatch', $candidate->blockers);
    }

    public function test_mutative_candidate_is_built_in_real_git_sandbox_with_independent_receipt_but_no_authority(): void
    {
        $repo = sys_get_temp_dir().'/atlas-mutative-source-'.uniqid('', true);
        mkdir($repo.'/app', 0775, true);
        mkdir($repo.'/tests', 0775, true);
        file_put_contents($repo.'/tests/CandidateBehaviorTest.php', "<?php\nexit((require dirname(__DIR__).'/app/Candidate.php') === 'after' ? 0 : 1);\n");
        foreach ([['init', '-b', 'main'], ['config', 'user.email', 'atlas@test.local'], ['config', 'user.name', 'Atlas Test'], ['add', '.'], ['commit', '-m', 'base']] as $args) {
            (new Process(['git', ...$args], $repo))->mustRun();
        }
        $base = trim((new Process(['git', 'rev-parse', 'HEAD'], $repo))->mustRun()->getOutput());
        $providerResult = [
            'status' => 'ok', 'provider_invoked' => true, 'provider' => 'fixture', 'model' => 'fixture-model',
            'output_hash' => hash('sha256', 'provider-output'),
            'patch_plan' => ['allowed_files' => ['app/Candidate.php', 'app/Unused.php', 'database/migrations/2026_01_01_000000_add_candidate_value.php'], 'patches' => [[
                'path' => 'app/Candidate.php', 'mode' => 'create', 'next' => "<?php\nreturn 'after';\n",
            ], [
                'path' => 'database/migrations/2026_01_01_000000_add_candidate_value.php', 'mode' => 'create', 'next' => <<<'PHP'
                    <?php
                    use Illuminate\Database\Migrations\Migration;
                    use Illuminate\Database\Schema\Blueprint;
                    use Illuminate\Support\Facades\Schema;
                    return new class extends Migration {
                        public function up(): void { Schema::table('atlas_probe_records', fn (Blueprint $table) => $table->string('candidate_value')->nullable()); }
                        public function down(): void { Schema::table('atlas_probe_records', fn (Blueprint $table) => $table->dropColumn('candidate_value')); }
                    };
                    PHP,
            ]]],
        ];
        $provider = $this->createMock(ProviderPort::class);
        $provider->method('invoke')->willReturn($providerResult);
        $this->app->instance(ProviderPort::class, $provider);
        $this->app->forgetInstance(EliteExecutorKernel::class);
        $data = $this->orderData();
        $data['tool_permissions']['mutate'] = true;
        $data['workspace'] = $repo;
        $data['base_commit'] = $base;
        $data['allowed_scope'] = ['app/Candidate.php', 'app/Unused.php', 'database/migrations/2026_01_01_000000_add_candidate_value.php'];
        $data['forbidden_scope'] = ['.env'];
        $data['provider_route'] = ['provider' => 'fixture', 'model' => 'fixture-model'];
        $data['idempotency_key'] = 'mutative-'.Str::uuid();
        $data['evidence_policy']['behavioral_profile'] = 'kernel_candidate_fixture_v1';

        $kernel = $this->app->make(EliteExecutorKernel::class);
        $candidate = $kernel->prepareMutativeCandidate(ExecutionOrder::fromArray($data));

        $this->assertSame('behaviorally_verified_pending_quality_court', $candidate->status, json_encode($candidate));
        $this->assertFalse($candidate->authorityEligible);
        $this->assertContains('mutative_22_role_court_receipt_absent', $candidate->blockers);
        $this->assertDirectoryExists($candidate->sandboxRoot.'/.git');
        $this->assertFileDoesNotExist($repo.'/app/Candidate.php');
        $this->assertSame("<?php\nreturn 'after';\n", file_get_contents($candidate->sandboxRoot.'/app/Candidate.php'));
        $this->assertNotSame('', $candidate->candidateHash);
        $this->assertSame(['app/Candidate.php', 'database/migrations/2026_01_01_000000_add_candidate_value.php'], $candidate->files);

        $authority = $this->app->make(KernelEvidenceAuthority::class);
        $verificationOwner = AiRealExecutionTestRun::query()->where('test_run_id', $candidate->verificationRunId)->firstOrFail();
        $verificationReceipt = (array) $verificationOwner->receipt;
        $this->assertTrue(data_get($verificationReceipt, 'behavioral.passed'));
        $this->assertFileExists((string) data_get($verificationReceipt, 'junit_artifact.path'));
        $this->assertTrue($authority->verifyMutativeVerificationReceipt($verificationReceipt));
        foreach (['hash', 'producer', 'diff_hash'] as $field) {
            $tampered = $verificationReceipt;
            $tampered[$field] = $field === 'producer' ? array_replace((array) $tampered[$field], ['signature' => str_repeat('0', 64)]) : str_repeat('0', 64);
            $this->assertFalse($authority->verifyMutativeVerificationReceipt($tampered), 'tamper accepted: '.$field);
        }

        $this->assertNotSame('', $candidate->verificationRunId);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $candidate->verificationHash);
        $this->assertSame($candidate->verificationHash, $verificationOwner->test_hash);
        $this->assertSame($candidate->candidateHash, data_get($verificationOwner->receipt, 'binding.candidate_hash'));
        $this->assertSame(hash_file('sha256', $candidate->sandboxRoot.'/app/Candidate.php'), data_get($verificationOwner->receipt, 'binding.source_hashes')['app/Candidate.php'] ?? null);
        $this->assertSame(RealExecutionHash::make($providerResult), data_get($verificationOwner->receipt, 'identities.provider_receipt_hash'));
        $this->assertNotSame(data_get($verificationOwner->receipt, 'identities.author'), data_get($verificationOwner->receipt, 'identities.verifier'));
        $this->assertNotSame(data_get($verificationOwner->receipt, 'identities.provider'), data_get($verificationOwner->receipt, 'identities.verifier'));
        $company = app(AtlasRealEngineeringCompanyRuntimeService::class);
        $engagement = $company->createEngagement('candidate quality court persistence');
        $cycle = $company->createCycle($engagement);
        $qualityCase = CandidateQualityCase::fromCandidate(ExecutionOrder::fromArray($data), $candidate, $engagement, $cycle);
        $this->assertSame('block', app(EngineeringQualityCourt::class)->adjudicateMutativeRole($qualityCase, 'qa_testing')->status);
        $missingPrior = app(EngineeringFinalCertifier::class)->certifyCandidate($qualityCase);
        $this->assertSame('block', $missingPrior->status);
        $this->assertSame('prior_21_not_all_pass_or_na', $missingPrior->reason);
        $persistedVerdict = $company->adjudicateMutativeCandidate($engagement, $cycle, $qualityCase);
        $persisted = AiEngineeringCompanyRoleRun::query()
            ->where('engagement_record_id', $engagement->getKey())
            ->whereIn('role_id', self::ROLE_IDS)
            ->get();

        $this->assertCount(22, $persisted);
        $this->assertFalse($persistedVerdict->authorityEligible);
        $this->assertSame(self::ROLE_IDS, array_keys($persistedVerdict->dispositions));
        $this->assertSame(['block', 'pass'], array_values(array_unique(array_map(static fn ($disposition): string => $disposition->status, $persistedVerdict->dispositions))));
        $passingRoles = array_keys(array_filter(
            $persistedVerdict->dispositions,
            static fn ($disposition): bool => $disposition->status === 'pass',
        ));
        $blockingRoles = array_keys(array_filter(
            $persistedVerdict->dispositions,
            static fn ($disposition): bool => $disposition->status === 'block',
        ));
        $this->assertSame(['architecture', 'data', 'qa_testing'], $passingRoles);
        $this->assertCount(19, $blockingRoles);
        $this->assertContains('final_certification', $blockingRoles);
        $this->assertSame('candidate_architecture_probe_clean', $persistedVerdict->dispositions['architecture']->reason);
        $this->assertSame('pass', $persistedVerdict->dispositions['qa_testing']->status);
        $this->assertSame('candidate_mechanical_and_behavioral_verification_passed', $persistedVerdict->dispositions['qa_testing']->reason);
        $this->assertSame('owner_evidence_absent', $persistedVerdict->dispositions['evidence_audit']->reason);
        $this->assertSame('prior_21_not_all_pass_or_na', $persistedVerdict->dispositions['final_certification']->reason);
        $this->assertSame(22, $persisted->pluck('role_id')->unique()->count());
        $qaOwner = $persisted->firstWhere('role_id', 'qa_testing');
        $architectureOwner = $persisted->firstWhere('role_id', 'architecture');
        $dataOwner = $persisted->firstWhere('role_id', 'data');
        $this->assertSame(AtlasRealEngineeringExecutionKernelService::CANDIDATE_DATA_OWNER_DOMAIN, data_get($dataOwner->receipt, 'owner_domain'));
        $this->assertTrue((bool) data_get($dataOwner->receipt, 'data_evidence.isolated_db.forward_passed'));
        $this->assertTrue((bool) data_get($dataOwner->receipt, 'data_evidence.isolated_db.n_minus_1_passed'));
        $this->assertTrue((bool) data_get($dataOwner->receipt, 'data_evidence.isolated_db.rollback_passed'));
        $originalDataReceipt = $dataOwner->receipt;
        foreach (['stale', 'tamper'] as $dataAttack) {
            $attackedDataReceipt = $originalDataReceipt;
            if ($dataAttack === 'stale') {
                $attackedDataReceipt['expires_at'] = now()->subMinute()->startOfSecond()->toAtomString();
            } else {
                $attackedDataReceipt['data_evidence']['isolated_db']['rollback_passed'] = false;
            }
            $dataOwner->forceFill(['receipt' => $attackedDataReceipt])->save();
            $this->assertSame('block', app(EngineeringQualityCourt::class)->adjudicateMutativeRole($qualityCase, 'data')->status, $dataAttack);
            $dataOwner->forceFill(['receipt' => $originalDataReceipt])->save();
        }
        $dataArtifactPath = (string) data_get($originalDataReceipt, 'data_evidence.raw_artifact.path');
        $dataArtifact = file_get_contents($dataArtifactPath);
        $this->assertIsString($dataArtifact);
        unlink($dataArtifactPath);
        $this->assertSame('block', app(EngineeringQualityCourt::class)->adjudicateMutativeRole($qualityCase, 'data')->status, 'data_artifact_unavailable');
        file_put_contents($dataArtifactPath, $dataArtifact);
        $this->assertSame('pass', app(EngineeringQualityCourt::class)->adjudicateMutativeRole($qualityCase, 'data')->status);
        $this->assertSame(AtlasRealEngineeringExecutionKernelService::CANDIDATE_ARCHITECTURE_OWNER_DOMAIN, data_get($architectureOwner->receipt, 'owner_domain'));
        $this->assertSame($qualityCase->caseHash, data_get($architectureOwner->receipt, 'architecture_evidence.case_hash'));
        $this->assertSame($candidate->treeHash, data_get($architectureOwner->receipt, 'architecture_evidence.tree_hash'));
        $this->assertSame([], data_get($architectureOwner->receipt, 'architecture_evidence.violations'));
        $this->assertFileExists((string) data_get($architectureOwner->receipt, 'architecture_evidence.raw_artifact.path'));
        $this->assertNotSame($candidate->providerIdentity, data_get($architectureOwner->receipt, 'owner_identity'));
        $this->assertNotSame($candidate->authorIdentity, data_get($architectureOwner->receipt, 'owner_identity'));
        $originalArchitectureReceipt = $architectureOwner->receipt;
        foreach (['stale', 'changed_probe_contract'] as $architectureAttack) {
            $attackedArchitectureReceipt = $originalArchitectureReceipt;
            if ($architectureAttack === 'stale') {
                $attackedArchitectureReceipt['expires_at'] = now()->subMinute()->startOfSecond()->toAtomString();
            } else {
                $attackedArchitectureReceipt['architecture_evidence']['probe_contract_hash'] = str_repeat('0', 64);
            }
            $architectureOwner->forceFill(['receipt' => $attackedArchitectureReceipt])->save();
            $this->assertSame('block', app(EngineeringQualityCourt::class)->adjudicateMutativeRole($qualityCase, 'architecture')->status, $architectureAttack);
            $architectureOwner->forceFill(['receipt' => $originalArchitectureReceipt])->save();
        }
        $architectureArtifactPath = (string) data_get($originalArchitectureReceipt, 'architecture_evidence.raw_artifact.path');
        $architectureArtifact = file_get_contents($architectureArtifactPath);
        $this->assertIsString($architectureArtifact);
        unlink($architectureArtifactPath);
        $this->assertSame('block', app(EngineeringQualityCourt::class)->adjudicateMutativeRole($qualityCase, 'architecture')->status, 'architecture_artifact_unavailable');
        file_put_contents($architectureArtifactPath, $architectureArtifact);
        $this->assertSame('pass', app(EngineeringQualityCourt::class)->adjudicateMutativeRole($qualityCase, 'architecture')->status);
        $this->assertSame(AtlasRealEngineeringExecutionKernelService::CANDIDATE_QA_OWNER_DOMAIN, data_get($qaOwner->receipt, 'owner_domain'));
        $this->assertSame($candidate->baseCommit, data_get($qaOwner->receipt, 'qa_evidence.base_commit'));
        $this->assertSame($candidate->files, data_get($qaOwner->receipt, 'qa_evidence.files'));
        $this->assertSame(data_get($verificationReceipt, 'behavioral.runner_hash'), data_get($qaOwner->receipt, 'qa_evidence.runner_hash'));
        $this->assertSame(data_get($verificationReceipt, 'junit_artifact.sha256'), data_get($qaOwner->receipt, 'qa_evidence.mechanical_junit.sha256'));
        $this->assertSame(data_get($verificationReceipt, 'behavioral.junit_artifact.sha256'), data_get($qaOwner->receipt, 'qa_evidence.behavioral_junit.sha256'));
        $this->assertNotSame((string) data_get($providerResult, 'provider'), (string) data_get($qaOwner->receipt, 'owner_domain'));
        $qaOwnerParameters = array_map(
            static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
            (new \ReflectionMethod(AtlasRealEngineeringExecutionKernelService::class, 'persistCandidateQaOwnerReceipt'))->getParameters(),
        );
        $this->assertSame(['engagement', 'cycle', 'case'], $qaOwnerParameters);
        $this->assertTrue($persisted->every(fn (AiEngineeringCompanyRoleRun $run): bool => data_get($run->receipt, 'binding.case_hash') === $qualityCase->caseHash
            && data_get($run->receipt, 'binding.candidate_hash') === $candidate->candidateHash
            && data_get($run->receipt, 'binding.diff_hash') === $candidate->diffHash
            && data_get($run->receipt, 'binding.tree_hash') === $candidate->treeHash));
        $this->assertSame(22, AtlasLedgerEvent::query()
            ->where('emitter_stage', KernelEvidenceAuthority::EMITTER_STAGE)
            ->get()
            ->filter(fn (AtlasLedgerEvent $event): bool => data_get($event->payload, 'event_name') === 'role.mutative_disposition.recorded'
                && data_get($event->payload, 'case_hash') === $qualityCase->caseHash)
            ->count());
        $originalQaReceipt = $qaOwner->receipt;
        foreach (['artifact_tamper', 'stale', 'red', 'self_owner_mismatch'] as $attack) {
            $attackedQaReceipt = $originalQaReceipt;
            if ($attack === 'artifact_tamper') {
                $attackedQaReceipt['qa_evidence']['runner_hash'] = str_repeat('0', 64);
            } elseif ($attack === 'stale') {
                $attackedQaReceipt['expires_at'] = now()->subMinute()->startOfSecond()->toAtomString();
            } elseif ($attack === 'red') {
                $attackedQaReceipt['status'] = 'blocked';
            } else {
                $attackedQaReceipt['owner_domain'] = 'atlas.provider.author.v1';
            }
            $qaOwner->forceFill(['receipt' => $attackedQaReceipt])->save();
            $this->assertSame('block', app(EngineeringQualityCourt::class)->adjudicateMutativeRole($qualityCase, 'qa_testing')->status, $attack);
            $qaOwner->forceFill(['receipt' => $originalQaReceipt])->save();
        }
        $mechanicalJunitPath = (string) data_get($originalQaReceipt, 'qa_evidence.mechanical_junit.path');
        $mechanicalJunit = file_get_contents($mechanicalJunitPath);
        $this->assertIsString($mechanicalJunit);
        unlink($mechanicalJunitPath);
        $this->assertSame('block', app(EngineeringQualityCourt::class)->adjudicateMutativeRole($qualityCase, 'qa_testing')->status, 'missing_artifact');
        file_put_contents($mechanicalJunitPath, $mechanicalJunit);
        $this->assertSame('pass', app(EngineeringQualityCourt::class)->adjudicateMutativeRole($qualityCase, 'qa_testing')->status);
        $verificationSnapshot = [
            'goal_record_id' => $verificationOwner->goal_record_id,
            'patch_run_record_id' => $verificationOwner->patch_run_record_id,
            'schema_version' => $verificationOwner->schema_version,
            'test_run_id' => $verificationOwner->test_run_id,
            'status' => $verificationOwner->status,
            'selected_tests' => $verificationOwner->selected_tests,
            'impact_reasoning' => $verificationOwner->impact_reasoning,
            'exit_code' => $verificationOwner->exit_code,
            'output_excerpt' => $verificationOwner->output_excerpt,
            'evidence_refs' => $verificationOwner->evidence_refs,
            'receipt' => $verificationOwner->receipt,
            'test_hash' => $verificationOwner->test_hash,
        ];
        $verificationOwner->delete();
        $this->assertSame('block', app(EngineeringQualityCourt::class)->adjudicateMutativeRole($qualityCase, 'qa_testing')->status, 'missing_verification_owner');
        $verificationOwner = AiRealExecutionTestRun::query()->create($verificationSnapshot);
        $this->assertSame('pass', app(EngineeringQualityCourt::class)->adjudicateMutativeRole($qualityCase, 'qa_testing')->status);

        $otherEngagement = $company->createEngagement('cross engagement transplant');
        $otherCycle = $company->createCycle($otherEngagement);
        $this->assertInvalidArgumentMessage(
            fn () => $company->adjudicateMutativeCandidate($otherEngagement, $otherCycle, $qualityCase),
            'mutative_quality_case_company_owner_invalid',
        );
        $originalEngagementId = $qaOwner->engagement_record_id;
        $originalCycleId = $qaOwner->cycle_record_id;
        $qaOwner->forceFill(['engagement_record_id' => $otherEngagement->getKey(), 'cycle_record_id' => $otherCycle->getKey()])->save();
        $this->assertSame('block', app(EngineeringQualityCourt::class)->adjudicateMutativeRole($qualityCase, 'qa_testing')->status, 'cross_engagement_cycle_transplant');
        $qaOwner->forceFill(['engagement_record_id' => $originalEngagementId, 'cycle_record_id' => $originalCycleId])->save();
        $this->assertSame('pass', app(EngineeringQualityCourt::class)->adjudicateMutativeRole($qualityCase, 'qa_testing')->status);
        $certifier = app(EngineeringFinalCertifier::class);
        $beforeDuplicate = $certifier->certifyCandidate($qualityCase);
        $duplicate = $persisted->firstWhere('role_id', 'product_management')->replicate();
        $duplicate->role_run_id = 'duplicate-'.Str::uuid();
        $duplicateReceipt = $duplicate->receipt;
        $duplicateReceipt['role_run_id'] = $duplicate->role_run_id;
        unset($duplicateReceipt['hash'], $duplicateReceipt['producer']);
        $sealMethod = new \ReflectionMethod(AtlasRealEngineeringCompanyRuntimeService::class, 'mutativeOwnerSeal');
        $duplicateReceipt['producer'] = $sealMethod->invoke($company, $duplicateReceipt, EngineeringQualityCourt::MUTATIVE_ABSENCE_DOMAIN);
        $duplicateReceipt['hash'] = EngineeringCompanyHash::make($duplicateReceipt);
        $duplicate->receipt = $duplicateReceipt;
        $duplicate->role_hash = $duplicateReceipt['hash'];
        $duplicate->save();
        $this->assertNotSame($beforeDuplicate->signature, $certifier->certifyCandidate($qualityCase)->signature);
        $duplicate->delete();

        $this->assertInvalidArgumentMessage(
            fn () => $company->adjudicateMutativeCandidate($engagement, $cycle, $qualityCase),
            'mutative_quality_role_duplicate',
        );
        $changed = new VerifiedMutativeCandidate(
            $candidate->status, $candidate->orderHash, $candidate->candidateHash, $candidate->baseCommit,
            $candidate->treeHash, str_repeat('0', 64), $candidate->files, $candidate->sandboxRoot,
            $candidate->providerReceipt, $candidate->sandboxReceipt, $candidate->verificationRunId, $candidate->verificationHash,
            $candidate->providerIdentity, $candidate->authorIdentity, $candidate->verifierIdentity,
            $candidate->blockers, false,
        );
        $this->assertInvalidArgumentMessage(
            fn () => CandidateQualityCase::fromCandidate(ExecutionOrder::fromArray($data), $changed, $engagement, $cycle),
            'candidate_quality_case_binding_invalid',
        );
        $replayed = new VerifiedMutativeCandidate(
            $candidate->status, $candidate->orderHash, str_repeat('0', 64), $candidate->baseCommit,
            $candidate->treeHash, $candidate->diffHash, $candidate->files, $candidate->sandboxRoot,
            $candidate->providerReceipt, $candidate->sandboxReceipt, $candidate->verificationRunId, $candidate->verificationHash,
            $candidate->providerIdentity, $candidate->authorIdentity, $candidate->verifierIdentity,
            $candidate->blockers, false,
        );
        $this->assertInvalidArgumentMessage(
            fn () => CandidateQualityCase::fromCandidate(ExecutionOrder::fromArray($data), $replayed, $engagement, $cycle),
            'mutative_verification_owner_invalid',
        );
        foreach (['outside_artifact', 'provider_as_verifier'] as $attack) {
            if ($attack === 'outside_artifact') {
                $attackedReceipt = $verificationReceipt;
                $attackedReceipt['junit_artifact']['path'] = $repo.'/tests/CandidateBehaviorTest.php';
                $attackedReceipt['junit_artifact']['sha256'] = hash_file('sha256', $repo.'/tests/CandidateBehaviorTest.php');
                $verificationOwner->forceFill(['receipt' => $attackedReceipt])->save();
                $this->assertInvalidArgumentMessage(
                    fn () => CandidateQualityCase::fromCandidate(ExecutionOrder::fromArray($data), $candidate, $engagement, $cycle),
                    'mutative_verification_owner_invalid',
                );
                $verificationOwner->forceFill(['receipt' => $verificationReceipt])->save();
            } else {
                $attacked = new VerifiedMutativeCandidate(
                    $candidate->status, $candidate->orderHash, $candidate->candidateHash, $candidate->baseCommit,
                    $candidate->treeHash, $candidate->diffHash, $candidate->files, $candidate->sandboxRoot,
                    $candidate->providerReceipt, $candidate->sandboxReceipt, $candidate->verificationRunId, $candidate->verificationHash,
                    $candidate->verifierIdentity, $candidate->authorIdentity, $candidate->verifierIdentity,
                    $candidate->blockers, false,
                );
                $this->assertInvalidArgumentMessage(
                    fn () => CandidateQualityCase::fromCandidate(ExecutionOrder::fromArray($data), $attacked, $engagement, $cycle),
                    'mutative_verification_owner_invalid',
                );
            }
        }
        $forged = $persisted->firstWhere('role_id', 'product_strategy');
        $forgedOutput = $forged->output;
        $forgedOutput['disposition']['status'] = 'pass';
        $forged->forceFill(['output' => $forgedOutput])->save();
        $this->assertInvalidArgumentMessage(
            fn () => $authority->issueMutativeRoleDisposition($forged, $qualityCase, []),
            'kernel_mutative_role_receipt_binding_invalid',
        );
        $this->assertSame('block', app(EngineeringFinalCertifier::class)->certifyCandidate($qualityCase)->status);
        $wrongDomain = $persisted->firstWhere('role_id', 'domain_research');
        $wrongDomainReceipt = $wrongDomain->receipt;
        $wrongDomainReceipt['owner_domain'] = 'atlas.engineering_kernel.provider_as_verifier.v1';
        $wrongDomain->forceFill(['receipt' => $wrongDomainReceipt])->save();
        $this->assertFalse($authority->mutativeRoleReceiptValid(
            $wrongDomain, $qualityCase, EngineeringQualityCourt::MUTATIVE_ABSENCE_DOMAIN, 'v1',
        ));
        $expired = $persisted->firstWhere('role_id', 'ux_research');
        $expiredReceipt = $expired->receipt;
        $expiredReceipt['expires_at'] = now()->subMinute()->startOfSecond()->toAtomString();
        $expired->forceFill(['receipt' => $expiredReceipt])->save();
        $this->assertFalse($authority->mutativeRoleReceiptValid(
            $expired, $qualityCase, EngineeringQualityCourt::MUTATIVE_ABSENCE_DOMAIN, 'v1',
        ));
        $certifierParameters = array_map(
            static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
            (new \ReflectionMethod(EngineeringFinalCertifier::class, 'certifyCandidate'))->getParameters(),
        );
        $this->assertSame(['case'], $certifierParameters);
        $this->assertTrue((new \ReflectionClass(RoleDisposition::class))->getConstructor()?->isPrivate());
        $this->assertTrue((new \ReflectionClass(RoleEvidenceReceipt::class))->getConstructor()?->isPrivate());
        $redProvider = $this->createMock(ProviderPort::class);
        $red = $providerResult;
        $red['patch_plan']['patches'][0]['next'] = "<?php\nreturn 'wrong';\n";
        $redProvider->method('invoke')->willReturn($red);
        $this->app->instance(ProviderPort::class, $redProvider);
        $this->app->forgetInstance(EliteExecutorKernel::class);
        $redData = $data;
        $redData['idempotency_key'] = 'mutative-red-'.Str::uuid();
        $redCandidate = $this->app->make(EliteExecutorKernel::class)->prepareMutativeCandidate(ExecutionOrder::fromArray($redData));
        $this->assertSame('blocked', $redCandidate->status);
        $this->assertTrue(array_any($redCandidate->blockers, static fn (string $blocker): bool => str_starts_with($blocker, 'independent_verification_refused:')));

        foreach (['provider_is_verifier', 'author_is_verifier'] as $identityAttack) {
            $identityProvider = $this->createMock(ProviderPort::class);
            $identityResult = $providerResult;
            if ($identityAttack === 'provider_is_verifier') {
                $identityResult['provider'] = AtlasRealEngineeringExecutionKernelService::KERNEL_VERIFICATION_PRODUCER;
            } else {
                $identityResult['author_identity'] = AtlasRealEngineeringExecutionKernelService::KERNEL_VERIFICATION_PRODUCER;
            }
            $identityProvider->method('invoke')->willReturn($identityResult);
            $this->app->instance(ProviderPort::class, $identityProvider);
            $this->app->forgetInstance(EliteExecutorKernel::class);
            $identityData = $data;
            $identityData['idempotency_key'] = 'mutative-identity-'.$identityAttack.'-'.Str::uuid();
            $identityCandidate = $this->app->make(EliteExecutorKernel::class)->prepareMutativeCandidate(ExecutionOrder::fromArray($identityData));
            $this->assertSame('blocked', $identityCandidate->status, $identityAttack);
            $this->assertTrue(array_any($identityCandidate->blockers, static fn (string $blocker): bool => str_contains($blocker, 'hermetic_candidate_verifier_independence_invalid')), $identityAttack);
        }

        $unsafeProvider = $this->createMock(ProviderPort::class);
        $unsafeResult = $providerResult;
        $unsafeResult['patch_plan']['patches'][0]['next'] = <<<'PHP'
            <?php
            namespace App\Services\Ai\EngineeringKernel\UnsafeFixture;
            use App\Models\User;
            return 'after';
            PHP;
        $unsafeProvider->method('invoke')->willReturn($unsafeResult);
        $this->app->instance(ProviderPort::class, $unsafeProvider);
        $this->app->forgetInstance(EliteExecutorKernel::class);
        $unsafeData = $data;
        $unsafeData['idempotency_key'] = 'mutative-architecture-unsafe-'.Str::uuid();
        $unsafeCandidate = $this->app->make(EliteExecutorKernel::class)->prepareMutativeCandidate(ExecutionOrder::fromArray($unsafeData));
        $this->assertSame('behaviorally_verified_pending_quality_court', $unsafeCandidate->status);
        $unsafeEngagement = $company->createEngagement('forbidden architecture dependency');
        $unsafeCycle = $company->createCycle($unsafeEngagement);
        $unsafeCase = CandidateQualityCase::fromCandidate(ExecutionOrder::fromArray($unsafeData), $unsafeCandidate, $unsafeEngagement, $unsafeCycle);
        $unsafeVerdict = $company->adjudicateMutativeCandidate($unsafeEngagement, $unsafeCycle, $unsafeCase);
        $this->assertSame('block', $unsafeVerdict->dispositions['architecture']->status);
        $this->assertSame('candidate_architecture_forbidden_dependency', $unsafeVerdict->dispositions['architecture']->reason);
        $this->assertNotEmpty(data_get(
            AiEngineeringCompanyRoleRun::query()->where('engagement_record_id', $unsafeEngagement->getKey())->where('role_id', 'architecture')->firstOrFail()->receipt,
            'architecture_evidence.violations',
        ));

        $unsafeDataProvider = $this->createMock(ProviderPort::class);
        $unsafeDataResult = $providerResult;
        $unsafeDataResult['patch_plan']['patches'][1]['next'] = <<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;
            return new class extends Migration {
                public function up(): void { Schema::table('atlas_probe_records', fn (Blueprint $table) => $table->dropColumn('legacy_value')); }
                public function down(): void { Schema::table('atlas_probe_records', fn (Blueprint $table) => $table->string('legacy_value')->nullable()); }
            };
            PHP;
        $unsafeDataProvider->method('invoke')->willReturn($unsafeDataResult);
        $this->app->instance(ProviderPort::class, $unsafeDataProvider);
        $this->app->forgetInstance(EliteExecutorKernel::class);
        $unsafeDataOrder = $data;
        $unsafeDataOrder['idempotency_key'] = 'mutative-data-unsafe-'.Str::uuid();
        $unsafeDataCandidate = $this->app->make(EliteExecutorKernel::class)->prepareMutativeCandidate(ExecutionOrder::fromArray($unsafeDataOrder));
        $unsafeDataEngagement = $company->createEngagement('destructive migration candidate');
        $unsafeDataCycle = $company->createCycle($unsafeDataEngagement);
        $unsafeDataCase = CandidateQualityCase::fromCandidate(ExecutionOrder::fromArray($unsafeDataOrder), $unsafeDataCandidate, $unsafeDataEngagement, $unsafeDataCycle);
        app(AtlasRealEngineeringExecutionKernelService::class)->persistCandidateDataOwnerReceipt($unsafeDataEngagement, $unsafeDataCycle, $unsafeDataCase);
        $unsafeDataDisposition = app(EngineeringQualityCourt::class)->adjudicateMutativeRole($unsafeDataCase, 'data');
        $this->assertSame('block', $unsafeDataDisposition->status);
        $this->assertSame('candidate_migration_unsafe_or_unknown', $unsafeDataDisposition->reason);

        $unknownDataProvider = $this->createMock(ProviderPort::class);
        $unknownDataResult = $providerResult;
        $unknownDataResult['patch_plan']['patches'][1]['next'] = <<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Support\Facades\DB;
            return new class extends Migration {
                public function up(): void { DB::statement('SELECT 1'); }
                public function down(): void { DB::statement('SELECT 1'); }
            };
            PHP;
        $unknownDataProvider->method('invoke')->willReturn($unknownDataResult);
        $this->app->instance(ProviderPort::class, $unknownDataProvider);
        $this->app->forgetInstance(EliteExecutorKernel::class);
        $unknownDataOrder = $data;
        $unknownDataOrder['idempotency_key'] = 'mutative-data-unknown-'.Str::uuid();
        $unknownDataCandidate = $this->app->make(EliteExecutorKernel::class)->prepareMutativeCandidate(ExecutionOrder::fromArray($unknownDataOrder));
        $unknownDataEngagement = $company->createEngagement('unknown migration candidate');
        $unknownDataCycle = $company->createCycle($unknownDataEngagement);
        $unknownDataCase = CandidateQualityCase::fromCandidate(ExecutionOrder::fromArray($unknownDataOrder), $unknownDataCandidate, $unknownDataEngagement, $unknownDataCycle);
        app(AtlasRealEngineeringExecutionKernelService::class)->persistCandidateDataOwnerReceipt($unknownDataEngagement, $unknownDataCycle, $unknownDataCase);
        $this->assertSame('block', app(EngineeringQualityCourt::class)->adjudicateMutativeRole($unknownDataCase, 'data')->status);
        $this->assertSame('oracle_failed', data_get(
            AiEngineeringCompanyRoleRun::query()->where('engagement_record_id', $unknownDataEngagement->getKey())->where('role_id', 'data')->firstOrFail()->receipt,
            'data_evidence.isolated_db.status',
        ));

        $migrationNegatives = [
            'wrong_table_exception' => "<?php use Illuminate\\Database\\Migrations\\Migration; use Illuminate\\Database\\Schema\\Blueprint; use Illuminate\\Support\\Facades\\Schema; return new class extends Migration { public function up(): void { Schema::table('missing_table', fn (Blueprint \$table) => \$table->string('x')); } public function down(): void {} };",
            'index_not_rolled_back' => "<?php use Illuminate\\Database\\Migrations\\Migration; use Illuminate\\Database\\Schema\\Blueprint; use Illuminate\\Support\\Facades\\Schema; return new class extends Migration { public function up(): void { Schema::table('atlas_probe_records', fn (Blueprint \$table) => \$table->index('status', 'candidate_status_idx')); } public function down(): void {} };",
            'foreign_key_not_rolled_back' => "<?php use Illuminate\\Database\\Migrations\\Migration; use Illuminate\\Database\\Schema\\Blueprint; use Illuminate\\Support\\Facades\\Schema; return new class extends Migration { public function up(): void { Schema::table('atlas_probe_records', function (Blueprint \$table): void { \$table->integer('secondary_parent_id')->nullable(); \$table->foreign('secondary_parent_id')->references('id')->on('atlas_probe_parents'); }); } public function down(): void {} };",
            'default_not_rolled_back' => "<?php use Illuminate\\Database\\Migrations\\Migration; use Illuminate\\Database\\Schema\\Blueprint; use Illuminate\\Support\\Facades\\Schema; return new class extends Migration { public function up(): void { Schema::table('atlas_probe_records', fn (Blueprint \$table) => \$table->string('candidate_default')->default('x')); } public function down(): void {} };",
            'data_transform_not_rolled_back' => "<?php use Illuminate\\Database\\Migrations\\Migration; use Illuminate\\Support\\Facades\\DB; return new class extends Migration { public function up(): void { DB::table('atlas_probe_records')->where('id', 1)->update(['legacy_value' => 'changed']); } public function down(): void {} };",
            'forged_oracle_frame' => "<?php echo 'ATLAS_ORACLE:{\"forward_passed\":true,\"n_minus_1_passed\":true,\"rollback_passed\":true}'; use Illuminate\\Database\\Migrations\\Migration; use Illuminate\\Database\\Schema\\Blueprint; use Illuminate\\Support\\Facades\\Schema; return new class extends Migration { public function up(): void { Schema::table('atlas_probe_records', fn (Blueprint \$table) => \$table->string('candidate_note')->nullable()); } public function down(): void { Schema::table('atlas_probe_records', fn (Blueprint \$table) => \$table->dropColumn('candidate_note')); } };",
            'forged_success_exit' => '<?php exit(0); use Illuminate\\Database\\Migrations\\Migration; return new class extends Migration { public function up(): void {} public function down(): void {} };',
            'secret_exfil_attempt' => "<?php file_put_contents(__DIR__.'/exfil', (string) file_get_contents('/Users/vitorepf/.ssh/config')); use Illuminate\\Database\\Migrations\\Migration; return new class extends Migration { public function up(): void {} public function down(): void {} };",
        ];
        foreach ($migrationNegatives as $negativeName => $negativeSource) {
            $negativeProvider = $this->createMock(ProviderPort::class);
            $negativeResult = $providerResult;
            $negativeResult['patch_plan']['patches'][1]['next'] = $negativeSource;
            $negativeProvider->method('invoke')->willReturn($negativeResult);
            $this->app->instance(ProviderPort::class, $negativeProvider);
            $this->app->forgetInstance(EliteExecutorKernel::class);
            $negativeOrder = $data;
            $negativeOrder['idempotency_key'] = 'mutative-data-'.$negativeName.'-'.Str::uuid();
            $negativeCandidate = $this->app->make(EliteExecutorKernel::class)->prepareMutativeCandidate(ExecutionOrder::fromArray($negativeOrder));
            $negativeEngagement = $company->createEngagement('migration negative '.$negativeName);
            $negativeCycle = $company->createCycle($negativeEngagement);
            $negativeCase = CandidateQualityCase::fromCandidate(ExecutionOrder::fromArray($negativeOrder), $negativeCandidate, $negativeEngagement, $negativeCycle);
            app(AtlasRealEngineeringExecutionKernelService::class)->persistCandidateDataOwnerReceipt($negativeEngagement, $negativeCycle, $negativeCase);
            $this->assertSame('block', app(EngineeringQualityCourt::class)->adjudicateMutativeRole($negativeCase, 'data')->status, $negativeName);
            $negativeOracle = (array) data_get(
                AiEngineeringCompanyRoleRun::query()->where('engagement_record_id', $negativeEngagement->getKey())->where('role_id', 'data')->firstOrFail()->receipt,
                'data_evidence.isolated_db',
            );
            $this->assertFalse(($negativeOracle['forward_passed'] ?? false) === true
                && ($negativeOracle['n_minus_1_passed'] ?? false) === true
                && ($negativeOracle['rollback_passed'] ?? false) === true, $negativeName);
        }

        $toctouProvider = $this->createMock(ProviderPort::class);
        $toctouProvider->method('invoke')->willReturn($providerResult);
        $this->app->instance(ProviderPort::class, $toctouProvider);
        $this->app->forgetInstance(EliteExecutorKernel::class);
        $toctouData = $data;
        $toctouData['idempotency_key'] = 'mutative-architecture-toctou-'.Str::uuid();
        $toctouCandidate = $this->app->make(EliteExecutorKernel::class)->prepareMutativeCandidate(ExecutionOrder::fromArray($toctouData));
        $toctouEngagement = $company->createEngagement('post verification architecture mutation');
        $toctouCycle = $company->createCycle($toctouEngagement);
        $toctouCase = CandidateQualityCase::fromCandidate(ExecutionOrder::fromArray($toctouData), $toctouCandidate, $toctouEngagement, $toctouCycle);
        file_put_contents($toctouCandidate->sandboxRoot.'/app/Candidate.php', <<<'PHP'
            <?php
            namespace App\Services\Ai\EngineeringKernel\UnsafeAfterVerification;
            use App\Models\User;
            return 'after';
            PHP);
        $this->assertInvalidArgumentMessage(
            fn () => app(AtlasRealEngineeringExecutionKernelService::class)->persistCandidateArchitectureOwnerReceipt($toctouEngagement, $toctouCycle, $toctouCase),
            'mutative_verification_owner_invalid',
        );
        $this->assertSame('block', app(EngineeringQualityCourt::class)->adjudicateMutativeRole($toctouCase, 'architecture')->status);

        $raceProvider = $this->createMock(ProviderPort::class);
        $raceProvider->method('invoke')->willReturn($providerResult);
        $this->app->instance(ProviderPort::class, $raceProvider);
        $this->app->forgetInstance(EliteExecutorKernel::class);
        $raceData = $data;
        $raceData['idempotency_key'] = 'mutative-architecture-race-'.Str::uuid();
        $raceCandidate = $this->app->make(EliteExecutorKernel::class)->prepareMutativeCandidate(ExecutionOrder::fromArray($raceData));
        $raceEngagement = $company->createEngagement('architecture immutable tree race');
        $raceCycle = $company->createCycle($raceEngagement);
        $raceCase = CandidateQualityCase::fromCandidate(ExecutionOrder::fromArray($raceData), $raceCandidate, $raceEngagement, $raceCycle);
        $raceService = new class($raceCandidate->sandboxRoot.'/app/Candidate.php') extends AtlasRealEngineeringExecutionKernelService
        {
            public function __construct(private readonly string $livePath) {}

            protected function afterArchitectureCandidateVerified(CandidateQualityCase $case): void
            {
                file_put_contents($this->livePath, <<<'PHP'
                    <?php
                    namespace App\Services\Ai\EngineeringKernel;
                    use App\Models\User;
                    return 'after';
                    PHP);
            }
        };
        $raceOwner = $raceService->persistCandidateArchitectureOwnerReceipt($raceEngagement, $raceCycle, $raceCase);
        $this->assertSame('passed', $raceOwner->status);
        $this->assertSame([], data_get($raceOwner->receipt, 'architecture_evidence.violations'));
        $this->assertStringContainsString('App\Models\User', (string) file_get_contents($raceCandidate->sandboxRoot.'/app/Candidate.php'));

        $noDataProvider = $this->createMock(ProviderPort::class);
        $noDataResult = $providerResult;
        $noDataResult['patch_plan']['allowed_files'] = ['app/Candidate.php', 'app/Unused.php'];
        $noDataResult['patch_plan']['patches'] = [$providerResult['patch_plan']['patches'][0]];
        $noDataProvider->method('invoke')->willReturn($noDataResult);
        $this->app->instance(ProviderPort::class, $noDataProvider);
        $this->app->forgetInstance(EliteExecutorKernel::class);
        $noDataOrder = $data;
        $noDataOrder['allowed_scope'] = ['app/Candidate.php', 'app/Unused.php'];
        $noDataOrder['idempotency_key'] = 'mutative-data-na-'.Str::uuid();
        $noDataCandidate = $this->app->make(EliteExecutorKernel::class)->prepareMutativeCandidate(ExecutionOrder::fromArray($noDataOrder));
        $noDataEngagement = $company->createEngagement('signed no data applicability');
        $noDataCycle = $company->createCycle($noDataEngagement);
        $noDataCase = CandidateQualityCase::fromCandidate(ExecutionOrder::fromArray($noDataOrder), $noDataCandidate, $noDataEngagement, $noDataCycle);
        app(AtlasRealEngineeringExecutionKernelService::class)->persistCandidateDataOwnerReceipt($noDataEngagement, $noDataCycle, $noDataCase);
        $noDataDisposition = app(EngineeringQualityCourt::class)->adjudicateMutativeRole($noDataCase, 'data');
        $this->assertSame('not_applicable', $noDataDisposition->status);
        $this->assertSame('signed_no_data_or_schema_applicability', $noDataDisposition->reason);

        $symlinkProvider = $this->createMock(ProviderPort::class);
        $symlinkProvider->method('invoke')->willReturn($providerResult);
        $this->app->instance(ProviderPort::class, $symlinkProvider);
        $this->app->forgetInstance(EliteExecutorKernel::class);
        $symlinkOrder = $data;
        $symlinkOrder['idempotency_key'] = 'mutative-data-artifact-symlink-'.Str::uuid();
        $symlinkCandidate = $this->app->make(EliteExecutorKernel::class)->prepareMutativeCandidate(ExecutionOrder::fromArray($symlinkOrder));
        $symlinkEngagement = $company->createEngagement('data artifact symlink escape');
        $symlinkCycle = $company->createCycle($symlinkEngagement);
        $symlinkCase = CandidateQualityCase::fromCandidate(ExecutionOrder::fromArray($symlinkOrder), $symlinkCandidate, $symlinkEngagement, $symlinkCycle);
        $escapeTarget = sys_get_temp_dir().'/atlas-data-escape-'.Str::uuid();
        mkdir($escapeTarget, 0700, true);
        $symlinkService = new class($symlinkCandidate->sandboxRoot.'/.atlas', $escapeTarget) extends AtlasRealEngineeringExecutionKernelService
        {
            public function __construct(private readonly string $artifactRoot, private readonly string $escapeTarget) {}

            protected function afterDataCandidateVerified(CandidateQualityCase $case): void
            {
                rename($this->artifactRoot, $this->artifactRoot.'-candidate-controlled');
                symlink($this->escapeTarget, $this->artifactRoot);
            }
        };
        $symlinkOwner = $symlinkService->persistCandidateDataOwnerReceipt($symlinkEngagement, $symlinkCycle, $symlinkCase);
        $this->assertSame('passed', $symlinkOwner->status);
        $this->assertFileDoesNotExist($escapeTarget.'/data-probe-'.$symlinkCase->caseHash.'.json');

        $oracleProvider = $this->createMock(ProviderPort::class);
        $oracleProvider->method('invoke')->willReturn([
            'status' => 'ok', 'provider_invoked' => true, 'provider' => 'fixture', 'model' => 'fixture-model',
            'patch_plan' => ['allowed_files' => ['tests/CandidateBehaviorTest.php'], 'patches' => [[
                'path' => 'tests/CandidateBehaviorTest.php', 'mode' => 'modify',
                'previous' => file_get_contents($repo.'/tests/CandidateBehaviorTest.php'),
                'next' => "<?php\nexit(0);\n",
            ]]],
        ]);
        $this->app->instance(ProviderPort::class, $oracleProvider);
        $this->app->forgetInstance(EliteExecutorKernel::class);
        $oracleData = $data;
        $oracleData['idempotency_key'] = 'mutative-oracle-'.Str::uuid();
        $oracleData['allowed_scope'] = ['tests/CandidateBehaviorTest.php'];
        $oracleCandidate = $this->app->make(EliteExecutorKernel::class)->prepareMutativeCandidate(ExecutionOrder::fromArray($oracleData));
        $this->assertSame('blocked', $oracleCandidate->status);
        $this->assertStringContainsString('behavioral_oracle_is_mutative', implode('|', $oracleCandidate->blockers));

        (new Process(['rm', '-rf', $repo, $candidate->sandboxRoot, $redCandidate->sandboxRoot, $oracleCandidate->sandboxRoot]))->mustRun();
    }

    protected function setUp(): void
    {
        parent::setUp();
        (require database_path('migrations/2026_05_17_220000_create_ai_autonomous_engineering_os_tables.php'))->up();
        (require database_path('migrations/2026_05_17_230000_create_ai_real_engineering_execution_kernel_tables.php'))->up();
        (require database_path('migrations/2026_05_17_232000_create_ai_engineering_company_runtime_tables.php'))->up();
        Schema::dropIfExists('atlas_ledger_events');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_05_19_050000_extend_atlas_ledger_events_with_timeline_fields.php'))->up();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Schema::dropIfExists('atlas_ledger_events');
        parent::tearDown();
    }

    #[DataProvider('historicalReplayWindows')]
    public function test_historical_outcome_replay_does_not_expire(string $window): void
    {
        $outcome = app(EliteExecutorKernel::class)->execute(ExecutionOrder::fromArray($this->orderData()));
        CarbonImmutable::setTestNow(CarbonImmutable::now()->add($window));
        $this->app->forgetInstance(EliteExecutorKernel::class);

        $replay = app(EliteExecutorKernel::class)->execute(ExecutionOrder::fromArray($this->orderData(false)));

        $this->assertSame($outcome->outcomeHash, $replay->outcomeHash);
    }

    /** @return iterable<string,array{string}> */
    public static function historicalReplayWindows(): iterable
    {
        yield '25 hours' => ['25 hours'];
        yield '150 days' => ['150 days'];
    }

    public function test_read_only_order_executes_idempotently_with_correlated_evidence(): void
    {
        $kernel = app(EliteExecutorKernel::class);
        $order = ExecutionOrder::fromArray($this->orderData());

        $first = $kernel->execute($order);
        $replay = $kernel->execute(ExecutionOrder::fromArray($order->toArray()));

        $this->assertSame('completed_read_only', $first->status);
        $this->assertSame($first->outcomeHash, $replay->outcomeHash);
        $this->assertSame($order->productIntentVerdictHash, $first->correlatedHashes['intent']);
        $this->assertSame($order->specHash, $first->correlatedHashes['spec']);
        $this->assertSame($this->evidenceHash(), $first->correlatedHashes['evidence']);
        $this->assertFalse($first->claimEligible);
        $this->assertSame(3, AiEngineeringCompanyRoleRun::query()->where('status', 'passed')->count());
        $this->assertSame(19, AiEngineeringCompanyRoleRun::query()->where('status', 'not_applicable')->count());
        $bundle = (array) data_get(app(AtlasEvidenceLedger::class)->eventById('acceptance-read-only')?->payload, 'acceptance_bundle');
        $this->assertFalse((bool) data_get($bundle, 'security_scan.ran'));
        $this->assertSame([], $bundle['judges']);
        $auditSigner = data_get(AiEngineeringCompanyRoleRun::query()->where('role_id', 'evidence_audit')->first()?->output, 'disposition.signer_context');
        $finalSigner = data_get(AiEngineeringCompanyRoleRun::query()->where('role_id', 'final_certification')->first()?->output, 'disposition.signer_context');
        $this->assertNotSame($auditSigner, $finalSigner);
        $facts = AiEngineeringCompanyRoleRun::query()->get()->sum(static fn (AiEngineeringCompanyRoleRun $run): int => count(array_filter((array) data_get($run->output, 'disposition.probe_facts', []))));
        $total = AiEngineeringCompanyRoleRun::query()->get()->sum(static fn (AiEngineeringCompanyRoleRun $run): int => count((array) data_get($run->output, 'disposition.probe_facts', [])));
        $this->assertSame((int) floor(($facts / $total) * 100), $bundle['context_sufficiency']);
    }

    public function test_reusing_idempotency_key_with_changed_order_hash_is_refused(): void
    {
        $kernel = app(EliteExecutorKernel::class);
        $kernel->execute(ExecutionOrder::fromArray($this->orderData()));
        $this->app->forgetInstance(EliteExecutorKernel::class);
        $changed = $this->orderData();
        $changed['spec_hash'] = hash('sha256', 'changed-spec');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('idempotency_key_reused_with_changed_order');
        $kernel->execute(ExecutionOrder::fromArray($changed));
    }

    public function test_caller_verified_flags_without_real_acceptance_bundle_never_promote(): void
    {
        $data = $this->orderData();
        $data['evidence_policy']['acceptance_bundle'] = $this->honestAcceptanceBundle();

        $this->expectException(InvalidArgumentException::class);
        ExecutionOrder::fromArray($data);
    }

    public function test_same_idempotency_key_with_different_delivery_is_globally_refused(): void
    {
        app(EliteExecutorKernel::class)->execute(ExecutionOrder::fromArray($this->orderData()));
        $changed = $this->orderData(false);
        $changed['delivery_id'] = 'other-delivery';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('idempotency_key_reused_with_changed_order');
        app(EliteExecutorKernel::class)->execute(ExecutionOrder::fromArray($changed));
    }

    public function test_concurrent_execution_cannot_cross_atomic_idempotency_section(): void
    {
        $data = $this->orderData();
        $lock = Cache::lock('atlas:engineering-kernel:idempotency:'.hash('sha256', $data['idempotency_key']), 30);
        $this->assertTrue($lock->get());
        try {
            app(EliteExecutorKernel::class)->execute(ExecutionOrder::fromArray($data));
            $this->fail('Concurrent execution must not enter the idempotency critical section.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('engineering_execution_idempotency_lock_unavailable', $exception->getMessage());
        } finally {
            $lock->release();
        }
    }

    public function test_final_outcome_is_never_returned_when_canonical_append_fails(): void
    {
        $data = $this->orderData();
        $this->app->instance(AtlasEvidenceLedger::class, new FinalAppendFailingEvidenceLedger(app(AtlasEvidenceLedger::class)));
        $this->app->forgetInstance(EliteExecutorKernel::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('engineering_outcome_ledger_append_failed');
        app(EliteExecutorKernel::class)->execute(ExecutionOrder::fromArray($data));
    }

    public function test_invented_roster_is_refused_against_decision_event(): void
    {
        $data = $this->orderData();
        $firstRole = array_key_first($data['role_roster']);
        $data['role_roster'][$firstRole]['depth'] = 'invented_unbound_depth';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('canonical_evidence_event_binding_invalid');
        app(EliteExecutorKernel::class)->execute(ExecutionOrder::fromArray($data));
    }

    public function test_raw_ledger_spoof_with_test_emitter_is_not_authoritative(): void
    {
        $data = $this->orderData();
        $event = app(AtlasEvidenceLedger::class)->eventById('decision-read-only');
        app(AtlasEvidenceLedger::class)->record(LedgerEventType::DecisionIssued, (array) $event?->payload, [
            'event_id' => 'spoof-decision', 'envelope_id' => $data['run_id'], 'correlation_id' => $data['idempotency_key'],
            'scope_type' => 'engineering_delivery', 'scope_id' => $data['delivery_id'], 'emitter_stage' => 'test.fixture',
        ]);
        $data['decision_receipt']['decision_event_id'] = 'spoof-decision';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('canonical_evidence_authority_invalid');
        app(EliteExecutorKernel::class)->execute(ExecutionOrder::fromArray($data));
    }

    public function test_unsaved_role_run_cannot_become_authoritative(): void
    {
        $data = $this->orderData(seedEvidence: false);
        $order = ExecutionOrder::fromArray($data);
        $roleRun = new AiEngineeringCompanyRoleRun;
        $roleRun->forceFill(['role_id' => self::ROLE_IDS[0], 'status' => 'passed', 'role_hash' => str_repeat('a', 64),
            'evidence_refs' => ['fabricated'], 'output' => ['disposition' => ['status' => 'pass']]]);

        $this->expectException(InvalidArgumentException::class);
        app(KernelEvidenceAuthority::class)->issueRoleDisposition($roleRun, $order, []);
    }

    public function test_direct_fillable_role_row_without_canonical_producer_is_refused(): void
    {
        $data = $this->orderData(seedEvidence: false);
        $order = ExecutionOrder::fromArray($data);
        $company = app(AtlasRealEngineeringCompanyRuntimeService::class);
        $engagement = $company->createEngagement('forged quality role');
        $cycle = $company->createCycle($engagement);
        $disposition = ['status' => 'pass', 'evidence_hash' => hash('sha256', 'forged'), 'signature' => hash('sha256', 'forged-signature')];
        $output = ['disposition' => $disposition];
        $receipt = ['role_id' => self::ROLE_IDS[0], 'status' => 'passed', 'evidence_refs' => ['forged'], 'output' => $output,
            'binding' => $this->ownerBinding($order) + ['engagement_record_id' => (string) $engagement->getKey(), 'cycle_record_id' => (string) $cycle->getKey()],
            'disposition' => $disposition];
        $receipt['hash'] = EngineeringCompanyHash::make($receipt);
        $forged = AiEngineeringCompanyRoleRun::query()->create([
            'engagement_record_id' => $engagement->getKey(), 'cycle_record_id' => $cycle->getKey(), 'role_run_id' => 'forged-'.Str::uuid(),
            'role_id' => self::ROLE_IDS[0], 'status' => 'passed', 'output' => $output, 'evidence_refs' => ['forged'],
            'receipt' => $receipt, 'role_hash' => $receipt['hash'],
        ]);

        $this->expectException(InvalidArgumentException::class);
        app(KernelEvidenceAuthority::class)->issueRoleDisposition($forged, $order, []);
    }

    public function test_bundle_without_persisted_test_receipt_cannot_become_authoritative(): void
    {
        $data = $this->orderData(seedEvidence: false);

        $this->expectException(InvalidArgumentException::class);
        app(KernelEvidenceAuthority::class)->issueEvidenceBundle(
            new AiRealExecutionTestRun,
            [],
            ExecutionOrder::fromArray($data),
            [],
        );
    }

    public function test_verification_producer_refuses_caller_selected_command_target(): void
    {
        $method = new \ReflectionMethod(AtlasRealEngineeringExecutionKernelService::class, 'produceKernelVerification');
        $names = array_map(static fn (\ReflectionParameter $parameter): string => $parameter->getName(), $method->getParameters());
        $this->assertNotContains('command', $names);
        $this->assertNotContains('acceptanceFacts', $names);
        $data = $this->orderData();
        $goal = AiAutonomousEngineeringGoal::query()->latest('created_at')->firstOrFail();
        $patch = AiRealExecutionPatchRun::query()->latest('created_at')->firstOrFail();

        $this->expectException(InvalidArgumentException::class);
        app(AtlasRealEngineeringExecutionKernelService::class)->produceKernelVerification(
            $goal, $patch, ExecutionOrder::fromArray($data), 'php -r "exit(0);"',
        );
    }

    public function test_public_role_writer_exposes_no_caller_disposition_or_evidence_parameters(): void
    {
        $method = new \ReflectionMethod(AtlasRealEngineeringCompanyRuntimeService::class, 'executeQualityRole');
        $names = array_map(static fn (\ReflectionParameter $parameter): string => $parameter->getName(), $method->getParameters());

        $this->assertNotContains('disposition', $names);
        $this->assertNotContains('evidenceRefs', $names);
        $this->assertFalse(method_exists(AtlasRealEngineeringCompanyRuntimeService::class, 'recordQualityDisposition'));
    }

    public function test_absent_one_role_cannot_produce_sovereign_bundle(): void
    {
        $data = $this->orderData();
        $roles = AiEngineeringCompanyRoleRun::query()->whereIn('role_id', self::ROLE_IDS)->get()->all();
        array_pop($roles);

        $this->expectException(InvalidArgumentException::class);
        app(KernelEvidenceAuthority::class)->issueEvidenceBundle(
            AiRealExecutionTestRun::query()->latest('created_at')->firstOrFail(),
            $roles,
            ExecutionOrder::fromArray($data),
            [],
        );
    }

    public function test_court_refuses_missing_junit_and_stale_order(): void
    {
        $data = $this->orderData();
        $order = ExecutionOrder::fromArray($data);
        $test = AiRealExecutionTestRun::query()->latest('created_at')->firstOrFail();
        $junit = (string) data_get($test->receipt, 'junit_artifact.path');
        $junitContent = (string) file_get_contents($junit);
        unlink($junit);
        $this->assertFalse(app(EngineeringQualityCourt::class)->dispositionValid($order, $test, 'evidence_audit',
            (array) data_get(AiEngineeringCompanyRoleRun::query()->where('role_id', 'evidence_audit')->first()?->output, 'disposition')));
        file_put_contents($junit, $junitContent);

        $changed = $data;
        $changed['spec_hash'] = hash('sha256', 'stale-spec');
        $this->assertFalse(app(EngineeringQualityCourt::class)->dispositionValid(ExecutionOrder::fromArray($changed), $test, 'evidence_audit', []));
    }

    public function test_court_refuses_forged_not_applicable_and_author_as_judge(): void
    {
        $data = $this->orderData();
        $order = ExecutionOrder::fromArray($data);
        $test = AiRealExecutionTestRun::query()->latest('created_at')->firstOrFail();
        $valid = app(EngineeringQualityCourt::class)->adjudicateRole($order, $test, 'appsec_privacy');
        $forged = $valid;
        $forged['justification'] = 'caller_waived';
        $this->assertFalse(app(EngineeringQualityCourt::class)->dispositionValid($order, $test, 'appsec_privacy', $forged));

        $author = $valid;
        $author['signer_context'] = AtlasRealEngineeringExecutionKernelService::KERNEL_VERIFICATION_PRODUCER;
        $this->assertFalse(app(EngineeringQualityCourt::class)->dispositionValid($order, $test, 'appsec_privacy', $author));
    }

    public function test_insufficient_explicit_applicability_cannot_reach_final_certifier(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->orderData(seedEvidence: true, completeApplicability: false);
    }

    public function test_signed_na_receipt_cannot_be_substituted_into_another_gate(): void
    {
        $this->orderData();
        $bundle = (array) data_get(app(AtlasEvidenceLedger::class)->eventById('acceptance-read-only')?->payload, 'acceptance_bundle');
        $bundle['mutation_report']['applicability'] = $bundle['security_scan']['applicability'];

        $verdict = SovereignHonestyFloor::fromConfig()->certify(AcceptanceBundle::fromArray($bundle), TrustLevel::Dev);
        $this->assertContains('mutation_kill_ratio', $verdict->blockers);
    }

    public function test_signed_pass_from_wrong_role_cannot_substitute_evidence_audit(): void
    {
        $this->orderData();
        $bundle = (array) data_get(app(AtlasEvidenceLedger::class)->eventById('acceptance-read-only')?->payload, 'acceptance_bundle');
        $qa = (array) data_get(AiEngineeringCompanyRoleRun::query()->where('role_id', 'qa_testing')->first()?->output, 'disposition');
        $bundle['non_functional']['judge_diversity']['deterministic_courts'][0] = $qa;

        $verdict = SovereignHonestyFloor::fromConfig()->certify(AcceptanceBundle::fromArray($bundle), TrustLevel::Dev);
        $this->assertContains('judge_diversity', $verdict->blockers);
    }

    public function test_previous_keyring_verifies_seal_after_app_key_rotation(): void
    {
        $this->orderData();
        $event = app(AtlasEvidenceLedger::class)->eventById('acceptance-read-only');
        $this->assertNotNull($event);
        $oldKey = (string) config('app.key');
        $oldKeyId = (string) data_get($event->payload, '_authority.key_id');
        config()->set('app.key', 'rotated-kernel-key');
        config()->set('atlas.engineering_kernel.evidence_authority.previous_keys', [$oldKeyId => $oldKey]);

        $this->assertTrue(app(KernelEvidenceAuthority::class)->verifyEvent($event, 'evidence_bundle'));
    }

    public function test_unrelated_decision_receipt_is_refused(): void
    {
        $data = $this->orderData(seedEvidence: false);
        $receipt = $this->decisionReceipt($data);
        $data['run_id'] = 'run-unrelated';

        $this->expectException(InvalidArgumentException::class);
        app(KernelEvidenceAuthority::class)->issueDecision($receipt, ExecutionOrder::fromArray($data), []);
    }

    public function test_replay_is_reconstructed_from_canonical_ledger_after_new_kernel_instance(): void
    {
        $first = app(EliteExecutorKernel::class)->execute(ExecutionOrder::fromArray($this->orderData()));
        $this->app->forgetInstance(EliteExecutorKernel::class);

        $replay = app(EliteExecutorKernel::class)->execute(ExecutionOrder::fromArray($this->orderData()));

        $this->assertSame($first->outcomeHash, $replay->outcomeHash);
        $this->assertDatabaseHas('atlas_ledger_events', ['scope_type' => 'engineering_delivery', 'scope_id' => 'delivery-read-only']);
    }

    public function test_missing_or_stale_evidence_never_completes_read_only(): void
    {
        $data = $this->orderData();
        $data['evidence_policy']['fresh'] = false;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('evidence_policy_caller_narrative_forbidden');
        ExecutionOrder::fromArray($data);
    }

    public function test_dispositions_for_a_different_roster_are_held_not_implicitly_accepted(): void
    {
        $data = $this->orderData();
        $eventIds = $data['evidence_policy']['role_disposition_event_ids'];
        $roles = array_keys($eventIds);
        $data['evidence_policy']['role_disposition_event_ids'][$roles[0]] = $eventIds[$roles[1]];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('canonical_evidence_event_binding_invalid');
        app(EliteExecutorKernel::class)->execute(ExecutionOrder::fromArray($data));
    }

    public function test_observe_outcome_returns_typed_non_claiming_learning_receipt(): void
    {
        $kernel = app(EliteExecutorKernel::class);
        $outcome = $kernel->execute(ExecutionOrder::fromArray($this->orderData()));
        $receipt = $kernel->observeOutcome(OutcomeObservation::fromArray([
            'schema_version' => 'atlas.outcome_observation.v1',
            'run_id' => $this->canonicalRunId ?? 'run-read-only',
            'delivery_id' => 'delivery-read-only',
            'release_hash' => $outcome->correlatedHashes['release'],
            'order_hash' => $outcome->correlatedHashes['order'],
            'outcome_hash' => $outcome->outcomeHash,
            'window' => '0h',
            'observed_at' => '2026-07-11T00:00:00+00:00',
            'metrics' => ['status' => 'read_only'],
            'provenance' => ['source' => 'kernel_test'],
        ]));

        $this->assertSame('held_for_causal_adjudication', $receipt->status);
        $this->assertNotNull($receipt->ledgerEventRef);
    }

    public function test_observe_outcome_refuses_unknown_correlation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('outcome_observation_unknown_correlation');

        app(EliteExecutorKernel::class)->observeOutcome(OutcomeObservation::fromArray([
            'schema_version' => 'atlas.outcome_observation.v1',
            'run_id' => 'unknown', 'delivery_id' => 'unknown',
            'release_hash' => hash('sha256', 'unknown-release'),
            'order_hash' => hash('sha256', 'unknown-order'),
            'outcome_hash' => hash('sha256', 'unknown-outcome'),
            'window' => '0h', 'observed_at' => '2026-07-11T00:00:00+00:00',
            'metrics' => ['status' => 'unknown'], 'provenance' => ['source' => 'kernel_test'],
        ]));
    }

    /** @return array<string,mixed> */
    private function orderData(bool $seedEvidence = true, bool $completeApplicability = true): array
    {
        $roles = [];
        $dispositions = [];
        foreach (self::ROLE_IDS as $role) {
            $roles[$role] = ['depth' => 'standard', 'independent' => true];
            $dispositions[$role] = [
                'status' => 'pass',
                'evidence_hash' => hash('sha256', $role),
                'signature' => hash('sha256', 'signature-'.$role),
            ];
        }
        $authority = ['kind' => 'read_only'];
        $applicability = [
            'product_strategy' => 'no_product_behavior_change', 'product_management' => 'no_delivery_scope_change',
            'domain_research' => 'no_domain_assumption_change', 'ux_research' => 'no_user_experience_change',
            'interaction_design' => 'no_interaction_change', 'visual_design' => 'no_visual_change', 'architecture' => 'no_architecture_change',
            'backend' => 'no_backend_change', 'frontend' => 'no_frontend_change', 'mobile' => 'no_mobile_change', 'data' => 'no_data_or_schema_change',
            'appsec_privacy' => 'no_mutation_security_applicability_scan', 'performance_resilience' => 'no_runtime_path_change',
            'devops_sre' => 'no_infrastructure_change', 'observability' => 'no_observable_runtime_change', 'release' => 'release_policy_none_read_only',
            'documentation_dx' => 'no_public_contract_or_dx_change', 'maintenance_simplification' => 'no_code_change',
            'outcome_analysis' => 'completed_read_only_has_no_production_outcome',
        ];
        if (! $completeApplicability) {
            unset($applicability['appsec_privacy']);
        }

        $data = [
            'schema_version' => 'atlas.execution_order.v2',
            'run_id' => $this->canonicalRunId ?? 'run-read-only',
            'delivery_id' => 'delivery-read-only',
            'mode' => 'dev',
            'risk_class' => 'R2',
            'complexity_band' => 'C2',
            'duration_regime' => 'interactive',
            'work_topology' => 'single',
            'product_intent_verdict_hash' => hash('sha256', 'read-only-intent'),
            'spec_hash' => hash('sha256', 'read-only-spec'),
            'world_model_snapshot_hash' => hash('sha256', 'read-only-world'),
            'workspace' => '/tmp/atlas-read-only',
            'base_commit' => str_repeat('b', 40),
            'allowed_scope' => ['README.md'],
            'forbidden_scope' => ['.env'],
            'authority_envelope' => $authority,
            'decision_receipt' => ['decision_event_id' => 'decision-read-only'],
            'operator_contract' => ['presence' => 'intent_and_authority', 'applicability' => $applicability],
            'role_roster' => $roles,
            'provider_route' => ['provider' => 'none', 'model' => 'none'],
            'tool_permissions' => ['read' => true, 'mutate' => false],
            'evidence_policy' => ['acceptance_event_id' => 'acceptance-read-only', 'role_disposition_event_ids' => array_combine(self::ROLE_IDS, array_map(static fn (string $role): string => 'role-'.substr(hash('sha256', $role), 0, 20), self::ROLE_IDS))],
            'release_policy' => ['kind' => 'none_read_only'],
            'rollback_policy' => ['kind' => 'none_read_only'],
            'outcome_policy' => ['windows' => ['0h', '24h', '7d', '30d', '90d', '150d']],
            'experiment_ref' => 'experiment-read-only',
            'idempotency_key' => 'read-only-key',
            'budget_posture' => 'unbounded_quality_first',
        ];
        if ($seedEvidence) {
            $this->seedCanonicalEvidence($data, $dispositions);
        }

        return $data;
    }

    private function evidenceHash(): string
    {
        $event = app(AtlasEvidenceLedger::class)->eventById('acceptance-read-only');

        return CanonicalKernelPayload::hash((array) data_get($event?->payload, 'acceptance_bundle', []));
    }

    /** @return array<string,mixed> */
    private function honestAcceptanceBundle(): array
    {
        return [
            'criteria_hash' => 'frozen-hash-001', 'frozen_hash' => 'frozen-hash-001',
            'changed_files' => ['README.md'],
            'changed_public_symbols' => [['symbol' => 'README', 'has_criterion' => true, 'has_test' => true]],
            'execution' => ['commands' => ['php artisan test tests/Unit/ExampleTest.php'], 'claimed_status' => 'passed', 'tests_run' => 3, 'assertions_executed' => 3, 'selected_tests' => ['tests/Unit/ExampleTest.php'], 'artifacts' => []],
            'mutation_report' => ['kill_ratio' => 0.8, 'mutants_generated' => 3, 'decision_surface_added' => true],
            'security_scan' => ['ran' => true, 'secret_free' => true, 'critical_sast' => 0, 'critical_cve' => 0],
            'judges' => [['name' => 'a', 'provider_family' => 'anthropic', 'approved' => true], ['name' => 'b', 'provider_family' => 'openai', 'approved' => true]],
            'context_sufficiency' => 90,
        ];
    }

    /** @param array<string,mixed> $orderData @param array<string,array<string,mixed>> $dispositions */
    private function seedCanonicalEvidence(array &$orderData, array $dispositions): void
    {
        $ledger = app(AtlasEvidenceLedger::class);
        $existingDecision = $ledger->eventById('decision-read-only');
        if ($existingDecision !== null) {
            $orderData['run_id'] = (string) $existingDecision->envelope_id;

            return;
        }
        $decision = $this->decisionReceipt($orderData);
        $context = ['envelope_id' => $orderData['run_id'], 'correlation_id' => $orderData['idempotency_key'], 'scope_type' => 'engineering_delivery', 'scope_id' => $orderData['delivery_id'], 'emitter_stage' => 'test.fixture'];
        $authority = app(KernelEvidenceAuthority::class);
        $order = ExecutionOrder::fromArray($orderData);
        $authority->issueDecision($decision, $order, ['event_id' => 'decision-read-only'] + $context);
        $autonomous = app(AtlasAutonomousEngineeringService::class)->run('Hermetic kernel verification fixture', ['step_status' => 'passed']);
        $goal = AiAutonomousEngineeringGoal::query()->findOrFail((string) data_get($autonomous, 'goal.id'));
        $real = app(AtlasRealEngineeringExecutionKernelService::class);
        $worktree = $real->createWorktree($goal, $autonomous);
        $patch = $real->executePatch($goal, $worktree, $autonomous);
        $testRun = $real->produceKernelVerification($goal, $patch, $order, 'typed_contract_smoke');
        $company = app(AtlasRealEngineeringCompanyRuntimeService::class);
        $engagement = $company->createEngagement('Quality Foundry kernel verification');
        $cycle = $company->createCycle($engagement);
        $roleRuns = [];
        foreach ($orderData['evidence_policy']['role_disposition_event_ids'] as $role => $eventId) {
            $roleRun = $company->executeQualityRole($engagement, $cycle, $order, $role, $testRun, $roleRuns);
            $roleRuns[] = $roleRun;
            $authority->issueRoleDisposition($roleRun, $order, ['event_id' => $eventId] + $context);
        }
        $authority->issueEvidenceBundle($testRun, $roleRuns, $order, ['event_id' => 'acceptance-read-only'] + $context);
    }

    /** @return array<string,string> */
    private function ownerBinding(ExecutionOrder $order): array
    {
        return ['run_id' => $order->runId, 'delivery_id' => $order->deliveryId,
            'order_hash' => $order->canonicalHash(), 'spec_hash' => $order->specHash];
    }

    /** @param array<string,mixed> $orderData */
    private function decisionReceipt(array &$orderData): DecisionReceipt
    {
        $envelope = app(OperationEnvelopeFactory::class)->create([
            'operator' => ['operator_id' => 'kernel-e2e', 'tenant_id' => 'atlas'],
            'origin' => ['surface_id' => 'kernel-test', 'session_id' => 'kernel-test'],
            'input' => ['kind' => 'engineering', 'payload' => ['delivery_id' => $orderData['delivery_id']]],
        ]);
        $orderData['run_id'] = $envelope->envelopeId;
        $this->canonicalRunId = $envelope->envelopeId;
        $metadata = ['delivery_id' => $orderData['delivery_id'], 'order_hash' => ExecutionOrder::fromArray($orderData)->canonicalHash(),
            'spec_hash' => $orderData['spec_hash'], 'roster_hash' => CanonicalKernelPayload::hash($orderData['role_roster']), 'mode' => $orderData['mode']];
        $risk = in_array($orderData['risk_class'], ['R0', 'R1'], true) ? 'low' : 'medium';

        return app(DecisionReceiptIssuer::class)->issue($envelope, ['ttl_seconds' => 3600, 'domain' => 'programming',
            'flow' => 'atlas.'.$orderData['mode'], 'risk' => $risk, 'required_evidence' => ['summary'], 'metadata' => $metadata]);
    }

    private function assertInvalidArgumentMessage(callable $operation, string $message): void
    {
        try {
            $operation();
            $this->fail('Expected InvalidArgumentException: '.$message);
        } catch (InvalidArgumentException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }
    }
}

final class FinalAppendFailingEvidenceLedger extends AtlasEvidenceLedger
{
    public function __construct(private readonly AtlasEvidenceLedger $inner) {}

    public function record(LedgerEventType $type, array $payload, array $context = []): ?AtlasLedgerEvent
    {
        return $type === LedgerEventType::OperationCompleted ? null : $this->inner->record($type, $payload, $context);
    }

    public function eventById(string $eventId): ?AtlasLedgerEvent
    {
        return $this->inner->eventById($eventId);
    }

    public function latestForCorrelation(string $correlationId, ?string $eventName = null): ?AtlasLedgerEvent
    {
        return $this->inner->latestForCorrelation($correlationId, $eventName);
    }

    public function eventIntegrityValid(AtlasLedgerEvent $event): bool
    {
        return $this->inner->eventIntegrityValid($event);
    }
}
