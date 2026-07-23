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

class KernelReceiptSupport
{
    public function producerKeyMaterial(): string
    {
        $key = (string) config('app.key');
        $decoded = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;

        return is_string($decoded) ? $decoded : '';
    }
    /** @param array<string,mixed> $payload @return array<string,string> */
    public function producerSeal(string $domain, array $payload): array
    {
        $key = $this->producerKeyMaterial();
        $keyId = 'app-key-'.substr(hash('sha256', $key), 0, 16);
        $seal = ['domain' => $domain, 'key_id' => $keyId, 'payload_hash' => RealExecutionHash::make($payload)];
        $authorityKey = hash_hmac('sha256', 'atlas.engineering_kernel.evidence_authority.v1', $key, true);
        $seal['signature'] = hash_hmac('sha256', RealExecutionHash::make($seal), hash_hmac('sha256', $domain, $authorityKey, true));

        return $seal;
    }
    public function signedTreeFile(string $tree, string $file, string $sandbox): ?string
    {
        $show = new Process(['git', 'show', $tree.':'.$file], $sandbox);
        $show->run();

        return $show->isSuccessful() ? $show->getOutput() : null;
    }
    public function ownedAtlasArtifactRoot(string $sandbox): string
    {
        $sandboxRoot = realpath($sandbox);
        $artifactPath = storage_path('app/atlas-owned-candidate-data');
        if ($sandboxRoot === false || is_link(storage_path('app')) || is_link($artifactPath)) {
            throw new \InvalidArgumentException('candidate_artifact_root_invalid');
        }
        File::ensureDirectoryExists($artifactPath, 0700);
        $artifactRoot = realpath($artifactPath);
        if ($artifactRoot === false || dirname($artifactRoot) !== realpath(storage_path('app')) || ! is_writable($artifactRoot)) {
            throw new \InvalidArgumentException('candidate_artifact_root_invalid');
        }

        return $artifactRoot;
    }
    /** @return array<string,mixed> */
    public function candidateOwnerBinding(CandidateQualityCase $case): array
    {
        return ['run_id' => $case->order->runId, 'delivery_id' => $case->order->deliveryId,
            'order_hash' => $case->order->canonicalHash(), 'spec_hash' => $case->order->specHash,
            'case_hash' => $case->caseHash, 'candidate_hash' => $case->candidate->candidateHash,
            'base_commit' => $case->candidate->baseCommit, 'diff_hash' => $case->candidate->diffHash,
            'tree_hash' => $case->candidate->treeHash, 'files' => $case->candidate->files,
            'engagement_record_id' => $case->engagementRecordId, 'cycle_record_id' => $case->cycleRecordId];
    }
    /** @return array<string,mixed> */
    public function backendOwnerBinding(CandidateQualityCase $case): array
    {
        return $this->candidateOwnerBinding($case) + ['workspace' => $case->order->workspace];
    }
    /** @param array<string,mixed> $payload */
    public function candidateOwnerSignature(array $payload, string $domain): string
    {
        return hash_hmac('sha256', RealExecutionHash::make($payload), hash_hmac('sha256', $domain, $this->producerKeyMaterial(), true));
    }
    /** @param array<string,mixed> $payload @return array<string,string> */
    public function candidateOwnerProducerSeal(array $payload, string $domain): array
    {
        $key = $this->producerKeyMaterial();
        $seal = ['domain' => $domain, 'key_id' => 'app-key-'.substr(hash('sha256', $key), 0, 16), 'payload_hash' => EngineeringCompanyHash::make($payload)];
        $authorityKey = hash_hmac('sha256', 'atlas.engineering_kernel.evidence_authority.v1', $key, true);
        $seal['signature'] = hash_hmac('sha256', EngineeringCompanyHash::make($seal), hash_hmac('sha256', $domain, $authorityKey, true));

        return $seal;
    }
    /** @param array<string,mixed> $unsigned */
    public function candidateOwnerProducerSealValid(array $unsigned, string $domain): bool
    {
        $producer = $unsigned['producer'] ?? null;
        if (! is_array($producer) || ($producer['domain'] ?? null) !== $domain) {
            return false;
        }
        $payload = array_diff_key($unsigned, ['producer' => true]);
        $signature = (string) ($producer['signature'] ?? '');
        $seal = array_diff_key($producer, ['signature' => true]);
        $key = $this->producerKeyMaterial();
        $authorityKey = hash_hmac('sha256', 'atlas.engineering_kernel.evidence_authority.v1', $key, true);

        return hash_equals((string) ($producer['payload_hash'] ?? ''), EngineeringCompanyHash::make($payload))
            && hash_equals($signature, hash_hmac('sha256', EngineeringCompanyHash::make($seal), hash_hmac('sha256', $domain, $authorityKey, true)));
    }
    public function verifiedMutativeVerificationReceipt(MutativeVerificationReference $reference, ExecutionOrder $order, bool $requireLiveWorkspace): AiRealExecutionTestRun
    {
        $row = AiRealExecutionTestRun::query()->where('test_run_id', $reference->runId)->first();
        $receipt = $row?->receipt;
        if (! $row instanceof AiRealExecutionTestRun || ! is_array($receipt)
            || ! hash_equals((string) $row->test_hash, $reference->receiptHash)
            || ! hash_equals((string) ($receipt['hash'] ?? ''), $reference->receiptHash)) {
            throw new \InvalidArgumentException('mutative_verification_owner_missing_or_changed');
        }
        $unsigned = array_diff_key($receipt, ['hash' => true]);
        $binding = $receipt['binding'] ?? null;
        $identities = $receipt['identities'] ?? null;
        try {
            $issued = CarbonImmutable::createFromFormat(DATE_ATOM, (string) ($receipt['issued_at'] ?? ''));
            $expires = CarbonImmutable::createFromFormat(DATE_ATOM, (string) ($receipt['expires_at'] ?? ''));
        } catch (\Throwable) {
            throw new \InvalidArgumentException('mutative_verification_owner_invalid');
        }
        if (! is_array($binding) || ! is_array($identities) || $row->status !== 'passed'
            || $issued === null || $expires === null || CarbonImmutable::now()->lt($issued) || CarbonImmutable::now()->gte($expires)
            || ($receipt['verification_run_id'] ?? null) !== $reference->runId
            || ($binding['run_id'] ?? null) !== $order->runId || ($binding['delivery_id'] ?? null) !== $order->deliveryId
            || ($binding['order_hash'] ?? null) !== $order->canonicalHash() || ($binding['spec_hash'] ?? null) !== $order->specHash
            || ($binding['base_commit'] ?? null) !== $order->baseCommit
            || ($binding['candidate_hash'] ?? null) !== $reference->candidateHash
            || ($identities['provider'] ?? null) !== $reference->providerIdentity
            || ($identities['author'] ?? null) !== $reference->authorIdentity
            || ($identities['verifier'] ?? null) !== $reference->verifierIdentity
            || ($identities['verifier_domain'] ?? null) !== AtlasRealEngineeringExecutionKernelService::KERNEL_VERIFICATION_PRODUCER
            || $reference->providerIdentity === $reference->verifierIdentity
            || $reference->authorIdentity === $reference->verifierIdentity
            || ! hash_equals($reference->receiptHash, RealExecutionHash::make($unsigned))
            || ! $this->qaVerificationProducerSealValid($unsigned)
            || ! $this->mutativeVerificationArtifactsValid($receipt)
            || ($requireLiveWorkspace && ! $this->mutativeVerificationWorkspaceMatches($receipt))) {
            throw new \InvalidArgumentException('mutative_verification_owner_invalid');
        }

        return $row;
    }
    /** @param array<string,mixed> $receipt */
    public function mutativeVerificationArtifactsValid(array $receipt): bool
    {
        // Oráculo comportamental só existe quando o order DECLARA um
        // behavioral_profile. Sem profile (dev em workspace estrangeiro, ex.
        // braço atlas_dev do Rivals), a verificação mecânica + diff bindings
        // são o piso e o julgamento comportamental fica com o harness externo.
        $behavioralDeclared = data_get($receipt, 'behavioral.status') !== 'missing';
        if (($receipt['passed'] ?? false) !== true
            || ($behavioralDeclared && data_get($receipt, 'behavioral.passed') !== true)) {
            return false;
        }
        $commands = $receipt['commands'] ?? null;
        if (! is_array($commands) || $commands === [] || array_any($commands, static fn (mixed $row): bool => ! is_array($row) || ($row['passed'] ?? false) !== true)) {
            return false;
        }
        $root = realpath((string) ($receipt['sandbox_root'] ?? '').'/.atlas');
        if ($root === false) {
            return false;
        }
        $artifacts = [$receipt['junit_artifact'] ?? null, $receipt['diff_artifact'] ?? null];
        if ($behavioralDeclared) {
            $artifacts[] = data_get($receipt, 'behavioral.junit_artifact');
        }
        foreach ($artifacts as $artifact) {
            $path = is_array($artifact) ? (string) ($artifact['path'] ?? '') : '';
            $real = $path !== '' ? realpath($path) : false;
            if ($real === false || ! str_starts_with($real, $root.'/') || is_link($path)
                || ! hash_equals((string) ($artifact['sha256'] ?? ''), (string) hash_file('sha256', $real))) {
                return false;
            }
        }
        if (! $behavioralDeclared) {
            return true;
        }
        $runner = (string) data_get($receipt, 'behavioral.runner_path', '');

        return is_file($runner) && ! is_link($runner)
            && hash_equals((string) data_get($receipt, 'behavioral.runner_hash', ''), (string) hash_file('sha256', $runner));
    }
    /** @param array<string,mixed> $receipt */
    public function mutativeVerificationWorkspaceMatches(array $receipt): bool
    {
        $sandbox = (string) ($receipt['sandbox_root'] ?? '');
        $binding = $receipt['binding'] ?? null;
        $files = is_array($binding) && is_array($binding['files'] ?? null) ? array_values(array_map('strval', $binding['files'])) : [];
        $sourceHashes = is_array($binding) && is_array($binding['source_hashes'] ?? null) ? $binding['source_hashes'] : [];
        $base = is_array($binding) ? (string) ($binding['base_commit'] ?? '') : '';
        if ($sandbox === '' || ! is_dir($sandbox.'/.git') || $files === [] || $base === '' || array_keys($sourceHashes) !== $files) {
            return false;
        }
        foreach ($files as $file) {
            $path = $sandbox.'/'.$file;
            if (! is_file($path) || is_link($path)
                || ! hash_equals((string) ($sourceHashes[$file] ?? ''), (string) hash_file('sha256', $path))) {
                return false;
            }
        }
        $index = $sandbox.'/.atlas/revalidate-'.bin2hex(random_bytes(8)).'.index';
        if (! File::copy($sandbox.'/.git/index', $index)) {
            return false;
        }
        try {
            (new Process(['git', 'add', '-A', '--', ...$files], $sandbox, ['GIT_INDEX_FILE' => $index]))->mustRun();
            $tree = trim((new Process(['git', 'write-tree'], $sandbox, ['GIT_INDEX_FILE' => $index]))->mustRun()->getOutput());
            $diff = (new Process(['git', 'diff', '--cached', '--binary', $base, '--', ...$files], $sandbox, ['GIT_INDEX_FILE' => $index]))->mustRun()->getOutput();

            return hash_equals((string) ($binding['tree_hash'] ?? ''), $tree)
                && hash_equals((string) ($binding['diff_hash'] ?? ''), hash('sha256', $diff));
        } catch (\Throwable) {
            return false;
        } finally {
            @unlink($index);
        }
    }
    /** @param array<string,mixed> $unsigned */
    public function qaVerificationProducerSealValid(array $unsigned): bool
    {
        $producer = $unsigned['producer'] ?? null;
        if (! is_array($producer) || ($producer['domain'] ?? null) !== AtlasRealEngineeringExecutionKernelService::KERNEL_VERIFICATION_PRODUCER) {
            return false;
        }
        $payload = array_diff_key($unsigned, ['producer' => true]);
        if (! hash_equals((string) ($producer['payload_hash'] ?? ''), RealExecutionHash::make($payload))) {
            return false;
        }
        $signature = (string) ($producer['signature'] ?? '');
        $seal = array_diff_key($producer, ['signature' => true]);
        $authorityKey = hash_hmac('sha256', 'atlas.engineering_kernel.evidence_authority.v1', $this->producerKeyMaterial(), true);

        return hash_equals($signature, hash_hmac('sha256', RealExecutionHash::make($seal), hash_hmac('sha256', AtlasRealEngineeringExecutionKernelService::KERNEL_VERIFICATION_PRODUCER, $authorityKey, true)));
    }
    public function verifiedMutativeVerification(MutativeVerificationReference $reference, ExecutionOrder $order): AiRealExecutionTestRun
    {
        return $this->verifiedMutativeVerificationReceipt($reference, $order, true);
    }
}
