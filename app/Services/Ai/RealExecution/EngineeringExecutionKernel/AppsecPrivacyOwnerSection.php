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
use App\Services\Engineering\CodeGraph\CodeGraphSecretScanner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use Symfony\Component\Process\Process;
use App\Services\Ai\RealExecution\AtlasRealEngineeringExecutionKernelService;
use App\Services\Ai\RealExecution\RealExecutionHash;

class AppsecPrivacyOwnerSection
{
    public function __construct(private readonly KernelReceiptSupport $support)
    {
    }

    public function persistCandidateAppsecPrivacyOwnerReceipt(AiEngineeringCompanyEngagement $engagement, AiEngineeringCompanyCycle $cycle, CandidateQualityCase $case): AiEngineeringCompanyRoleRun
    {
        if (! $engagement->exists || ! $cycle->exists || $cycle->engagement_record_id !== $engagement->getKey()
            || (string) $engagement->getKey() !== $case->engagementRecordId || (string) $cycle->getKey() !== $case->cycleRecordId) {
            throw new \InvalidArgumentException('candidate_appsec_privacy_owner_binding_invalid');
        }
        $evidence = $this->candidateAppsecPrivacyEvidence($case, true);
        $disposition = $this->candidateAppsecPrivacyDisposition($case, $evidence);
        $roleRunId = 'aereappsec_'.substr(RealExecutionHash::make([$case->caseHash, 'appsec_privacy']), 0, 22);
        if (AiEngineeringCompanyRoleRun::query()->where('role_run_id', $roleRunId)->exists()) {
            throw new \InvalidArgumentException('candidate_appsec_privacy_owner_duplicate');
        }
        $issued = CarbonImmutable::now()->startOfSecond();
        $expires = $issued->addHour();
        $refs = ['candidate:'.$case->candidate->candidateHash, 'verification:'.$case->verification->receiptHash,
            'appsec_privacy_artifact:'.(string) data_get($evidence, 'raw_artifact.sha256')];
        $typed = RoleEvidenceReceipt::issue($case, $disposition, AtlasRealEngineeringExecutionKernelService::CANDIDATE_APPSEC_PRIVACY_OWNER_DOMAIN,
            AtlasRealEngineeringExecutionKernelService::CANDIDATE_APPSEC_PRIVACY_OWNER_VERSION, $issued->toAtomString(), $expires->toAtomString(), $refs);
        $output = ['disposition' => $disposition->toArray(), 'role_evidence_receipt' => $typed->toArray()];
        $receipt = ['schema_version' => AtlasRealEngineeringCompanyRuntimeService::ROLE_SCHEMA,
            'purpose' => 'candidate_appsec_privacy_owner_evidence', 'owner_domain' => AtlasRealEngineeringExecutionKernelService::CANDIDATE_APPSEC_PRIVACY_OWNER_DOMAIN,
            'owner_version' => AtlasRealEngineeringExecutionKernelService::CANDIDATE_APPSEC_PRIVACY_OWNER_VERSION, 'owner_identity' => CodeGraphSecretScanner::class,
            'issued_at' => $issued->toAtomString(), 'expires_at' => $expires->toAtomString(), 'role_run_id' => $roleRunId,
            'role_id' => 'appsec_privacy', 'status' => $disposition->status === 'pass' ? 'passed' : ($disposition->status === 'not_applicable' ? 'not_applicable' : 'blocked'),
            'output' => $output, 'evidence_refs' => $refs, 'binding' => $this->support->candidateOwnerBinding($case),
            'disposition' => $disposition->toArray(), 'appsec_privacy_evidence' => $evidence];
        $receipt['producer'] = $this->support->candidateOwnerProducerSeal($receipt, AtlasRealEngineeringExecutionKernelService::CANDIDATE_APPSEC_PRIVACY_OWNER_DOMAIN);
        $receipt['hash'] = EngineeringCompanyHash::make($receipt);

        return AiEngineeringCompanyRoleRun::query()->create(['engagement_record_id' => $engagement->getKey(),
            'cycle_record_id' => $cycle->getKey(), 'role_run_id' => $roleRunId, 'role_id' => 'appsec_privacy',
            'status' => $receipt['status'], 'responsibilities' => [], 'output' => $output,
            'evidence_refs' => $refs, 'receipt' => $receipt, 'role_hash' => $receipt['hash']]);
    }
    public function candidateAppsecPrivacyOwnerReceiptValid(AiEngineeringCompanyRoleRun $row, CandidateQualityCase $case): bool
    {
        $persisted = $row->exists ? AiEngineeringCompanyRoleRun::query()->find($row->getKey()) : null;
        $receipt = $persisted?->receipt;
        if (! $persisted instanceof AiEngineeringCompanyRoleRun || ! is_array($receipt) || $persisted->role_id !== 'appsec_privacy'
            || (string) $persisted->engagement_record_id !== $case->engagementRecordId || (string) $persisted->cycle_record_id !== $case->cycleRecordId) {
            return false;
        }
        try {
            $issued = CarbonImmutable::createFromFormat(DATE_ATOM, (string) ($receipt['issued_at'] ?? ''));
            $expires = CarbonImmutable::createFromFormat(DATE_ATOM, (string) ($receipt['expires_at'] ?? ''));
            $evidence = (array) ($receipt['appsec_privacy_evidence'] ?? []);
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
            $disposition = $this->candidateAppsecPrivacyDisposition($case, $evidence);
        } catch (\Throwable) {
            return false;
        }
        $refs = ['candidate:'.$case->candidate->candidateHash, 'verification:'.$case->verification->receiptHash,
            'appsec_privacy_artifact:'.(string) data_get($evidence, 'raw_artifact.sha256')];
        $typed = RoleEvidenceReceipt::issue($case, $disposition, AtlasRealEngineeringExecutionKernelService::CANDIDATE_APPSEC_PRIVACY_OWNER_DOMAIN,
            AtlasRealEngineeringExecutionKernelService::CANDIDATE_APPSEC_PRIVACY_OWNER_VERSION, (string) $receipt['issued_at'], (string) $receipt['expires_at'], $refs)->toArray();
        $output = $persisted->output;
        $unsigned = array_diff_key($receipt, ['hash' => true]);

        return is_array($output) && $issued !== null && $expires !== null && ! CarbonImmutable::now()->lt($issued) && CarbonImmutable::now()->lt($expires)
            && ($receipt['purpose'] ?? null) === 'candidate_appsec_privacy_owner_evidence'
            && ($receipt['owner_domain'] ?? null) === AtlasRealEngineeringExecutionKernelService::CANDIDATE_APPSEC_PRIVACY_OWNER_DOMAIN
            && ($receipt['owner_version'] ?? null) === AtlasRealEngineeringExecutionKernelService::CANDIDATE_APPSEC_PRIVACY_OWNER_VERSION
            && ($receipt['owner_identity'] ?? null) === CodeGraphSecretScanner::class
            && ($receipt['binding'] ?? null) === $this->support->candidateOwnerBinding($case)
            && ($receipt['evidence_refs'] ?? null) === $refs && ($output['disposition'] ?? null) === $disposition->toArray()
            && ($output['role_evidence_receipt'] ?? null) === $typed && ($receipt['output'] ?? null) === $output
            && ($receipt['disposition'] ?? null) === $output['disposition']
            && hash_equals((string) $persisted->role_hash, (string) ($receipt['hash'] ?? ''))
            && hash_equals((string) ($receipt['hash'] ?? ''), EngineeringCompanyHash::make($unsigned))
            && $this->support->candidateOwnerProducerSealValid($unsigned, AtlasRealEngineeringExecutionKernelService::CANDIDATE_APPSEC_PRIVACY_OWNER_DOMAIN);
    }
    /** @param array<string,mixed> $evidence */
    public function candidateAppsecPrivacyDisposition(CandidateQualityCase $case, array $evidence): RoleDisposition
    {
        $applies = ($evidence['applies'] ?? false) === true;
        $safe = ($evidence['safe'] ?? false) === true;
        $status = ! $applies ? 'not_applicable' : ($safe ? 'pass' : 'block');
        $reason = ! $applies ? 'signed_no_sensitive_change_applicability' : ($safe ? 'candidate_appsec_privacy_probe_clean' : 'candidate_appsec_privacy_unsafe_or_unknown');
        $payload = ['purpose' => 'candidate_appsec_privacy_disposition', 'case_hash' => $case->caseHash,
            'evidence_hash' => RealExecutionHash::make($evidence), 'status' => $status, 'reason' => $reason,
            'owner_identity' => CodeGraphSecretScanner::class];

        return RoleDisposition::appsecPrivacyCandidateAdjudicated($case, $status, $reason,
            AtlasRealEngineeringExecutionKernelService::CANDIDATE_APPSEC_PRIVACY_OWNER_DOMAIN,
            $this->support->candidateOwnerSignature($payload, AtlasRealEngineeringExecutionKernelService::CANDIDATE_APPSEC_PRIVACY_OWNER_DOMAIN));
    }
    /** @return array<string,mixed> */
    public function candidateAppsecPrivacyEvidence(CandidateQualityCase $case, bool $writeArtifact): array
    {
        $verification = $this->support->verifiedMutativeVerificationReceipt($case->verification, $case->order, $writeArtifact);
        $binding = (array) data_get($verification->receipt, 'binding', []);
        $identities = (array) data_get($verification->receipt, 'identities', []);
        $tree = (string) ($binding['tree_hash'] ?? '');
        $sourceHashes = (array) ($binding['source_hashes'] ?? []);
        try {
            $scanner = app(CodeGraphSecretScanner::class);
        } catch (\Throwable) {
            $scanner = null;
        }
        $findings = [];
        $dependencyFindings = [];
        $sourceDigests = [];
        $sensitivePaths = [];
        foreach ($case->candidate->files as $file) {
            $show = new Process(['git', 'show', $tree.':'.$file], $case->candidate->sandboxRoot);
            $show->run();
            $source = $show->getOutput();
            if (! $show->isSuccessful() || ! isset($sourceHashes[$file])
                || ! hash_equals((string) $sourceHashes[$file], hash('sha256', $source))) {
                throw new \InvalidArgumentException('candidate_appsec_privacy_signed_source_unavailable');
            }
            $sourceDigests[$file] = hash('sha256', $source);
            if (str_contains($source, "\0")) {
                $findings[] = ['path' => $file, 'type' => 'unsupported_binary_construct', 'severity' => 'high'];

                continue;
            }
            if (! $scanner instanceof CodeGraphSecretScanner) {
                $findings[] = ['path' => $file, 'type' => 'scanner_unavailable', 'severity' => 'high'];

                continue;
            }
            try {
                $scan = $scanner->scan($source);
            } catch (\Throwable) {
                $scan = null;
            }
            if (! is_array($scan) || ! isset($scan['findings'], $scan['has_secrets'], $scan['count'])) {
                $findings[] = ['path' => $file, 'type' => 'scanner_unavailable', 'severity' => 'high'];

                continue;
            }
            foreach ((array) $scan['findings'] as $finding) {
                $findings[] = ['path' => $file, 'type' => (string) ($finding['type'] ?? 'unknown'),
                    'severity' => (string) ($finding['severity'] ?? 'unknown'), 'line' => (int) ($finding['line'] ?? 0)];
            }
            if (str_ends_with($file, '.php')) {
                array_push($dependencyFindings, ...$this->phpDependencyProvenanceFindings($source, $file, $tree, $case->candidate->sandboxRoot));
            } elseif (preg_match('/\.(?:js|jsx|ts|tsx)$/i', $file) === 1) {
                array_push($dependencyFindings, ...$this->javascriptDependencyProvenanceFindings($source, $file, $tree, $case->candidate->sandboxRoot));
            }
            if (preg_match('/\.(?:php|js|jsx|ts|tsx|json|ya?ml|env)$/i', $file) === 1 && ! str_starts_with($file, 'tests/')) {
                $sensitivePaths[] = $file;
            }
            if (in_array(basename($file), ['composer.json', 'package.json'], true)) {
                array_push($dependencyFindings, ...$this->manifestLockProvenanceFindings($source, $file, $tree, $case->candidate->sandboxRoot));
            }
        }
        sort($sensitivePaths, SORT_STRING);
        ksort($sourceDigests, SORT_STRING);
        usort($findings, static fn (array $a, array $b): int => [$a['path'], $a['line'] ?? 0, $a['type']] <=> [$b['path'], $b['line'] ?? 0, $b['type']]);
        usort($dependencyFindings, static fn (array $a, array $b): int => [$a['path'], $a['type']] <=> [$b['path'], $b['type']]);
        $scannerFile = (new \ReflectionClass(CodeGraphSecretScanner::class))->getFileName();
        $scannerHash = is_string($scannerFile) && is_file($scannerFile) ? hash_file('sha256', $scannerFile) : 'unavailable';
        $autoloadClassmap = base_path('vendor/composer/autoload_classmap.php');
        $autoloadClassmapHash = is_file($autoloadClassmap) ? hash_file('sha256', $autoloadClassmap) : 'unavailable';
        $signedComposerLock = $this->support->signedTreeFile($tree, 'composer.lock', $case->candidate->sandboxRoot);
        $signedComposerLockHash = $signedComposerLock === null ? 'absent' : hash('sha256', $signedComposerLock);
        if ($scannerHash === 'unavailable') {
            $findings[] = ['path' => '__scanner__', 'type' => 'scanner_unavailable', 'severity' => 'high'];
        }
        if ($autoloadClassmapHash === 'unavailable') {
            $dependencyFindings[] = ['path' => '__autoload__', 'type' => 'autoload_classmap_unavailable'];
        }
        $applies = $sensitivePaths !== [] || $findings !== [] || $dependencyFindings !== [];
        $ownerIdentity = CodeGraphSecretScanner::class;
        foreach (['provider', 'author'] as $actor) {
            if (hash_equals($ownerIdentity, (string) ($identities[$actor] ?? ''))) {
                $findings[] = ['path' => '__identity__', 'type' => $actor.'_equals_appsec_owner', 'severity' => 'high'];
            }
        }
        $raw = ['schema_version' => 'atlas.candidate_appsec_privacy_probe.v1', 'case_hash' => $case->caseHash,
            'order_hash' => $case->order->canonicalHash(), 'spec_hash' => $case->order->specHash,
            'candidate_hash' => $case->candidate->candidateHash, 'base_commit' => $case->candidate->baseCommit,
            'tree_hash' => $case->candidate->treeHash, 'diff_hash' => $case->candidate->diffHash, 'files' => $case->candidate->files,
            'source_hashes' => $sourceDigests, 'sensitive_paths' => $sensitivePaths, 'secret_privacy_findings' => $findings,
            'dependency_provenance_findings' => $dependencyFindings, 'scanner_schema' => CodeGraphSecretScanner::SCHEMA,
            'scanner_hash' => $scannerHash, 'autoload_classmap_hash' => $autoloadClassmapHash,
            'signed_composer_lock_hash' => $signedComposerLockHash,
            'semver_evaluator_hash' => hash('sha256', 'atlas.semver_evaluator.composer_npm_stable_only.v2'), 'applies' => $applies,
            'safe' => $findings === [] && $dependencyFindings === [], 'owner_identity' => $ownerIdentity,
            'owner_identity_hash' => hash('sha256', $ownerIdentity),
            'provider_identity_hash' => hash('sha256', (string) ($identities['provider'] ?? '')),
            'author_identity_hash' => hash('sha256', (string) ($identities['author'] ?? '')),
            'verifier_identity_hash' => hash('sha256', (string) ($identities['verifier'] ?? ''))];
        $root = $this->support->ownedAtlasArtifactRoot($case->candidate->sandboxRoot);
        $artifact = $root.'/appsec-privacy-probe-'.$case->caseHash.'.json';
        if ($writeArtifact) {
            File::put($artifact, json_encode($raw, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        }
        $real = realpath($artifact);
        if ($real === false || ! str_starts_with($real, $root.'/') || is_link($artifact) || ! is_file($real)) {
            throw new \InvalidArgumentException('candidate_appsec_privacy_artifact_unavailable');
        }

        return $raw + ['raw_artifact' => ['path' => $real, 'sha256' => hash_file('sha256', $real)]];
    }
    /** @return list<array<string,string>> */
    public function phpDependencyProvenanceFindings(string $source, string $file, string $tree, string $sandbox): array
    {
        try {
            $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($source);
            if (! is_array($ast)) {
                throw new \RuntimeException('php_ast_empty');
            }
            $traverser = new NodeTraverser;
            $traverser->addVisitor(new NameResolver);
            $traverser->addVisitor(new ParentConnectingVisitor);
            $ast = $traverser->traverse($ast);
        } catch (\Throwable) {
            return [['path' => $file, 'type' => 'php_ast_unsupported']];
        }
        $finder = new NodeFinder;
        $names = [];
        $record = static function (mixed $name) use (&$names): void {
            if ($name instanceof Node\Name) {
                $resolved = $name->getAttribute('resolvedName');
                $names[] = ltrim(($resolved instanceof Node\Name ? $resolved : $name)->toString(), '\\');
            } elseif (is_string($name) && $name !== '') {
                $names[] = ltrim($name, '\\');
            }
        };
        foreach ($finder->findInstanceOf($ast, Node\Name::class) as $name) {
            if ($this->phpAstNameIsDependencyReference($name)) {
                $record($name);
            }
        }
        $names = array_values(array_unique(array_filter($names)));
        sort($names, SORT_STRING);
        $findings = [];
        foreach ($names as $name) {
            if ($this->phpClassResolvableFromTreeOrAutoload($name, $tree, $sandbox)) {
                continue;
            }
            $findings[] = ['path' => $file, 'type' => 'php_dependency_class_unresolved',
                'reference_hash' => hash('sha256', $name)];
        }

        return $findings;
    }
    public function phpAstNameIsDependencyReference(Node\Name $name): bool
    {
        $parent = $name->getAttribute('parent');
        if (! $parent instanceof Node) {
            return false;
        }
        if ($parent instanceof Node\Stmt\Namespace_) {
            return false;
        }
        if ($parent instanceof Node\Stmt\UseUse || $parent instanceof Node\Stmt\TraitUse
            || $parent instanceof Node\Stmt\Catch_ || $parent instanceof Node\Attribute
            || $parent instanceof Node\Expr\New_ || $parent instanceof Node\Expr\StaticCall
            || $parent instanceof Node\Expr\ClassConstFetch || $parent instanceof Node\Expr\StaticPropertyFetch
            || $parent instanceof Node\Expr\Instanceof_ || $parent instanceof Node\Stmt\Class_
            || $parent instanceof Node\Stmt\Interface_ || $parent instanceof Node\Stmt\Enum_) {
            return true;
        }
        if ($parent instanceof Node\UnionType || $parent instanceof Node\IntersectionType || $parent instanceof Node\NullableType) {
            return true;
        }

        return $parent instanceof Node\Param || $parent instanceof Node\Stmt\Property
            || $parent instanceof Node\Stmt\ClassMethod || $parent instanceof Node\Stmt\Function_;
    }
    public function phpClassResolvableFromTreeOrAutoload(string $class, string $tree, string $sandbox): bool
    {
        $composerSource = $this->support->signedTreeFile($tree, 'composer.json', $sandbox);
        $composer = $composerSource === null ? null : json_decode($composerSource, true);
        if (is_array($composer)) {
            foreach (['autoload', 'autoload-dev'] as $section) {
                foreach ((array) data_get($composer, $section.'.psr-4', []) as $prefix => $directories) {
                    if (! is_string($prefix) || ! str_starts_with($class, $prefix)) {
                        continue;
                    }
                    foreach ((array) $directories as $directory) {
                        if (! is_string($directory)) {
                            continue;
                        }
                        $path = rtrim($directory, '/').'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
                        if ($this->signedPhpFileDeclaresClass($tree, $path, $class, $sandbox)) {
                            return true;
                        }
                    }
                }
                foreach ((array) data_get($composer, $section.'.classmap', []) as $mappedPath) {
                    if (! is_string($mappedPath)) {
                        continue;
                    }
                    $listing = new Process(['git', 'ls-tree', '-r', '--name-only', $tree, '--', $mappedPath], $sandbox);
                    $listing->run();
                    if (! $listing->isSuccessful()) {
                        continue;
                    }
                    foreach (array_filter(explode("\n", $listing->getOutput())) as $candidatePath) {
                        if (str_ends_with($candidatePath, '.php')
                            && $this->signedPhpFileDeclaresClass($tree, $candidatePath, $class, $sandbox)) {
                            return true;
                        }
                    }
                }
            }
        }
        $path = match (true) {
            str_starts_with($class, 'App\\') => 'app/'.str_replace('\\', '/', substr($class, 4)).'.php',
            str_starts_with($class, 'Tests\\') => 'tests/'.str_replace('\\', '/', substr($class, 6)).'.php',
            default => '',
        };
        if ($path !== '') {
            return $this->signedPhpFileDeclaresClass($tree, $path, $class, $sandbox);
        }

        if (! class_exists($class) && ! interface_exists($class) && ! trait_exists($class)
            && (! function_exists('enum_exists') || ! enum_exists($class))) {
            return false;
        }
        $reflection = new \ReflectionClass($class);
        $classFile = $reflection->getFileName();
        if ($classFile === false) {
            return true;
        }
        $vendorRoot = realpath(base_path('vendor'));
        $realClassFile = realpath($classFile);

        return $vendorRoot !== false && $realClassFile !== false && str_starts_with($realClassFile, $vendorRoot.'/')
            && $this->signedVendorClassProvenanceValid($class, $realClassFile, $tree, $sandbox);
    }
    public function signedVendorClassProvenanceValid(string $class, string $classFile, string $tree, string $sandbox): bool
    {
        $vendorRoot = realpath(base_path('vendor'));
        if ($vendorRoot === false || ! str_starts_with($classFile, $vendorRoot.'/')) {
            return false;
        }
        $relative = substr($classFile, strlen($vendorRoot) + 1);
        $segments = explode('/', $relative);
        if (count($segments) < 3) {
            return false;
        }
        $packageName = $segments[0].'/'.$segments[1];
        $manifestSource = $this->support->signedTreeFile($tree, 'composer.json', $sandbox);
        $lockSource = $this->support->signedTreeFile($tree, 'composer.lock', $sandbox);
        $manifest = $manifestSource === null ? null : json_decode($manifestSource, true);
        $lock = $lockSource === null ? null : json_decode($lockSource, true);
        if (! is_array($manifest) || ! is_array($lock)) {
            return false;
        }
        $constraint = data_get($manifest, 'require.'.$packageName, data_get($manifest, 'require-dev.'.$packageName));
        if (! is_string($constraint) || $constraint === '') {
            return false;
        }
        foreach ([...(array) ($lock['packages'] ?? []), ...(array) ($lock['packages-dev'] ?? [])] as $package) {
            if (! is_array($package) || ($package['name'] ?? null) !== $packageName || ! is_string($package['version'] ?? null)) {
                continue;
            }
            $reference = data_get($package, 'dist.reference', data_get($package, 'source.reference'));

            return is_string($reference) && $reference !== ''
                && $this->semverSatisfies($package['version'], $constraint, 'composer') === true;
        }

        return false;
    }
    public function signedPhpFileDeclaresClass(string $tree, string $path, string $class, string $sandbox): bool
    {
        $source = $this->support->signedTreeFile($tree, $path, $sandbox);
        if ($source === null) {
            return false;
        }
        try {
            $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($source);
            if (! is_array($ast)) {
                return false;
            }
            $traverser = new NodeTraverser;
            $traverser->addVisitor(new NameResolver);
            $ast = $traverser->traverse($ast);
        } catch (\Throwable) {
            return false;
        }
        foreach ((new NodeFinder)->findInstanceOf($ast, Node\Stmt\ClassLike::class) as $declaration) {
            $declared = $declaration->getAttribute('namespacedName');
            if ($declared instanceof Node\Name && hash_equals($class, $declared->toString())) {
                return true;
            }
        }

        return false;
    }
    /** @return list<array<string,string>> */
    public function javascriptDependencyProvenanceFindings(string $source, string $file, string $tree, string $sandbox): array
    {
        if (preg_match('/\bimport\s*\(\s*[^\'\"]/', $source) === 1) {
            return [['path' => $file, 'type' => 'javascript_dynamic_import_unsupported']];
        }
        preg_match_all('/(?:\bfrom\s*|\bimport\s*(?:\(\s*)?|\brequire\s*\()\s*[\'\"]([^\'\"]+)[\'\"]/', $source, $matches);
        $specifiers = array_values(array_unique($matches[1]));
        if (preg_match('/\b(?:import|require)\b/', $source) === 1 && $specifiers === []) {
            return [['path' => $file, 'type' => 'javascript_module_syntax_unsupported']];
        }
        sort($specifiers, SORT_STRING);
        $findings = [];
        foreach ($specifiers as $specifier) {
            if (! str_starts_with($specifier, '.')) {
                if (! $this->javascriptPackageLocked($specifier, $tree, $sandbox)) {
                    $findings[] = ['path' => $file, 'type' => 'javascript_package_provenance_unresolved',
                        'reference_hash' => hash('sha256', $specifier)];
                }

                continue;
            }
            $base = dirname($file).'/'.$specifier;
            $resolved = false;
            foreach (['', '.js', '.jsx', '.ts', '.tsx', '/index.js', '/index.ts'] as $suffix) {
                $candidate = str_replace('/./', '/', $base).$suffix;
                $probe = new Process(['git', 'cat-file', '-e', $tree.':'.$candidate], $sandbox);
                $probe->run();
                if ($probe->isSuccessful()) {
                    $resolved = true;
                    break;
                }
            }
            if (! $resolved) {
                $findings[] = ['path' => $file, 'type' => 'javascript_relative_import_unresolved',
                    'reference_hash' => hash('sha256', $specifier)];
            }
        }

        return $findings;
    }
    public function javascriptPackageLocked(string $specifier, string $tree, string $sandbox): bool
    {
        $parts = explode('/', $specifier);
        $package = str_starts_with($specifier, '@') ? implode('/', array_slice($parts, 0, 2)) : $parts[0];
        $manifestSource = $this->support->signedTreeFile($tree, 'package.json', $sandbox);
        $lockSource = $this->support->signedTreeFile($tree, 'package-lock.json', $sandbox);
        $manifest = $manifestSource === null ? null : json_decode($manifestSource, true);
        $lock = $lockSource === null ? null : json_decode($lockSource, true);
        if (! is_array($manifest) || ! is_array($lock) || ! is_array($lock['packages'] ?? null)) {
            return false;
        }
        $declared = false;
        $constraint = null;
        foreach (['dependencies', 'devDependencies', 'peerDependencies', 'optionalDependencies'] as $group) {
            if (is_string($manifest[$group][$package] ?? null)) {
                $declared = true;
                $constraint = $manifest[$group][$package];
            }
        }
        $entry = $lock['packages']['node_modules/'.$package] ?? null;
        if (! $declared || ! is_string($constraint) || ! is_array($entry) || ! is_string($entry['version'] ?? null)
            || $entry['version'] === '' || ! is_string($entry['integrity'] ?? null) || $entry['integrity'] === '') {
            return false;
        }

        return $this->semverSatisfies($entry['version'], $constraint, 'npm') === true;
    }
    /** @return list<array<string,string>> */
    public function manifestLockProvenanceFindings(string $source, string $file, string $tree, string $sandbox): array
    {
        $manifest = json_decode($source, true);
        if (! is_array($manifest)) {
            return [['path' => $file, 'type' => 'invalid_dependency_manifest']];
        }
        $directory = dirname($file) === '.' ? '' : dirname($file).'/';
        if (basename($file) === 'composer.json') {
            $lockSource = $this->support->signedTreeFile($tree, $directory.'composer.lock', $sandbox);
            $lock = $lockSource === null ? null : json_decode($lockSource, true);
            if (! is_array($lock) || ! is_string($lock['content-hash'] ?? null) || ($lock['content-hash'] ?? '') === '') {
                return [['path' => $file, 'type' => 'composer_lock_invalid_or_absent']];
            }
            if (! hash_equals((string) $lock['content-hash'], $this->composerManifestContentHash($manifest))) {
                return [['path' => $file, 'type' => 'composer_lock_content_hash_mismatch']];
            }
            $locked = [];
            foreach ([...(array) ($lock['packages'] ?? []), ...(array) ($lock['packages-dev'] ?? [])] as $package) {
                if (is_array($package) && is_string($package['name'] ?? null) && is_string($package['version'] ?? null) && $package['version'] !== '') {
                    $reference = data_get($package, 'dist.reference', data_get($package, 'source.reference'));
                    $locked[$package['name']] = ['version' => $package['version'], 'reference' => $reference];
                }
            }
            foreach ([...(array) ($manifest['require'] ?? []), ...(array) ($manifest['require-dev'] ?? [])] as $name => $constraint) {
                if (! is_string($name) || preg_match('/^(?:php|ext-|lib-)/', $name) === 1) {
                    continue;
                }
                if (! isset($locked[$name]) || ! is_string($constraint) || trim($constraint) === ''
                    || ! is_string($locked[$name]['reference'] ?? null) || $locked[$name]['reference'] === '') {
                    return [['path' => $file, 'type' => 'composer_locked_package_missing_or_invalid']];
                }
                $satisfies = $this->semverSatisfies((string) $locked[$name]['version'], $constraint, 'composer');
                if ($satisfies === null) {
                    return [['path' => $file, 'type' => 'composer_semver_evaluator_unavailable_or_invalid']];
                }
                if (! $satisfies) {
                    return [['path' => $file, 'type' => 'composer_locked_version_constraint_mismatch']];
                }
            }

            return [];
        }
        $lockSource = $this->support->signedTreeFile($tree, $directory.'package-lock.json', $sandbox);
        if ($lockSource === null && ($this->support->signedTreeFile($tree, $directory.'pnpm-lock.yaml', $sandbox) !== null
            || $this->support->signedTreeFile($tree, $directory.'yarn.lock', $sandbox) !== null)) {
            return [['path' => $file, 'type' => 'javascript_lock_ecosystem_unsupported']];
        }
        $lock = $lockSource === null ? null : json_decode($lockSource, true);
        if (! is_array($lock) || ! is_int($lock['lockfileVersion'] ?? null) || ! is_array($lock['packages'] ?? null)) {
            return [['path' => $file, 'type' => 'npm_lock_invalid_or_absent']];
        }
        foreach (['dependencies', 'devDependencies', 'peerDependencies', 'optionalDependencies'] as $group) {
            foreach ((array) ($manifest[$group] ?? []) as $name => $constraint) {
                $entry = $lock['packages']['node_modules/'.$name] ?? null;
                $rootPackage = $lock['packages'][''] ?? null;
                $rootConstraint = is_array($rootPackage) ? ($rootPackage[$group][$name] ?? null) : null;
                if (! is_string($name) || ! is_string($constraint) || trim($constraint) === '' || ! is_array($entry)
                    || ! is_string($entry['version'] ?? null) || $entry['version'] === ''
                    || ! is_string($entry['integrity'] ?? null) || $entry['integrity'] === ''
                    || ! is_string($rootConstraint) || ! hash_equals($constraint, $rootConstraint)) {
                    return [['path' => $file, 'type' => 'npm_locked_package_missing_or_invalid']];
                }
                $satisfies = $this->semverSatisfies((string) $entry['version'], $constraint, 'npm');
                if ($satisfies === null) {
                    return [['path' => $file, 'type' => 'npm_semver_evaluator_unavailable_or_invalid']];
                }
                if (! $satisfies) {
                    return [['path' => $file, 'type' => 'npm_locked_version_constraint_mismatch']];
                }
            }
        }

        return [];
    }
    /** @param array<string,mixed> $manifest */
    public function composerManifestContentHash(array $manifest): string
    {
        $relevant = array_intersect_key($manifest, array_flip([
            'name', 'version', 'require', 'require-dev', 'conflict', 'replace', 'provide',
            'minimum-stability', 'prefer-stable', 'repositories', 'extra',
        ]));
        $sort = function (array &$value) use (&$sort): void {
            foreach ($value as &$nested) {
                if (is_array($nested)) {
                    $sort($nested);
                }
            }
            unset($nested);
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }
        };
        $sort($relevant);

        return md5(json_encode($relevant, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
    public function semverSatisfies(string $version, string $constraint, string $ecosystem): ?bool
    {
        if (! in_array($ecosystem, ['composer', 'npm'], true)) {
            return null;
        }
        if (preg_match('/\d[-+][0-9A-Za-z]/', $version) === 1 || preg_match('/\d[-+][0-9A-Za-z]/', $constraint) === 1) {
            return false;
        }
        $normalize = static function (string $value): ?array {
            if (preg_match('/^v?(\d+)(?:\.(\d+))?(?:\.(\d+))?$/', trim($value), $match) !== 1) {
                return null;
            }

            return [(int) $match[1], (int) ($match[2] ?? 0), (int) ($match[3] ?? 0)];
        };
        $actual = $normalize($version);
        if ($actual === null || trim($constraint) === '') {
            return null;
        }
        if ($ecosystem === 'npm' && preg_match('/^v?(\d+)(?:\.(\d+))?$/', trim($constraint), $partial) === 1) {
            $prefix = [(int) $partial[1]];
            if (isset($partial[2])) {
                $prefix[] = (int) $partial[2];
            }

            return array_slice($actual, 0, count($prefix)) === $prefix;
        }
        foreach (preg_split('/\s*\|\|\s*/', trim($constraint)) ?: [] as $alternative) {
            $tokens = preg_split('/(?:\s*,\s*|\s+)/', trim($alternative), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $all = true;
            foreach ($tokens as $token) {
                if (in_array($token, ['*', 'x', 'X'], true)) {
                    continue;
                }
                if (preg_match('/^(\^|~|>=|<=|>|<|=)?\s*(v?\d+(?:\.\d+){0,2}|\d+(?:\.(?:x|X|\*)){1,2})$/', $token, $match) !== 1) {
                    return null;
                }
                $operator = $match[1];
                $targetText = $match[2];
                if (preg_match('/[xX*]/', $targetText) === 1) {
                    $prefix = array_map('intval', preg_split('/\./', preg_replace('/\.(?:x|X|\*).*$/', '', $targetText)) ?: []);
                    $all = $all && array_slice($actual, 0, count($prefix)) === $prefix;

                    continue;
                }
                $target = $normalize($targetText);
                if ($target === null) {
                    return null;
                }
                $compare = $actual <=> $target;
                $caretUpper = $target[0] > 0 ? [$target[0] + 1, 0, 0]
                    : ($target[1] > 0 ? [0, $target[1] + 1, 0] : [0, 0, $target[2] + 1]);
                $tildeUpper = substr_count($targetText, '.') === 0 ? [$target[0] + 1, 0, 0] : [$target[0], $target[1] + 1, 0];
                $matches = match ($operator) {
                    '>' => $compare > 0, '>=' => $compare >= 0, '<' => $compare < 0, '<=' => $compare <= 0,
                    '^' => $compare >= 0 && $actual < $caretUpper,
                    '~' => $compare >= 0 && $actual < $tildeUpper,
                    default => $compare === 0,
                };
                $all = $all && $matches;
            }
            if ($all) {
                return true;
            }
        }

        return false;
    }
}
