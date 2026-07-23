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

class BackendOwnerSection
{
    public function __construct(private readonly KernelReceiptSupport $support)
    {
    }

    public function persistCandidateBackendOwnerReceipt(AiEngineeringCompanyEngagement $engagement, AiEngineeringCompanyCycle $cycle, CandidateQualityCase $case): AiEngineeringCompanyRoleRun
    {
        if (! $engagement->exists || ! $cycle->exists || $cycle->engagement_record_id !== $engagement->getKey()
            || (string) $engagement->getKey() !== $case->engagementRecordId || (string) $cycle->getKey() !== $case->cycleRecordId) {
            throw new \InvalidArgumentException('candidate_backend_owner_binding_invalid');
        }
        $specAuthority = $this->backendSpecCourtAuthorityReceipt($case);
        if ($specAuthority === null) {
            throw new \InvalidArgumentException('candidate_backend_product_spec_contract_authority_absent');
        }
        $evidence = $this->candidateBackendEvidence($case, true);
        $disposition = $this->candidateBackendDisposition($case, $evidence);
        $id = 'aerebackend_'.substr(RealExecutionHash::make([$case->caseHash, 'backend']), 0, 24);
        if (AiEngineeringCompanyRoleRun::query()->where('role_run_id', $id)->exists()) {
            throw new \InvalidArgumentException('candidate_backend_owner_duplicate');
        }
        $issued = CarbonImmutable::now()->startOfSecond();
        $expires = $issued->addHour();
        $refs = ['candidate:'.$case->candidate->candidateHash, 'verification:'.$case->verification->receiptHash,
            'backend_artifact:'.(string) data_get($evidence, 'raw_artifact.sha256'),
            'spec_court_receipt:'.$specAuthority['receipt_hash']];
        $typed = RoleEvidenceReceipt::issue($case, $disposition, AtlasRealEngineeringExecutionKernelService::CANDIDATE_BACKEND_OWNER_DOMAIN,
            AtlasRealEngineeringExecutionKernelService::CANDIDATE_BACKEND_OWNER_VERSION, $issued->toAtomString(), $expires->toAtomString(), $refs);
        $output = ['disposition' => $disposition->toArray(), 'role_evidence_receipt' => $typed->toArray()];
        $receipt = ['schema_version' => AtlasRealEngineeringCompanyRuntimeService::ROLE_SCHEMA,
            'purpose' => 'candidate_backend_owner_evidence', 'owner_domain' => AtlasRealEngineeringExecutionKernelService::CANDIDATE_BACKEND_OWNER_DOMAIN,
            'owner_version' => AtlasRealEngineeringExecutionKernelService::CANDIDATE_BACKEND_OWNER_VERSION, 'owner_identity' => AtlasRealEngineeringExecutionKernelService::class,
            'issued_at' => $issued->toAtomString(), 'expires_at' => $expires->toAtomString(), 'role_run_id' => $id,
            'role_id' => 'backend', 'status' => $disposition->status === 'pass' ? 'passed' : ($disposition->status === 'not_applicable' ? 'not_applicable' : 'blocked'),
            'output' => $output, 'evidence_refs' => $refs, 'binding' => $this->support->backendOwnerBinding($case),
            'disposition' => $disposition->toArray(), 'backend_evidence' => $evidence,
            'spec_court_receipt_ref' => ['row_id' => $specAuthority['row_id'], 'receipt_hash' => $specAuthority['receipt_hash']]];
        $receipt['producer'] = $this->support->candidateOwnerProducerSeal($receipt, AtlasRealEngineeringExecutionKernelService::CANDIDATE_BACKEND_OWNER_DOMAIN);
        $receipt['hash'] = EngineeringCompanyHash::make($receipt);

        return AiEngineeringCompanyRoleRun::query()->create(['engagement_record_id' => $engagement->getKey(),
            'cycle_record_id' => $cycle->getKey(), 'role_run_id' => $id, 'role_id' => 'backend', 'status' => $receipt['status'],
            'responsibilities' => [], 'output' => $output, 'evidence_refs' => $refs, 'receipt' => $receipt, 'role_hash' => $receipt['hash']]);
    }
    public function candidateBackendOwnerReceiptValid(AiEngineeringCompanyRoleRun $row, CandidateQualityCase $case): bool
    {
        $persisted = $row->exists ? AiEngineeringCompanyRoleRun::query()->find($row->getKey()) : null;
        $receipt = $persisted?->receipt;
        if (! $persisted instanceof AiEngineeringCompanyRoleRun || ! is_array($receipt) || $persisted->role_id !== 'backend') {
            return false;
        }
        try {
            $issued = CarbonImmutable::createFromFormat(DATE_ATOM, (string) ($receipt['issued_at'] ?? ''));
            $expires = CarbonImmutable::createFromFormat(DATE_ATOM, (string) ($receipt['expires_at'] ?? ''));
            $evidence = (array) ($receipt['backend_evidence'] ?? []);
            $specAuthority = $this->backendSpecCourtAuthorityReceipt($case);
            if ($specAuthority === null || ($receipt['spec_court_receipt_ref'] ?? null) !== [
                'row_id' => $specAuthority['row_id'], 'receipt_hash' => $specAuthority['receipt_hash'],
            ]) {
                return false;
            }
            $verification = $this->support->verifiedMutativeVerificationReceipt($case->verification, $case->order, true);
            $currentQaContract = ['verification_hash' => $case->verification->receiptHash,
                'commands_hash' => RealExecutionHash::make((array) data_get($verification->receipt, 'commands', [])),
                'runner_hash' => data_get($verification->receipt, 'behavioral.runner_hash'),
                'behavioral_junit_hash' => data_get($verification->receipt, 'behavioral.junit_artifact.sha256'),
                'backend_contract_hash' => $specAuthority['artifact']['sha256']];
            if (($evidence['qa_contract'] ?? null) !== $currentQaContract
                || in_array(AtlasRealEngineeringExecutionKernelService::class, [$case->verification->providerIdentity, $case->verification->authorIdentity, $case->verification->verifierIdentity], true)) {
                return false;
            }
            $binding = (array) data_get($verification->receipt, 'binding', []);
            $currentSourceHashes = (array) ($binding['source_hashes'] ?? []);
            $currentAnalyses = [];
            foreach ($case->candidate->files as $file) {
                if (! str_ends_with($file, '.php') || ! str_starts_with($file, 'app/')) {
                    continue;
                }
                $source = $this->support->signedTreeFile((string) ($binding['tree_hash'] ?? ''), $file, $case->candidate->sandboxRoot);
                if ($source === null || ! hash_equals((string) ($currentSourceHashes[$file] ?? ''), hash('sha256', $source))) {
                    return false;
                }
                $currentAnalyses[$file] = $this->analyzeBackendPhpSource($source);
            }
            if (($evidence['source_analyses'] ?? null) !== $currentAnalyses
                || ($evidence['source_contract_hash'] ?? null) !== RealExecutionHash::make($currentAnalyses)) {
                return false;
            }
            $artifact = (array) ($evidence['raw_artifact'] ?? []);
            $root = $this->support->ownedAtlasArtifactRoot($case->candidate->sandboxRoot);
            $path = realpath((string) ($artifact['path'] ?? ''));
            if ($path === false || ! str_starts_with($path, $root.'/') || is_link((string) ($artifact['path'] ?? ''))
                || ! hash_equals((string) ($artifact['sha256'] ?? ''), (string) hash_file('sha256', $path))) {
                return false;
            }
            $raw = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            // Igualdade canônica, não ===: o receipt volta do jsonb com chaves reordenadas.
            if (! is_array($raw) || ! hash_equals(RealExecutionHash::make($raw), RealExecutionHash::make(array_diff_key($evidence, ['raw_artifact' => true])))) {
                return false;
            }
            $disposition = $this->candidateBackendDisposition($case, $evidence);
        } catch (\Throwable) {
            return false;
        }
        $refs = ['candidate:'.$case->candidate->candidateHash, 'verification:'.$case->verification->receiptHash,
            'backend_artifact:'.(string) data_get($evidence, 'raw_artifact.sha256'),
            'spec_court_receipt:'.$specAuthority['receipt_hash']];
        $typed = RoleEvidenceReceipt::issue($case, $disposition, AtlasRealEngineeringExecutionKernelService::CANDIDATE_BACKEND_OWNER_DOMAIN,
            AtlasRealEngineeringExecutionKernelService::CANDIDATE_BACKEND_OWNER_VERSION, (string) $receipt['issued_at'], (string) $receipt['expires_at'], $refs)->toArray();
        $output = $persisted->output;
        $unsigned = array_diff_key($receipt, ['hash' => true]);

        return is_array($output) && $issued !== null && $expires !== null && ! CarbonImmutable::now()->lt($issued) && CarbonImmutable::now()->lt($expires)
            && ($receipt['purpose'] ?? null) === 'candidate_backend_owner_evidence'
            && ($receipt['owner_domain'] ?? null) === AtlasRealEngineeringExecutionKernelService::CANDIDATE_BACKEND_OWNER_DOMAIN
            && ($receipt['binding'] ?? null) === $this->support->backendOwnerBinding($case)
            && ($receipt['evidence_refs'] ?? null) === $refs && ($receipt['output'] ?? null) === $output
            && ($output['disposition'] ?? null) === $disposition->toArray() && ($output['role_evidence_receipt'] ?? null) === $typed
            && hash_equals((string) $persisted->role_hash, (string) ($receipt['hash'] ?? ''))
            && hash_equals((string) ($receipt['hash'] ?? ''), EngineeringCompanyHash::make($unsigned))
            && $this->support->candidateOwnerProducerSealValid($unsigned, AtlasRealEngineeringExecutionKernelService::CANDIDATE_BACKEND_OWNER_DOMAIN);
    }
    /** @param array<string,mixed> $evidence */
    public function candidateBackendDisposition(CandidateQualityCase $case, array $evidence): RoleDisposition
    {
        $applies = ($evidence['applies'] ?? false) === true;
        $safe = ($evidence['safe'] ?? false) === true;
        $status = ! $applies ? 'not_applicable' : ($safe ? 'pass' : 'block');
        $reason = ! $applies ? 'signed_no_backend_runtime_change' : ($safe ? 'candidate_backend_contract_verified' : 'candidate_backend_unknown_or_contract_invalid');
        $payload = ['purpose' => 'candidate_backend_disposition', 'case_hash' => $case->caseHash,
            'evidence_hash' => RealExecutionHash::make($evidence), 'status' => $status, 'reason' => $reason, 'owner_identity' => AtlasRealEngineeringExecutionKernelService::class];

        return RoleDisposition::backendCandidateAdjudicated($case, $status, $reason, AtlasRealEngineeringExecutionKernelService::CANDIDATE_BACKEND_OWNER_DOMAIN,
            $this->support->candidateOwnerSignature($payload, AtlasRealEngineeringExecutionKernelService::CANDIDATE_BACKEND_OWNER_DOMAIN));
    }
    /** @return array<string,mixed> */
    public function candidateBackendEvidence(CandidateQualityCase $case, bool $writeArtifact): array
    {
        $verification = $this->support->verifiedMutativeVerificationReceipt($case->verification, $case->order, $writeArtifact);
        $binding = (array) data_get($verification->receipt, 'binding', []);
        $identities = (array) data_get($verification->receipt, 'identities', []);
        $tree = (string) ($binding['tree_hash'] ?? '');
        $sourceHashes = (array) ($binding['source_hashes'] ?? []);
        $analyses = [];
        foreach ($case->candidate->files as $file) {
            if (! str_ends_with($file, '.php') || ! str_starts_with($file, 'app/')) {
                continue;
            }
            $source = $this->support->signedTreeFile($tree, $file, $case->candidate->sandboxRoot);
            if ($source === null || ! hash_equals((string) ($sourceHashes[$file] ?? ''), hash('sha256', $source))) {
                throw new \InvalidArgumentException('candidate_backend_signed_source_unavailable');
            }
            $analyses[$file] = $this->analyzeBackendPhpSource($source);
        }
        $specAuthority = $this->backendSpecCourtAuthorityReceipt($case);
        $contract = $specAuthority['contract'] ?? null;
        $includeGraphSafe = is_array($contract) && is_array($contract['permitted_includes'] ?? null);
        $permittedIncludes = $includeGraphSafe ? array_values(array_map('strval', $contract['permitted_includes'])) : [];
        foreach ($analyses as $analysis) {
            foreach ((array) ($analysis['include_targets'] ?? []) as $target) {
                if (! in_array($target, $permittedIncludes, true)
                    || $this->support->signedTreeFile($tree, (string) $target, $case->candidate->sandboxRoot) === null) {
                    $includeGraphSafe = false;
                }
            }
        }
        $entrypoint = $this->support->signedTreeFile($tree, is_array($contract) ? (string) ($contract['entrypoint'] ?? '') : '', $case->candidate->sandboxRoot);
        $profile = $analyses === [] ? ['status' => 'not_applicable', 'safe' => true]
            : (! is_array($contract) || ($contract['spec_hash'] ?? null) !== $case->order->specHash || ! is_string($entrypoint)
                ? ['status' => 'spec_contract_missing_or_invalid', 'safe' => false]
                : $this->runBackendContractProfile($entrypoint, $contract));
        $qaContract = ['verification_hash' => $case->verification->receiptHash,
            'commands_hash' => RealExecutionHash::make((array) data_get($verification->receipt, 'commands', [])),
            'runner_hash' => data_get($verification->receipt, 'behavioral.runner_hash'),
            'behavioral_junit_hash' => data_get($verification->receipt, 'behavioral.junit_artifact.sha256'),
            'backend_contract_hash' => $specAuthority['artifact']['sha256'] ?? null];
        $identitySeparated = ! in_array(AtlasRealEngineeringExecutionKernelService::class, [(string) ($identities['provider'] ?? ''), (string) ($identities['author'] ?? ''), (string) ($identities['verifier'] ?? '')], true);
        $safe = $analyses !== [] && ! array_any($analyses, static fn (array $analysis): bool => ($analysis['safe'] ?? false) !== true)
            && $includeGraphSafe && ($profile['safe'] ?? false) === true && $identitySeparated;
        $raw = ['schema_version' => 'atlas.candidate_backend_evidence.v1', 'case_hash' => $case->caseHash,
            'order_hash' => $case->order->canonicalHash(), 'spec_hash' => $case->order->specHash,
            'candidate_hash' => $case->candidate->candidateHash, 'base_commit' => $case->candidate->baseCommit,
            'tree_hash' => $case->candidate->treeHash, 'diff_hash' => $case->candidate->diffHash, 'files' => $case->candidate->files,
            'source_analyses' => $analyses, 'source_contract_hash' => RealExecutionHash::make($analyses),
            'include_graph_safe' => $includeGraphSafe,
            'qa_contract' => $qaContract, 'qa_contract_hash' => RealExecutionHash::make($qaContract), 'behavioral_profile' => $profile,
            'spec_contract' => $contract, 'spec_contract_hash' => is_array($contract) ? RealExecutionHash::make($contract) : null,
            'identity_separated' => $identitySeparated, 'applies' => $analyses !== [], 'safe' => $safe, 'owner_identity' => AtlasRealEngineeringExecutionKernelService::class];
        $root = $this->support->ownedAtlasArtifactRoot($case->candidate->sandboxRoot);
        $artifact = $root.'/backend-contract-'.$case->caseHash.'.json';
        if ($writeArtifact) {
            File::put($artifact, json_encode($raw, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        }
        $real = realpath($artifact);
        if ($real === false || ! str_starts_with($real, $root.'/') || is_link($artifact)) {
            throw new \InvalidArgumentException('candidate_backend_artifact_unavailable');
        }

        return $raw + ['raw_artifact' => ['path' => $real, 'sha256' => hash_file('sha256', $real)]];
    }
    /** @return array{row_id:string,receipt_hash:string,contract:array<string,mixed>,artifact:array<string,string>}|null */
    public function backendSpecCourtAuthorityReceipt(CandidateQualityCase $case): ?array
    {
        $expectedBinding = $this->support->backendOwnerBinding($case);
        $rows = AiEngineeringCompanyRoleRun::query()->where('role_id', 'backend_spec_authority')->get()
            ->filter(static fn (AiEngineeringCompanyRoleRun $row): bool => data_get($row->receipt, 'purpose') === 'product_spec_backend_contract_authority'
                && data_get($row->receipt, 'binding.case_hash') === $case->caseHash);
        if ($rows->count() !== 1) {
            return null;
        }
        $row = $rows->first();
        $receipt = $row?->receipt;
        if (! $row instanceof AiEngineeringCompanyRoleRun || ! is_array($receipt)) {
            return null;
        }
        try {
            $issued = CarbonImmutable::createFromFormat(DATE_ATOM, (string) ($receipt['issued_at'] ?? ''));
            $expires = CarbonImmutable::createFromFormat(DATE_ATOM, (string) ($receipt['expires_at'] ?? ''));
        } catch (\Throwable) {
            return null;
        }
        $artifact = $receipt['contract_artifact'] ?? null;
        $root = $this->support->ownedAtlasArtifactRoot($case->candidate->sandboxRoot);
        $declared = is_array($artifact) ? (string) ($artifact['path'] ?? '') : '';
        $path = $declared !== '' ? realpath($declared) : false;
        if ($issued === null || $expires === null || CarbonImmutable::now()->lt($issued) || CarbonImmutable::now()->gte($expires)
            || ($receipt['owner_domain'] ?? null) !== AtlasRealEngineeringExecutionKernelService::BACKEND_SPEC_COURT_OWNER_DOMAIN
            || ($receipt['owner_version'] ?? null) !== AtlasRealEngineeringExecutionKernelService::BACKEND_SPEC_COURT_OWNER_VERSION
            || ($receipt['owner_identity'] ?? null) !== 'App\\Services\\Ai\\EngineeringKernel\\Spec\\SovereignSpecFloor'
            || ($receipt['binding'] ?? null) !== $expectedBinding || $path === false || ! str_starts_with($path, $root.'/')
            || $this->pathHasSymlinkBetween($root, $declared)
            || ! hash_equals((string) ($artifact['sha256'] ?? ''), (string) hash_file('sha256', $path))) {
            return null;
        }
        try {
            $contract = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }
        $unsigned = array_diff_key($receipt, ['hash' => true]);
        $ledgerRef = $receipt['ledger_event'] ?? null;
        $ledger = is_array($ledgerRef) ? AtlasLedgerEvent::query()->where('event_id', (string) ($ledgerRef['event_id'] ?? ''))->first() : null;
        $ledgerOccurredAt = $ledger instanceof AtlasLedgerEvent ? CarbonImmutable::parse($ledger->getAttribute('occurred_at')) : null;
        $ledgerEnvelopeHash = $ledger instanceof AtlasLedgerEvent ? AtlasEvidenceLedger::computeEventHash([
            'event_id' => $ledger->event_id, 'event_type' => $ledger->event_type, 'envelope_id' => $ledger->envelope_id,
            'correlation_id' => $ledger->correlation_id, 'causation_id' => $ledger->causation_id,
            'scope_type' => $ledger->getAttribute('scope_type'), 'scope_id' => $ledger->getAttribute('scope_id'), 'payload_hash' => $ledger->payload_hash,
            'occurred_at' => $ledgerOccurredAt?->toISOString(),
        ]) : '';
        if (! is_array($contract) || array_keys($contract) !== ['schema_version', 'spec_hash', 'profile', 'entrypoint', 'symbols', 'permitted_includes', 'effects', 'cases']
            || ($contract['schema_version'] ?? null) !== 'atlas.backend_contract.v1' || ($contract['spec_hash'] ?? null) !== $case->order->specHash
            || ! is_array($contract['symbols']) || ! is_array($contract['permitted_includes']) || ! is_array($contract['effects']) || ! is_array($contract['cases'])
            || ! hash_equals((string) $row->role_hash, (string) ($receipt['hash'] ?? ''))
            || ! hash_equals((string) ($receipt['hash'] ?? ''), EngineeringCompanyHash::make($unsigned))
            || ! $this->support->candidateOwnerProducerSealValid($unsigned, AtlasRealEngineeringExecutionKernelService::BACKEND_SPEC_COURT_OWNER_DOMAIN)
            || ! $ledger instanceof AtlasLedgerEvent || ! app(AtlasEvidenceLedger::class)->eventIntegrityValid($ledger)
            || $ledger->event_type !== LedgerEventType::GateEvaluated->value || $ledger->emitter_stage !== AtlasRealEngineeringExecutionKernelService::BACKEND_SPEC_COURT_OWNER_DOMAIN
            || $ledger->emitter_version !== AtlasRealEngineeringExecutionKernelService::BACKEND_SPEC_COURT_OWNER_VERSION || $ledger->getAttribute('scope_type') !== 'engineering_delivery'
            || $ledger->getAttribute('scope_id') !== $case->order->deliveryId || ! hash_equals((string) $ledger->getAttribute('event_hash'), $ledgerEnvelopeHash)
            || ($ledgerRef['occurred_at'] ?? null) !== $ledgerOccurredAt?->toISOString()
            || $ledgerOccurredAt === null || $ledgerOccurredAt->lt($issued) || $ledgerOccurredAt->gt($expires)
            || ($ledgerRef['event_hash'] ?? null) !== $ledger->getAttribute('event_hash') || ($ledgerRef['payload_hash'] ?? null) !== $ledger->payload_hash
            || data_get($ledger->payload, 'receipt_body_hash') !== EngineeringCompanyHash::make(array_diff_key($unsigned, ['ledger_event' => true, 'producer' => true]))
            || data_get($ledger->payload, 'run_id') !== $case->order->runId || data_get($ledger->payload, 'delivery_id') !== $case->order->deliveryId
            || data_get($ledger->payload, 'workspace') !== $case->order->workspace || data_get($ledger->payload, 'case_hash') !== $case->caseHash
            || data_get($ledger->payload, 'order_hash') !== $case->order->canonicalHash() || data_get($ledger->payload, 'spec_hash') !== $case->order->specHash
            || data_get($ledger->payload, 'owner_domain') !== AtlasRealEngineeringExecutionKernelService::BACKEND_SPEC_COURT_OWNER_DOMAIN
            || data_get($ledger->payload, 'owner_version') !== AtlasRealEngineeringExecutionKernelService::BACKEND_SPEC_COURT_OWNER_VERSION) {
            return null;
        }

        return ['row_id' => (string) $row->getKey(), 'receipt_hash' => (string) $receipt['hash'], 'contract' => $contract,
            'artifact' => ['path' => $path, 'sha256' => (string) $artifact['sha256']]];
    }
    public function pathHasSymlinkBetween(string $root, string $path): bool
    {
        $relative = ltrim(substr($path, strlen($root)), '/');
        $cursor = $root;
        foreach (explode('/', $relative) as $part) {
            $cursor .= '/'.$part;
            if (is_link($cursor)) {
                return true;
            }
        }

        return false;
    }
    /** @return array<string,mixed> */
    public function analyzeBackendPhpSource(string $source): array
    {
        try {
            $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($source);
            if (! is_array($ast)) {
                return ['safe' => false, 'findings' => ['parse_unknown']];
            }
        } catch (\Throwable) {
            return ['safe' => false, 'findings' => ['parse_failed']];
        }
        $finder = new NodeFinder;
        $findings = [];
        $includeTargets = [];
        foreach ($finder->find($ast, static fn (Node $node): bool => $node instanceof Node\Expr\Eval_
            || $node instanceof Node\Expr\Exit_ || $node instanceof Node\Expr\ErrorSuppress) as $node) {
            $findings[] = 'forbidden_'.strtolower((new \ReflectionClass($node))->getShortName());
        }
        foreach ($finder->findInstanceOf($ast, Node\FunctionLike::class) as $function) {
            if ($function instanceof Node\Expr\Closure || $function instanceof Node\Expr\ArrowFunction) {
                continue;
            }
            if ($function->getReturnType() === null) {
                $findings[] = 'missing_return_type';
            }
            foreach ($function->getParams() as $parameter) {
                if ($parameter->type === null) {
                    $findings[] = 'missing_parameter_type';
                }
            }
        }
        foreach ($finder->findInstanceOf($ast, Node\Expr\Include_::class) as $include) {
            if (! $include->expr instanceof Node\Scalar\String_) {
                $findings[] = 'dynamic_include_forbidden';
            } else {
                $includeTargets[] = $include->expr->value;
            }
        }
        foreach ($finder->findInstanceOf($ast, Node\Stmt\ClassMethod::class) as $method) {
            if (($method->flags & (Node\Stmt\Class_::MODIFIER_PUBLIC | Node\Stmt\Class_::MODIFIER_PROTECTED | Node\Stmt\Class_::MODIFIER_PRIVATE)) === 0) {
                $findings[] = 'implicit_visibility';
            }
        }
        $returns = $finder->findInstanceOf($ast, Node\Stmt\Return_::class);
        if ($returns === []) {
            $findings[] = 'missing_backend_contract_return';
        }
        $findings = array_values(array_unique($findings));

        return ['safe' => $findings === [], 'findings' => $findings, 'include_targets' => $includeTargets,
            'ast_hash' => RealExecutionHash::make(array_map(
                static fn (Node $node): string => $node::class, $finder->find($ast, static fn (Node $node): bool => true),
            ))];
    }
    /** @param array<string,mixed> $contract @return array<string,mixed> */
    public function runBackendContractProfile(string $source, array $contract): array
    {
        $cases = $contract['cases'] ?? null;
        if (! is_array($cases) || count($cases) !== 1 || ! is_array($cases[0] ?? null)
            || ($contract['schema_version'] ?? null) !== 'atlas.backend_contract.v1') {
            return ['status' => 'contract_unexpressible', 'safe' => false];
        }
        $root = '/private/tmp/atlas-backend-contract-'.bin2hex(random_bytes(12));
        if (! mkdir($root, 0700)) {
            return ['status' => 'runner_unavailable', 'safe' => false];
        }
        try {
            File::put($root.'/candidate.php', $source);
            $sandbox = '/usr/bin/sandbox-exec';
            $profile = '(version 1)(deny default)(deny network*)(allow process*)(allow sysctl-read)(allow mach-lookup)'
                .'(allow file-read-metadata)(allow file-read* (literal "/") (subpath "/opt/homebrew") (subpath "/usr/lib") (subpath "/System/Library") (subpath "/Library") (subpath "/private/etc") (subpath "'.$root.'") (literal "/dev/null") (literal "/dev/urandom"))'
                .'(allow file-write* (literal "/dev/null"))';
            $secret = random_bytes(32);
            $nonce = bin2hex(random_bytes(24));
            $supervisor = <<<'PHP'
                $parentSecret=base64_decode((string)getenv('ATLAS_BACKEND_PARENT_SECRET'),true); $nonce=(string)getenv('ATLAS_BACKEND_NONCE');
                if(!is_string($parentSecret)||strlen($parentSecret)!==32){exit(90);} $wrapper=<<<'WRAPPER'
                $pipe=fopen('php://fd/3','rb');$secret=stream_get_contents($pipe);fclose($pipe);$nonce=$argv[1];$path=$argv[2];
                $invoke=static function(string $p):array{try{$value=require $p;return ['type'=>get_debug_type($value),'value_hash'=>hash('sha256',serialize($value)),'error'=>null,'effects'=>[]];}
                catch(Throwable $e){return ['type'=>null,'value_hash'=>null,'error'=>['class'=>$e::class,'message_hash'=>hash('sha256',$e->getMessage())],'effects'=>[]];}};
                $actual=$invoke($path);$marker=['schema_version'=>'atlas.backend_case_result.v1','nonce'=>$nonce,'pid'=>getmypid(),'actual'=>$actual];
                $marker['mac']=hash_hmac('sha256',json_encode($marker,JSON_THROW_ON_ERROR),$secret);echo json_encode($marker,JSON_THROW_ON_ERROR);
                WRAPPER;
                $null=fopen('/dev/null','ab');$desc=[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>$null,3=>['pipe','r']];$completionSecret=random_bytes(32);
                $child=proc_open([$argv[1],'-p',$argv[2],$argv[3],'-n','-r',$wrapper,$nonce,$argv[4]],$desc,$pipes,'/',['HOME'=>'/nonexistent']);
                if(!is_resource($child)){exit(91);}fwrite($pipes[3],$completionSecret);fclose($pipes[3]);$output=stream_get_contents($pipes[1]);fclose($pipes[1]);
                $exit=proc_close($child);if($exit!==0){exit(92);}$marker=json_decode($output,true);
                if(!is_array($marker)||($marker['schema_version']??null)!=='atlas.backend_case_result.v1'||($marker['nonce']??null)!==$nonce||!is_int($marker['pid']??null)){exit(93);}
                $mac=$marker['mac']??null;$unsigned=array_diff_key($marker,['mac'=>true]);if(!is_string($mac)||!hash_equals($mac,hash_hmac('sha256',json_encode($unsigned,JSON_THROW_ON_ERROR),$completionSecret))){exit(94);}
                $envelope=['schema_version'=>'atlas.backend_supervisor.v1','nonce'=>$nonce,'target_pid'=>$marker['pid'],'actual'=>$marker['actual'],'marker_hash'=>hash('sha256',$output)];
                $envelope['supervisor_mac']=hash_hmac('sha256',json_encode($envelope,JSON_THROW_ON_ERROR),$parentSecret);echo json_encode($envelope,JSON_THROW_ON_ERROR);
                PHP;
            $process = new Process([PHP_BINARY, '-n', '-r', $supervisor, $sandbox, $profile, PHP_BINARY, $root.'/candidate.php'], '/', [
                'ATLAS_BACKEND_PARENT_SECRET' => base64_encode($secret), 'ATLAS_BACKEND_NONCE' => $nonce,
            ]);
            $process->setTimeout(3);
            try {
                $process->mustRun();
            } catch (\Throwable) {
                return ['status' => 'case_execution_failed', 'safe' => false];
            }
            $envelope = json_decode($process->getOutput(), true);
            if (! is_array($envelope) || ($envelope['schema_version'] ?? null) !== 'atlas.backend_supervisor.v1'
                || ($envelope['nonce'] ?? null) !== $nonce || ! is_int($envelope['target_pid'] ?? null)
                || ! is_array($envelope['actual'] ?? null) || ! is_string($envelope['supervisor_mac'] ?? null)) {
                return ['status' => 'supervisor_result_invalid', 'safe' => false];
            }
            $mac = $envelope['supervisor_mac'];
            $unsigned = array_diff_key($envelope, ['supervisor_mac' => true]);
            if (! hash_equals($mac, hash_hmac('sha256', json_encode($unsigned, JSON_THROW_ON_ERROR), $secret))) {
                return ['status' => 'supervisor_authentication_failed', 'safe' => false];
            }
            $expected = $cases[0];
            $actual = $envelope['actual'];
            $passed = ($actual['type'] ?? null) === ($expected['expected_type'] ?? null)
                && ($actual['value_hash'] ?? null) === ($expected['expected_value_hash'] ?? null)
                && ($actual['error'] ?? null) === ($expected['expected_error'] ?? null)
                && ($actual['effects'] ?? null) === ($expected['expected_effects'] ?? null);

            return ['status' => $passed ? 'contract_cases_passed' : 'contract_case_mismatch', 'safe' => $passed,
                'case_id' => $expected['id'] ?? null, 'expected_hash' => RealExecutionHash::make($expected), 'supervisor_envelope' => $envelope];
        } finally {
            File::deleteDirectory($root, true);
        }
    }
}
