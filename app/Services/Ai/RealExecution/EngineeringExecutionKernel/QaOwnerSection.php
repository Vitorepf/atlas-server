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

class QaOwnerSection
{
    public function __construct(private readonly KernelReceiptSupport $support)
    {
    }

    public function persistCandidateQaOwnerReceipt(AiEngineeringCompanyEngagement $engagement, AiEngineeringCompanyCycle $cycle, CandidateQualityCase $case): AiEngineeringCompanyRoleRun
    {
        if (! $engagement->exists || ! $cycle->exists || $cycle->engagement_record_id !== $engagement->getKey()
            || (string) $engagement->getKey() !== $case->engagementRecordId || (string) $cycle->getKey() !== $case->cycleRecordId
            || ! $this->candidateQaArtifactsValid($case)) {
            throw new \InvalidArgumentException('candidate_qa_owner_evidence_invalid');
        }
        $roleRunId = 'aereqa_'.substr(RealExecutionHash::make([$case->caseHash, 'qa_testing']), 0, 24);
        if (AiEngineeringCompanyRoleRun::query()->where('role_run_id', $roleRunId)->exists()) {
            throw new \InvalidArgumentException('candidate_qa_owner_duplicate');
        }
        $issued = CarbonImmutable::now()->startOfSecond();
        $expires = $issued->addHour();
        $disposition = $this->candidateQaDisposition($case);
        $qaEvidence = $this->candidateQaEvidence($case);
        $evidenceRefs = [
            'candidate:'.$case->candidate->candidateHash,
            'verification:'.$case->verification->receiptHash,
            'mechanical_junit:'.(string) data_get($qaEvidence, 'mechanical_junit.sha256'),
            'behavioral_junit:'.(string) data_get($qaEvidence, 'behavioral_junit.sha256'),
        ];
        $typed = RoleEvidenceReceipt::issue(
            $case, $disposition, AtlasRealEngineeringExecutionKernelService::CANDIDATE_QA_OWNER_DOMAIN, AtlasRealEngineeringExecutionKernelService::CANDIDATE_QA_OWNER_VERSION,
            $issued->toAtomString(), $expires->toAtomString(), $evidenceRefs,
        );
        $output = ['disposition' => $disposition->toArray(), 'role_evidence_receipt' => $typed->toArray()];
        $binding = [
            'run_id' => $case->order->runId, 'delivery_id' => $case->order->deliveryId,
            'order_hash' => $case->order->canonicalHash(), 'spec_hash' => $case->order->specHash,
            'case_hash' => $case->caseHash, 'candidate_hash' => $case->candidate->candidateHash,
            'base_commit' => $case->candidate->baseCommit, 'diff_hash' => $case->candidate->diffHash,
            'tree_hash' => $case->candidate->treeHash, 'files' => $case->candidate->files,
            'engagement_record_id' => (string) $engagement->getKey(), 'cycle_record_id' => (string) $cycle->getKey(),
        ];
        $receipt = [
            'schema_version' => AtlasRealEngineeringCompanyRuntimeService::ROLE_SCHEMA,
            'purpose' => 'candidate_qa_owner_evidence', 'owner_domain' => AtlasRealEngineeringExecutionKernelService::CANDIDATE_QA_OWNER_DOMAIN,
            'owner_version' => AtlasRealEngineeringExecutionKernelService::CANDIDATE_QA_OWNER_VERSION, 'issued_at' => $issued->toAtomString(),
            'expires_at' => $expires->toAtomString(), 'role_run_id' => $roleRunId, 'role_id' => 'qa_testing',
            'status' => 'passed', 'output' => $output, 'evidence_refs' => $evidenceRefs,
            'binding' => $binding, 'disposition' => $disposition->toArray(), 'qa_evidence' => $qaEvidence,
        ];
        $receipt['producer'] = $this->qaOwnerProducerSeal($receipt);
        $receipt['hash'] = EngineeringCompanyHash::make($receipt);

        return AiEngineeringCompanyRoleRun::query()->create([
            'engagement_record_id' => $engagement->getKey(), 'cycle_record_id' => $cycle->getKey(),
            'role_run_id' => $roleRunId, 'role_id' => 'qa_testing', 'status' => 'passed',
            'responsibilities' => [], 'output' => $output, 'evidence_refs' => $evidenceRefs,
            'receipt' => $receipt, 'role_hash' => $receipt['hash'],
        ]);
    }
    public function candidateQaOwnerReceiptValid(AiEngineeringCompanyRoleRun $row, CandidateQualityCase $case): bool
    {
        $persisted = $row->exists ? AiEngineeringCompanyRoleRun::query()->find($row->getKey()) : null;
        $receipt = $persisted?->getAttribute('receipt');
        $output = $persisted?->getAttribute('output');
        if (! $persisted instanceof AiEngineeringCompanyRoleRun || ! is_array($receipt) || ! is_array($output)
            || ! $this->candidateQaArtifactsValid($case)) {
            return false;
        }
        try {
            $issued = CarbonImmutable::createFromFormat(DATE_ATOM, (string) ($receipt['issued_at'] ?? ''));
            $expires = CarbonImmutable::createFromFormat(DATE_ATOM, (string) ($receipt['expires_at'] ?? ''));
        } catch (\Throwable) {
            return false;
        }
        $expectedDisposition = $this->candidateQaDisposition($case);
        $expectedEvidence = $this->candidateQaEvidence($case);
        $evidenceRefs = [
            'candidate:'.$case->candidate->candidateHash, 'verification:'.$case->verification->receiptHash,
            'mechanical_junit:'.(string) data_get($expectedEvidence, 'mechanical_junit.sha256'),
            'behavioral_junit:'.(string) data_get($expectedEvidence, 'behavioral_junit.sha256'),
        ];
        $expectedTyped = RoleEvidenceReceipt::issue(
            $case, $expectedDisposition, AtlasRealEngineeringExecutionKernelService::CANDIDATE_QA_OWNER_DOMAIN, AtlasRealEngineeringExecutionKernelService::CANDIDATE_QA_OWNER_VERSION,
            (string) $receipt['issued_at'], (string) $receipt['expires_at'], $evidenceRefs,
        )->toArray();
        $expectedBinding = [
            'run_id' => $case->order->runId, 'delivery_id' => $case->order->deliveryId,
            'order_hash' => $case->order->canonicalHash(), 'spec_hash' => $case->order->specHash,
            'case_hash' => $case->caseHash, 'candidate_hash' => $case->candidate->candidateHash,
            'base_commit' => $case->candidate->baseCommit, 'diff_hash' => $case->candidate->diffHash,
            'tree_hash' => $case->candidate->treeHash, 'files' => $case->candidate->files,
            'engagement_record_id' => $case->engagementRecordId,
            'cycle_record_id' => $case->cycleRecordId,
        ];
        $unsigned = array_diff_key($receipt, ['hash' => true]);
        $producer = $unsigned['producer'] ?? null;
        $withoutProducer = array_diff_key($unsigned, ['producer' => true]);

        return $persisted->role_id === 'qa_testing' && $persisted->status === 'passed'
            && ($receipt['purpose'] ?? null) === 'candidate_qa_owner_evidence'
            && ($receipt['owner_domain'] ?? null) === AtlasRealEngineeringExecutionKernelService::CANDIDATE_QA_OWNER_DOMAIN
            && ($receipt['owner_version'] ?? null) === AtlasRealEngineeringExecutionKernelService::CANDIDATE_QA_OWNER_VERSION
            && $issued !== null && $expires !== null && ! CarbonImmutable::now()->lt($issued) && CarbonImmutable::now()->lt($expires)
            && ($receipt['binding'] ?? null) === $expectedBinding && ($receipt['qa_evidence'] ?? null) === $expectedEvidence
            && ($receipt['evidence_refs'] ?? null) === $evidenceRefs && ($receipt['output'] ?? null) === $output
            && ($output['disposition'] ?? null) === $expectedDisposition->toArray()
            && ($output['role_evidence_receipt'] ?? null) === $expectedTyped
            && hash_equals((string) $persisted->role_hash, (string) ($receipt['hash'] ?? ''))
            && hash_equals((string) ($receipt['hash'] ?? ''), EngineeringCompanyHash::make($unsigned))
            && $this->qaOwnerProducerSealValid($withoutProducer, $producer);
    }
    public function candidateQaDisposition(CandidateQualityCase $case): RoleDisposition
    {
        $payload = ['purpose' => 'candidate_qa_owner_disposition', 'case_hash' => $case->caseHash,
            'order_hash' => $case->order->canonicalHash(), 'spec_hash' => $case->order->specHash,
            'candidate_hash' => $case->candidate->candidateHash, 'base_commit' => $case->candidate->baseCommit,
            'diff_hash' => $case->candidate->diffHash, 'tree_hash' => $case->candidate->treeHash,
            'files' => $case->candidate->files, 'verification_hash' => $case->verification->receiptHash,
            'signer_context' => AtlasRealEngineeringExecutionKernelService::CANDIDATE_QA_OWNER_DOMAIN];

        return RoleDisposition::qaCandidateVerified($case, AtlasRealEngineeringExecutionKernelService::CANDIDATE_QA_OWNER_DOMAIN, $this->qaOwnerSignature($payload));
    }
    /** @return array<string,mixed> */
    public function candidateQaEvidence(CandidateQualityCase $case): array
    {
        $verification = (array) $this->support->verifiedMutativeVerification($case->verification, $case->order)->receipt;

        return [
            'case_hash' => $case->caseHash, 'order_hash' => $case->order->canonicalHash(),
            'spec_hash' => $case->order->specHash, 'candidate_hash' => $case->candidate->candidateHash,
            'base_commit' => $case->candidate->baseCommit, 'diff_hash' => $case->candidate->diffHash,
            'tree_hash' => $case->candidate->treeHash, 'files' => $case->candidate->files,
            'verification_run_id' => $case->verification->runId,
            'verification_hash' => $case->verification->receiptHash, 'commands_hash' => RealExecutionHash::make((array) data_get($verification, 'commands', [])),
            'provider_identity' => $case->verification->providerIdentity,
            'author_identity' => $case->verification->authorIdentity,
            'verifier_identity' => $case->verification->verifierIdentity,
            'provider_receipt_hash' => data_get($verification, 'identities.provider_receipt_hash'),
            'runner_path' => data_get($verification, 'behavioral.runner_path'),
            'runner_hash' => data_get($verification, 'behavioral.runner_hash'),
            'runner_version' => data_get($verification, 'behavioral.runner_version'),
            'mechanical_junit' => (array) data_get($verification, 'junit_artifact', []),
            'behavioral_junit' => data_get($verification, 'behavioral.junit_artifact'),
            'diff_artifact' => (array) data_get($verification, 'diff_artifact', []),
        ];
    }
    public function candidateQaArtifactsValid(CandidateQualityCase $case): bool
    {
        try {
            $this->support->verifiedMutativeVerification($case->verification, $case->order);
        } catch (\Throwable) {
            return false;
        }

        return $case->verification->providerIdentity !== $case->verification->verifierIdentity
            && $case->verification->authorIdentity !== $case->verification->verifierIdentity;
    }
    /** @param array<string,mixed> $payload */
    public function qaOwnerSignature(array $payload): string
    {
        return hash_hmac('sha256', RealExecutionHash::make($payload), hash_hmac('sha256', AtlasRealEngineeringExecutionKernelService::CANDIDATE_QA_OWNER_DOMAIN, $this->support->producerKeyMaterial(), true));
    }
    /** @param array<string,mixed> $payload @return array<string,string> */
    public function qaOwnerProducerSeal(array $payload): array
    {
        $key = $this->support->producerKeyMaterial();
        $seal = ['domain' => AtlasRealEngineeringExecutionKernelService::CANDIDATE_QA_OWNER_DOMAIN, 'key_id' => 'app-key-'.substr(hash('sha256', $key), 0, 16), 'payload_hash' => EngineeringCompanyHash::make($payload)];
        $authorityKey = hash_hmac('sha256', 'atlas.engineering_kernel.evidence_authority.v1', $key, true);
        $seal['signature'] = hash_hmac('sha256', EngineeringCompanyHash::make($seal), hash_hmac('sha256', AtlasRealEngineeringExecutionKernelService::CANDIDATE_QA_OWNER_DOMAIN, $authorityKey, true));

        return $seal;
    }
    /** @param array<string,mixed> $payload */
    public function qaOwnerProducerSealValid(array $payload, mixed $producer): bool
    {
        if (! is_array($producer) || ($producer['domain'] ?? null) !== AtlasRealEngineeringExecutionKernelService::CANDIDATE_QA_OWNER_DOMAIN
            || ! hash_equals((string) ($producer['payload_hash'] ?? ''), EngineeringCompanyHash::make($payload))) {
            return false;
        }
        $signature = (string) ($producer['signature'] ?? '');
        $unsigned = array_diff_key($producer, ['signature' => true]);
        $key = $this->support->producerKeyMaterial();
        $authorityKey = hash_hmac('sha256', 'atlas.engineering_kernel.evidence_authority.v1', $key, true);

        return hash_equals($signature, hash_hmac('sha256', EngineeringCompanyHash::make($unsigned), hash_hmac('sha256', AtlasRealEngineeringExecutionKernelService::CANDIDATE_QA_OWNER_DOMAIN, $authorityKey, true)));
    }
}
