<?php

namespace App\Services\Ai\RealExecution\EngineeringExecutionKernel;

use App\Models\AiEngineeringCompanyCycle;
use App\Models\AiEngineeringCompanyEngagement;
use App\Models\AiEngineeringCompanyRoleRun;
use App\Services\Ai\EngineeringCompany\AtlasRealEngineeringCompanyRuntimeService;
use App\Services\Ai\EngineeringCompany\EngineeringCompanyHash;
use App\Services\Ai\EngineeringKernel\CandidateQualityCase;
use App\Services\Ai\EngineeringKernel\RoleDisposition;
use App\Services\Ai\EngineeringKernel\RoleEvidenceReceipt;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use Symfony\Component\Process\Process;
use App\Services\Ai\RealExecution\AtlasRealEngineeringExecutionKernelService;
use App\Services\Ai\RealExecution\CandidatePerformancePolicy;
use App\Services\Ai\RealExecution\RealExecutionHash;

class PerformanceOwnerSection
{
    public function __construct(private readonly KernelReceiptSupport $support)
    {
    }

    public function persistCandidatePerformanceOwnerReceipt(AiEngineeringCompanyEngagement $engagement, AiEngineeringCompanyCycle $cycle, CandidateQualityCase $case): AiEngineeringCompanyRoleRun
    {
        if (! $engagement->exists || ! $cycle->exists || $cycle->engagement_record_id !== $engagement->getKey()
            || (string) $engagement->getKey() !== $case->engagementRecordId || (string) $cycle->getKey() !== $case->cycleRecordId) {
            throw new \InvalidArgumentException('candidate_performance_owner_binding_invalid');
        }
        $evidence = $this->candidatePerformanceEvidence($case, true);
        $disposition = $this->candidatePerformanceDisposition($case, $evidence);
        $roleRunId = 'aereperf_'.substr(RealExecutionHash::make([$case->caseHash, 'performance_resilience']), 0, 24);
        if (AiEngineeringCompanyRoleRun::query()->where('role_run_id', $roleRunId)->exists()) {
            throw new \InvalidArgumentException('candidate_performance_owner_duplicate');
        }
        $issued = CarbonImmutable::now()->startOfSecond();
        $expires = $issued->addHour();
        $refs = ['candidate:'.$case->candidate->candidateHash, 'verification:'.$case->verification->receiptHash,
            'performance_artifact:'.(string) data_get($evidence, 'raw_artifact.sha256'),
            'performance_recovery_artifact:'.(string) data_get($evidence, 'recovery_artifact.sha256')];
        $typed = RoleEvidenceReceipt::issue($case, $disposition, AtlasRealEngineeringExecutionKernelService::CANDIDATE_PERFORMANCE_OWNER_DOMAIN,
            AtlasRealEngineeringExecutionKernelService::CANDIDATE_PERFORMANCE_OWNER_VERSION, $issued->toAtomString(), $expires->toAtomString(), $refs);
        $output = ['disposition' => $disposition->toArray(), 'role_evidence_receipt' => $typed->toArray()];
        $receipt = ['schema_version' => AtlasRealEngineeringCompanyRuntimeService::ROLE_SCHEMA,
            'purpose' => 'candidate_performance_owner_evidence', 'owner_domain' => AtlasRealEngineeringExecutionKernelService::CANDIDATE_PERFORMANCE_OWNER_DOMAIN,
            'owner_version' => AtlasRealEngineeringExecutionKernelService::CANDIDATE_PERFORMANCE_OWNER_VERSION, 'owner_identity' => AtlasRealEngineeringExecutionKernelService::class,
            'issued_at' => $issued->toAtomString(), 'expires_at' => $expires->toAtomString(), 'role_run_id' => $roleRunId,
            'role_id' => 'performance_resilience', 'status' => $disposition->status === 'pass' ? 'passed' : ($disposition->status === 'not_applicable' ? 'not_applicable' : 'blocked'),
            'output' => $output, 'evidence_refs' => $refs, 'binding' => $this->support->candidateOwnerBinding($case),
            'disposition' => $disposition->toArray(), 'performance_evidence' => $evidence];
        $receipt['producer'] = $this->support->candidateOwnerProducerSeal($receipt, AtlasRealEngineeringExecutionKernelService::CANDIDATE_PERFORMANCE_OWNER_DOMAIN);
        $receipt['hash'] = EngineeringCompanyHash::make($receipt);

        return AiEngineeringCompanyRoleRun::query()->create(['engagement_record_id' => $engagement->getKey(),
            'cycle_record_id' => $cycle->getKey(), 'role_run_id' => $roleRunId, 'role_id' => 'performance_resilience',
            'status' => $receipt['status'], 'responsibilities' => [], 'output' => $output, 'evidence_refs' => $refs,
            'receipt' => $receipt, 'role_hash' => $receipt['hash']]);
    }
    public function candidatePerformanceOwnerReceiptValid(AiEngineeringCompanyRoleRun $row, CandidateQualityCase $case): bool
    {
        $persisted = $row->exists ? AiEngineeringCompanyRoleRun::query()->find($row->getKey()) : null;
        $receipt = $persisted?->receipt;
        if (! $persisted instanceof AiEngineeringCompanyRoleRun || ! is_array($receipt) || $persisted->role_id !== 'performance_resilience'
            || (string) $persisted->engagement_record_id !== $case->engagementRecordId || (string) $persisted->cycle_record_id !== $case->cycleRecordId) {
            return false;
        }
        try {
            $issued = CarbonImmutable::createFromFormat(DATE_ATOM, (string) ($receipt['issued_at'] ?? ''));
            $expires = CarbonImmutable::createFromFormat(DATE_ATOM, (string) ($receipt['expires_at'] ?? ''));
            $evidence = (array) ($receipt['performance_evidence'] ?? []);
            $artifact = (array) ($evidence['raw_artifact'] ?? []);
            $recoveryArtifact = (array) ($evidence['recovery_artifact'] ?? []);
            $root = $this->support->ownedAtlasArtifactRoot($case->candidate->sandboxRoot);
            $path = realpath((string) ($artifact['path'] ?? ''));
            $recoveryPath = realpath((string) ($recoveryArtifact['path'] ?? ''));
            if ($path === false || ! str_starts_with($path, $root.'/') || is_link((string) ($artifact['path'] ?? ''))
                || ! hash_equals((string) ($artifact['sha256'] ?? ''), (string) hash_file('sha256', $path))
                || $recoveryPath === false || ! str_starts_with($recoveryPath, $root.'/') || is_link((string) ($recoveryArtifact['path'] ?? ''))
                || ! hash_equals((string) ($recoveryArtifact['sha256'] ?? ''), (string) hash_file('sha256', $recoveryPath))) {
                \Illuminate\Support\Facades\Log::warning('candidate_performance_receipt_invalid', ['failed_checks' => ['artifact_paths']]);

                return false;
            }
            $raw = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $recoveryRaw = json_decode((string) file_get_contents($recoveryPath), true, 512, JSON_THROW_ON_ERROR);
            // Igualdade canônica (hash com ksort recursivo), não ===: o receipt
            // volta do jsonb do Postgres com as chaves reordenadas.
            if (! is_array($raw)
                || ! hash_equals(RealExecutionHash::make($raw), RealExecutionHash::make(array_diff_key($evidence, ['raw_artifact' => true])))
                || ! is_array($recoveryRaw)
                // Mesmo fallback do write: sem runtime paths o arquivo de recovery
                // é escrito como ['status' => 'not_applicable'].
                || ! hash_equals(RealExecutionHash::make($recoveryRaw), RealExecutionHash::make($evidence['measurements']['recovery'] ?? ['status' => 'not_applicable']))) {
                \Illuminate\Support\Facades\Log::warning('candidate_performance_receipt_invalid', ['failed_checks' => ['artifact_content']]);

                return false;
            }
            $disposition = $this->candidatePerformanceDisposition($case, $evidence);
        } catch (\Throwable $exception) {
            \Illuminate\Support\Facades\Log::warning('candidate_performance_receipt_invalid', [
                'failed_checks' => ['exception:'.$exception::class.':'.mb_substr($exception->getMessage(), 0, 120)],
            ]);

            return false;
        }
        $refs = ['candidate:'.$case->candidate->candidateHash, 'verification:'.$case->verification->receiptHash,
            'performance_artifact:'.(string) data_get($evidence, 'raw_artifact.sha256'),
            'performance_recovery_artifact:'.(string) data_get($evidence, 'recovery_artifact.sha256')];
        $typed = RoleEvidenceReceipt::issue($case, $disposition, AtlasRealEngineeringExecutionKernelService::CANDIDATE_PERFORMANCE_OWNER_DOMAIN,
            AtlasRealEngineeringExecutionKernelService::CANDIDATE_PERFORMANCE_OWNER_VERSION, (string) $receipt['issued_at'], (string) $receipt['expires_at'], $refs)->toArray();
        $output = $persisted->output;
        $unsigned = array_diff_key($receipt, ['hash' => true]);

        $checks = [
            'output_shape' => is_array($output),
            'window' => $issued !== null && $expires !== null && ! CarbonImmutable::now()->lt($issued) && CarbonImmutable::now()->lt($expires),
            'purpose' => ($receipt['purpose'] ?? null) === 'candidate_performance_owner_evidence',
            'domain' => ($receipt['owner_domain'] ?? null) === AtlasRealEngineeringExecutionKernelService::CANDIDATE_PERFORMANCE_OWNER_DOMAIN,
            'binding' => ($receipt['binding'] ?? null) === $this->support->candidateOwnerBinding($case),
            'evidence_refs' => ($receipt['evidence_refs'] ?? null) === $refs,
            'output_match' => ($receipt['output'] ?? null) === $output,
            'disposition' => ($output['disposition'] ?? null) === $disposition->toArray(),
            'typed_receipt' => ($output['role_evidence_receipt'] ?? null) === $typed,
            'role_hash' => hash_equals((string) $persisted->role_hash, (string) ($receipt['hash'] ?? '')),
            'receipt_hash' => hash_equals((string) ($receipt['hash'] ?? ''), EngineeringCompanyHash::make($unsigned)),
            'producer_seal' => $this->support->candidateOwnerProducerSealValid($unsigned, AtlasRealEngineeringExecutionKernelService::CANDIDATE_PERFORMANCE_OWNER_DOMAIN),
        ];
        $failed = array_keys(array_filter($checks, static fn (bool $ok): bool => ! $ok));
        if ($failed !== []) {
            // Sem isto a corte recusa com um rótulo genérico e o diagnóstico
            // exige mais uma rodada de provider por condição.
            \Illuminate\Support\Facades\Log::warning('candidate_performance_receipt_invalid', ['failed_checks' => $failed]);
        }

        return $failed === [];
    }
    /** @param array<string,mixed> $evidence */
    public function candidatePerformanceDisposition(CandidateQualityCase $case, array $evidence): RoleDisposition
    {
        $applies = ($evidence['applies'] ?? false) === true;
        $safe = ($evidence['safe'] ?? false) === true;
        $status = ! $applies ? 'not_applicable' : ($safe ? 'pass' : 'block');
        $reason = ! $applies ? 'signed_no_runtime_path_change' : ($safe ? 'candidate_performance_within_frozen_budget' : 'candidate_performance_unknown_or_over_budget');
        $payload = ['purpose' => 'candidate_performance_disposition', 'case_hash' => $case->caseHash,
            'evidence_hash' => RealExecutionHash::make($evidence), 'status' => $status, 'reason' => $reason, 'owner_identity' => AtlasRealEngineeringExecutionKernelService::class];

        return RoleDisposition::performanceCandidateAdjudicated($case, $status, $reason, AtlasRealEngineeringExecutionKernelService::CANDIDATE_PERFORMANCE_OWNER_DOMAIN,
            $this->support->candidateOwnerSignature($payload, AtlasRealEngineeringExecutionKernelService::CANDIDATE_PERFORMANCE_OWNER_DOMAIN));
    }
    /** @return array<string,mixed> */
    public function candidatePerformanceEvidence(CandidateQualityCase $case, bool $writeArtifact): array
    {
        $verification = $this->support->verifiedMutativeVerificationReceipt($case->verification, $case->order, $writeArtifact);
        $binding = (array) data_get($verification->receipt, 'binding', []);
        $identities = (array) data_get($verification->receipt, 'identities', []);
        $tree = (string) ($binding['tree_hash'] ?? '');
        $sourceHashes = (array) ($binding['source_hashes'] ?? []);
        $runtimePaths = [];
        foreach ($case->candidate->files as $file) {
            $candidateSource = $this->support->signedTreeFile($tree, $file, $case->candidate->sandboxRoot);
            if ($candidateSource === null || ! hash_equals((string) ($sourceHashes[$file] ?? ''), hash('sha256', $candidateSource))) {
                throw new \InvalidArgumentException('candidate_performance_signed_source_unavailable');
            }
            if (! str_ends_with($file, '.php') || ! $this->phpSourceHasRuntimeImpact($candidateSource)) {
                continue;
            }
            $runtimePaths[] = $file;
        }
        sort($runtimePaths, SORT_STRING);
        $policy = CandidatePerformancePolicy::frozen()->toArray();
        $entrypoint = 'app/Candidate.php';
        $baselineEntrypoint = $this->support->signedTreeFile($case->candidate->baseCommit, $entrypoint, $case->candidate->sandboxRoot);
        $candidateEntrypoint = $this->support->signedTreeFile($tree, $entrypoint, $case->candidate->sandboxRoot);
        $measurements = $runtimePaths === [] ? ['status' => 'not_applicable']
            : ((! is_string($baselineEntrypoint) || ! is_string($candidateEntrypoint))
                ? ['status' => 'preregistered_workload_unavailable', 'safe' => false]
                : $this->runCandidatePerformanceProfile($baselineEntrypoint, $candidateEntrypoint, $policy));
        $identitySeparated = ! in_array(AtlasRealEngineeringExecutionKernelService::class, [(string) ($identities['provider'] ?? ''),
            (string) ($identities['author'] ?? ''), (string) ($identities['verifier'] ?? '')], true);
        $safe = $runtimePaths !== [] && ($measurements['safe'] ?? false) === true && $identitySeparated;
        $raw = ['schema_version' => 'atlas.candidate_performance_evidence.v1', 'case_hash' => $case->caseHash,
            'order_hash' => $case->order->canonicalHash(), 'spec_hash' => $case->order->specHash,
            'candidate_hash' => $case->candidate->candidateHash, 'base_commit' => $case->candidate->baseCommit,
            'tree_hash' => $case->candidate->treeHash, 'diff_hash' => $case->candidate->diffHash, 'files' => $case->candidate->files,
            'runtime_paths' => $runtimePaths, 'baseline_blob_hash' => hash('sha256', (string) $baselineEntrypoint),
            'candidate_blob_hash' => hash('sha256', (string) $candidateEntrypoint), 'policy' => $policy, 'policy_hash' => RealExecutionHash::make($policy),
            'metric_shape_hash' => RealExecutionHash::make(['wall_ms', 'cpu_user_us', 'cpu_system_us', 'peak_memory_bytes', 'throughput', 'timeout', 'recovery']),
            'runner_hash' => hash('sha256', 'atlas.fixed.php_behavior_entrypoint_runner.v2'), 'measurements' => $measurements,
            'identity_separated' => $identitySeparated,
            'provider_identity_hash' => hash('sha256', (string) ($identities['provider'] ?? '')),
            'author_identity_hash' => hash('sha256', (string) ($identities['author'] ?? '')),
            'verifier_identity_hash' => hash('sha256', (string) ($identities['verifier'] ?? '')),
            'applies' => $runtimePaths !== [], 'safe' => $safe, 'owner_identity' => AtlasRealEngineeringExecutionKernelService::class];
        $root = $this->support->ownedAtlasArtifactRoot($case->candidate->sandboxRoot);
        $recoveryArtifact = $root.'/performance-recovery-'.$case->caseHash.'.json';
        $recovery = (array) ($measurements['recovery'] ?? ['status' => 'not_applicable']);
        if ($writeArtifact) {
            File::put($recoveryArtifact, json_encode($recovery, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        }
        $recoveryReal = realpath($recoveryArtifact);
        if ($recoveryReal === false || ! str_starts_with($recoveryReal, $root.'/') || is_link($recoveryArtifact)) {
            throw new \InvalidArgumentException('candidate_performance_recovery_artifact_unavailable');
        }
        $raw['recovery_artifact'] = ['path' => $recoveryReal, 'sha256' => hash_file('sha256', $recoveryReal)];
        $artifact = $root.'/performance-probe-'.$case->caseHash.'.json';
        if ($writeArtifact) {
            File::put($artifact, json_encode($raw, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        }
        $real = realpath($artifact);
        if ($real === false || ! str_starts_with($real, $root.'/') || is_link($artifact)) {
            throw new \InvalidArgumentException('candidate_performance_artifact_unavailable');
        }

        return $raw + ['raw_artifact' => ['path' => $real, 'sha256' => hash_file('sha256', $real)]];
    }
    public function phpSourceHasRuntimeImpact(string $source): bool
    {
        try {
            $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($source);
            if (! is_array($ast)) {
                return true;
            }
        } catch (\Throwable) {
            return true;
        }
        $finder = new NodeFinder;
        foreach ($finder->findInstanceOf($ast, Node\Stmt\ClassLike::class) as $classLike) {
            if (! $classLike instanceof Node\Stmt\Class_ || ! $classLike->extends instanceof Node\Name
                || ! in_array($classLike->extends->getLast(), ['TestCase'], true)) {
                return true;
            }
        }
        if ($finder->findInstanceOf($ast, Node\Stmt\Function_::class) !== []) {
            return true;
        }
        foreach ($ast as $statement) {
            if (! $statement instanceof Node\Stmt\Declare_ && ! $statement instanceof Node\Stmt\Namespace_
                && ! $statement instanceof Node\Stmt\Use_ && ! $statement instanceof Node\Stmt\GroupUse
                && ! $statement instanceof Node\Stmt\ClassLike && ! $statement instanceof Node\Stmt\Nop) {
                return true;
            }
        }

        return false;
    }
    public function phpSourceHasEarlyExit(string $source): bool
    {
        try {
            $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($source);

            return is_array($ast) && (new NodeFinder)->findInstanceOf($ast, Node\Expr\Exit_::class) !== [];
        } catch (\Throwable) {
            return true;
        }
    }
    /** @param array<string,mixed> $policy @return array<string,mixed> */
    public function runCandidatePerformanceProfile(string $baseline, string $candidate, array $policy): array
    {
        $root = '/private/tmp/atlas-performance-'.bin2hex(random_bytes(12));
        if (! mkdir($root, 0700)) {
            return ['status' => 'runner_unavailable', 'safe' => false];
        }
        try {
            File::put($root.'/baseline.php', $baseline);
            File::put($root.'/candidate.php', $candidate);
            File::put($root.'/induced-timeout.php', '<?php usleep(500000);');
            File::put($root.'/early-exit.php', '<?php exit(0);');
            if ($this->phpSourceHasEarlyExit($baseline) || $this->phpSourceHasEarlyExit($candidate)) {
                return ['status' => 'candidate_control_flow_rejected', 'safe' => false];
            }
            $sandboxExec = '/usr/bin/sandbox-exec';
            if (! is_executable($sandboxExec)) {
                return ['status' => 'runner_unavailable', 'safe' => false];
            }
            $profile = '(version 1)(deny default)(deny network*)(allow process*)(allow sysctl-read)(allow mach-lookup)'
                .'(allow file-read-metadata)(allow file-read* (literal "/") (subpath "/opt/homebrew") (subpath "/usr/lib") (subpath "/System/Library") (subpath "/Library") (subpath "/private/etc") (subpath "'.$root.'") (literal "/dev/null") (literal "/dev/urandom"))'
                .'(allow file-write* (literal "/dev/null"))';
            $run = function (string $path, float $timeout) use ($sandboxExec, $profile): ?array {
                $secret = random_bytes(32);
                $nonce = bin2hex(random_bytes(24));
                $supervisor = <<<'PHP'
                    $secret = base64_decode((string) getenv('ATLAS_SUPERVISOR_SECRET'), true);
                    $nonce = (string) getenv('ATLAS_SUPERVISOR_NONCE');
                    if (!is_string($secret) || strlen($secret) !== 32 || !preg_match('/^[a-f0-9]{48}$/', $nonce)) { exit(90); }
                    $wrapper = <<<'WRAPPER'
                    $completionPipe = fopen('php://fd/3', 'rb'); $completionSecret = stream_get_contents($completionPipe); fclose($completionPipe);
                    $completionNonce = (string) $argv[1]; $candidatePath = (string) $argv[2];
                    $executeCandidate = static function (string $isolatedPath): void { for ($i=0; $i<200; $i++) { require $isolatedPath; } };
                    $executeCandidate($candidatePath);
                    $marker = ['schema_version'=>'atlas.performance_completion.v1','nonce'=>$completionNonce,'pid'=>getmypid(),'returned'=>true];
                    $marker['mac']=hash_hmac('sha256',json_encode($marker,JSON_THROW_ON_ERROR),$completionSecret);
                    echo json_encode($marker,JSON_THROW_ON_ERROR);
                    WRAPPER;
                    $null = fopen('/dev/null', 'ab'); $descriptors = [0=>['file','/dev/null','r'],1=>['pipe','w'],2=>$null,3=>['pipe','r']];
                    $before = getrusage(1); $started = hrtime(true);
                    $completionSecret = random_bytes(32);
                    $child = proc_open([$argv[1],'-p',$argv[2],$argv[3],'-n','-r',$wrapper,$nonce,$argv[4]], $descriptors, $pipes, '/', ['HOME'=>'/nonexistent']);
                    if (!is_resource($child)) { exit(91); }
                    fwrite($pipes[3], $completionSecret); fclose($pipes[3]); stream_set_blocking($pipes[1], false);
                    $deadline = microtime(true) + (float) $argv[5]; $timedOut = false;
                    $candidateOutput = '';
                    do { $candidateOutput .= stream_get_contents($pipes[1]); if (strlen($candidateOutput) > 65536) { proc_terminate($child, 9); exit(93); }
                        $status = proc_get_status($child); if (!$status['running']) { break; }
                        if (microtime(true) >= $deadline) { $timedOut = true; proc_terminate($child, 9); break; } usleep(1000);
                    } while (true);
                    $candidateOutput .= stream_get_contents($pipes[1]); fclose($pipes[1]);
                    $exit = proc_close($child); if ($timedOut || ($exit !== 0 && ($status['exitcode'] ?? -1) !== 0)) { exit(92); }
                    $completion = json_decode($candidateOutput, true);
                    if (!is_array($completion) || array_keys($completion) !== ['schema_version','nonce','pid','returned','mac']
                      || $completion['schema_version'] !== 'atlas.performance_completion.v1' || $completion['nonce'] !== $nonce
                      || !is_int($completion['pid']) || $completion['pid'] <= 0 || $completion['returned'] !== true || !is_string($completion['mac'])) { exit(94); }
                    $unsignedCompletion = array_diff_key($completion, ['mac'=>true]);
                    if (!hash_equals($completion['mac'],hash_hmac('sha256',json_encode($unsignedCompletion,JSON_THROW_ON_ERROR),$completionSecret))) { exit(95); }
                    $after = getrusage(1); $wall = (hrtime(true)-$started)/1e6;
                    $metrics = ['wall_ms'=>$wall,
                      'cpu_user_us'=>(($after['ru_utime.tv_sec']-$before['ru_utime.tv_sec'])*1000000)+($after['ru_utime.tv_usec']-$before['ru_utime.tv_usec']),
                      'cpu_system_us'=>(($after['ru_stime.tv_sec']-$before['ru_stime.tv_sec'])*1000000)+($after['ru_stime.tv_usec']-$before['ru_stime.tv_usec']),
                      'peak_memory_bytes'=>(int)($after['ru_maxrss'] ?? 0),'operations'=>200,'throughput'=>200/max($wall/1000,0.000001)];
                    $envelope=['schema_version'=>'atlas.performance_supervisor_result.v1','nonce'=>$nonce,
                      'candidate_output_observed'=>false,'exit_code'=>0,'target_pid'=>$completion['pid'],'completion_marker_hash'=>hash('sha256',$candidateOutput),'metrics'=>$metrics];
                    $envelope['supervisor_mac']=hash_hmac('sha256',json_encode($envelope,JSON_THROW_ON_ERROR),$secret);
                    echo json_encode($envelope,JSON_THROW_ON_ERROR);
                    PHP;
                $process = new Process([PHP_BINARY, '-n', '-r', $supervisor, $sandboxExec, $profile, PHP_BINARY, $path, (string) $timeout], '/', [
                    'ATLAS_SUPERVISOR_SECRET' => base64_encode($secret), 'ATLAS_SUPERVISOR_NONCE' => $nonce,
                ]);
                $process->setTimeout($timeout + 1.0);
                try {
                    $process->mustRun();
                } catch (\Throwable) {
                    return null;
                }
                $envelope = json_decode($process->getOutput(), true);
                if (! is_array($envelope) || array_keys($envelope) !== ['schema_version', 'nonce', 'candidate_output_observed', 'exit_code', 'target_pid', 'completion_marker_hash', 'metrics', 'supervisor_mac']
                    || $envelope['schema_version'] !== 'atlas.performance_supervisor_result.v1' || $envelope['nonce'] !== $nonce
                    || $envelope['candidate_output_observed'] !== false || $envelope['exit_code'] !== 0
                    || ! is_int($envelope['target_pid']) || $envelope['target_pid'] <= 0
                    || ! is_string($envelope['completion_marker_hash']) || preg_match('/^[a-f0-9]{64}$/', $envelope['completion_marker_hash']) !== 1
                    || ! is_array($envelope['metrics'])) {
                    return null;
                }
                $unsigned = array_diff_key($envelope, ['supervisor_mac' => true]);
                if (! is_string($envelope['supervisor_mac']) || ! hash_equals($envelope['supervisor_mac'], hash_hmac('sha256', json_encode($unsigned, JSON_THROW_ON_ERROR), $secret))) {
                    return null;
                }
                $metrics = $envelope['metrics'];
                if (array_keys($metrics) !== ['wall_ms', 'cpu_user_us', 'cpu_system_us', 'peak_memory_bytes', 'operations', 'throughput']) {
                    return null;
                }
                foreach ($metrics as $metric => $value) {
                    if (! is_int($value) && ! is_float($value) || ! is_finite((float) $value) || $value < 0
                        || ($metric === 'peak_memory_bytes' && $value > 1_099_511_627_776)
                        || ($metric !== 'peak_memory_bytes' && $value > max(1_000_000_000, $timeout * 10_000_000))) {
                        return null;
                    }
                }

                return $metrics + ['supervisor_envelope' => $envelope];
            };
            $baselineWarmup = $run($root.'/baseline.php', (float) $policy['timeout_seconds']);
            $candidateWarmup = $run($root.'/candidate.php', (float) $policy['timeout_seconds']);
            if ($baselineWarmup === null || $candidateWarmup === null) {
                return ['status' => 'warmup_failed', 'safe' => false, 'warmup_passed' => false];
            }
            $baselineRuns = [];
            $candidateRuns = [];
            for ($i = 0; $i < (int) $policy['repetitions']; $i++) {
                $baselineRuns[] = $run($root.'/baseline.php', (float) $policy['timeout_seconds']);
                $candidateRuns[] = $run($root.'/candidate.php', (float) $policy['timeout_seconds']);
            }
            $inducedFailure = $run($root.'/induced-timeout.php', 0.01);
            $recovery = $run($root.'/candidate.php', (float) $policy['timeout_seconds']);
            if (in_array(null, $baselineRuns, true) || in_array(null, $candidateRuns, true) || $inducedFailure !== null || $recovery === null) {
                return ['status' => 'timeout_or_runner_failure', 'safe' => false, 'recovery_passed' => false];
            }
            $summarize = static function (array $runs): array {
                $summary = ['runs' => $runs, 'cv' => []];
                foreach (['wall_ms', 'cpu_user_us', 'cpu_system_us', 'peak_memory_bytes', 'throughput'] as $metric) {
                    $values = array_map(static fn (array $run): float => (float) $run[$metric], $runs);
                    $mean = array_sum($values) / count($values);
                    $variance = array_sum(array_map(static fn (float $v): float => ($v - $mean) ** 2, $values)) / count($values);
                    $summary['min_'.$metric] = min($values);
                    $summary['max_'.$metric] = max($values);
                    $summary['cv'][$metric] = $mean > 0 ? sqrt($variance) / $mean : 0.0;
                }

                return $summary;
            };
            $base = $summarize($baselineRuns);
            $cand = $summarize($candidateRuns);
            $budgets = ['wall_ms' => ((float) $base['max_wall_ms'] * (float) $policy['max_ratio']) + (float) $policy['wall_slack_ms'],
                'cpu_user_us' => ((float) $base['max_cpu_user_us'] * (float) $policy['max_ratio']) + (float) $policy['cpu_slack_us'],
                'cpu_system_us' => ((float) $base['max_cpu_system_us'] * (float) $policy['max_ratio']) + (float) $policy['cpu_slack_us'],
                'peak_memory_bytes' => ((float) $base['max_peak_memory_bytes'] * (float) $policy['max_ratio']) + (int) $policy['rss_slack_bytes'],
                'min_throughput' => (float) $base['min_throughput'] / (float) $policy['max_ratio']];
            $dimensions = ['wall' => $cand['max_wall_ms'] <= $budgets['wall_ms'],
                'cpu_user' => $cand['max_cpu_user_us'] <= $budgets['cpu_user_us'],
                'cpu_system' => $cand['max_cpu_system_us'] <= $budgets['cpu_system_us'],
                'peak_rss' => $cand['max_peak_memory_bytes'] <= $budgets['peak_memory_bytes'],
                'throughput' => $cand['min_throughput'] >= $budgets['min_throughput'],
                'noise' => max([...$base['cv'], ...$cand['cv']]) <= (float) $policy['max_cv'],
                'timeout' => true, 'recovery' => data_get($recovery, 'supervisor_envelope.schema_version') === 'atlas.performance_supervisor_result.v1'
                    && data_get($recovery, 'supervisor_envelope.exit_code') === 0];
            $safe = ! in_array(false, $dimensions, true);

            return ['status' => $safe ? 'measured_within_budget' : 'measured_blocked', 'baseline' => $base, 'candidate' => $cand,
                'warmup' => ['baseline' => $baselineWarmup, 'candidate' => $candidateWarmup, 'excluded_from_repetitions' => true],
                'budgets' => $budgets, 'dimensions' => $dimensions,
                'recovery' => ['induced_failure' => 'timeout', 'fresh_process' => $recovery, 'passed' => $dimensions['recovery']],
                'recovery_passed' => $dimensions['recovery'], 'safe' => $safe];
        } finally {
            File::deleteDirectory($root, true);
        }
    }
}
