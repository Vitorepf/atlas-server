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
use App\Services\Ai\RealExecution\AtlasRealEngineeringExecutionKernelService;
use App\Services\Ai\RealExecution\RealExecutionHash;

class SurfaceApplicabilitySection
{
    public function __construct(private readonly KernelReceiptSupport $support)
    {
    }

    public function persistCandidateSurfaceApplicabilityOwnerReceipt(AiEngineeringCompanyEngagement $engagement, AiEngineeringCompanyCycle $cycle,
        CandidateQualityCase $case, string $role): AiEngineeringCompanyRoleRun
    {
        $domain = AtlasRealEngineeringExecutionKernelService::surfaceApplicabilityOwnerDomain($role);
        if (! $engagement->exists || ! $cycle->exists || $cycle->engagement_record_id !== $engagement->getKey()
            || (string) $engagement->getKey() !== $case->engagementRecordId || (string) $cycle->getKey() !== $case->cycleRecordId) {
            throw new \InvalidArgumentException('surface_applicability_owner_binding_invalid');
        }
        $evidence = $this->candidateSurfaceApplicabilityEvidence($case, $role, true);
        $disposition = $this->candidateSurfaceApplicabilityDisposition($case, $role, $evidence);
        $id = 'aeresurface_'.substr(RealExecutionHash::make([$case->caseHash, $role]), 0, 24);
        $issued = CarbonImmutable::now()->startOfSecond();
        $expires = $issued->addHour();
        $refs = ['candidate:'.$case->candidate->candidateHash, 'surface_artifact:'.(string) data_get($evidence, 'raw_artifact.sha256')];
        $typed = RoleEvidenceReceipt::issue($case, $disposition, $domain, AtlasRealEngineeringExecutionKernelService::CANDIDATE_SURFACE_APPLICABILITY_OWNER_VERSION,
            $issued->toAtomString(), $expires->toAtomString(), $refs);
        $output = ['disposition' => $disposition->toArray(), 'role_evidence_receipt' => $typed->toArray()];
        $receipt = ['schema_version' => AtlasRealEngineeringCompanyRuntimeService::ROLE_SCHEMA,
            'purpose' => 'candidate_'.$role.'_applicability_owner_evidence', 'owner_domain' => $domain,
            'owner_version' => AtlasRealEngineeringExecutionKernelService::CANDIDATE_SURFACE_APPLICABILITY_OWNER_VERSION, 'owner_identity' => AtlasRealEngineeringExecutionKernelService::class,
            'issued_at' => $issued->toAtomString(), 'expires_at' => $expires->toAtomString(), 'role_run_id' => $id, 'role_id' => $role,
            'status' => $disposition->status === 'not_applicable' ? 'not_applicable' : 'blocked', 'output' => $output,
            'evidence_refs' => $refs, 'binding' => $this->support->candidateOwnerBinding($case), 'disposition' => $disposition->toArray(),
            'surface_applicability_evidence' => $evidence];
        $receipt['producer'] = $this->support->candidateOwnerProducerSeal($receipt, $domain);
        $receipt['hash'] = EngineeringCompanyHash::make($receipt);

        return AiEngineeringCompanyRoleRun::query()->create(['engagement_record_id' => $engagement->getKey(), 'cycle_record_id' => $cycle->getKey(),
            'role_run_id' => $id, 'role_id' => $role, 'status' => $receipt['status'], 'responsibilities' => [], 'output' => $output,
            'evidence_refs' => $refs, 'receipt' => $receipt, 'role_hash' => $receipt['hash']]);
    }
    public function candidateSurfaceApplicabilityOwnerReceiptValid(AiEngineeringCompanyRoleRun $row, CandidateQualityCase $case, string $role): bool
    {
        $domain = AtlasRealEngineeringExecutionKernelService::surfaceApplicabilityOwnerDomain($role);
        $persisted = $row->exists ? AiEngineeringCompanyRoleRun::query()->find($row->getKey()) : null;
        $receipt = $persisted?->receipt;
        if (! $persisted instanceof AiEngineeringCompanyRoleRun || ! is_array($receipt) || $persisted->role_id !== $role) {
            return false;
        }
        try {
            $issued = CarbonImmutable::createFromFormat(DATE_ATOM, (string) ($receipt['issued_at'] ?? ''));
            $expires = CarbonImmutable::createFromFormat(DATE_ATOM, (string) ($receipt['expires_at'] ?? ''));
            $evidence = (array) ($receipt['surface_applicability_evidence'] ?? []);
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
            $current = $this->candidateSurfaceApplicabilityEvidence($case, $role, false);
            if (array_diff_key($current, ['raw_artifact' => true]) !== $raw) {
                return false;
            }
            $expected = $this->candidateSurfaceApplicabilityDisposition($case, $role, $current);
        } catch (\Throwable) {
            return false;
        }
        $refs = ['candidate:'.$case->candidate->candidateHash, 'surface_artifact:'.(string) data_get($evidence, 'raw_artifact.sha256')];
        $typed = RoleEvidenceReceipt::issue($case, $expected, $domain, AtlasRealEngineeringExecutionKernelService::CANDIDATE_SURFACE_APPLICABILITY_OWNER_VERSION,
            (string) $receipt['issued_at'], (string) $receipt['expires_at'], $refs)->toArray();
        $unsigned = array_diff_key($receipt, ['hash' => true]);
        $output = $persisted->output;

        return is_array($output) && $issued !== null && $expires !== null && ! CarbonImmutable::now()->lt($issued) && CarbonImmutable::now()->lt($expires)
            && ($receipt['purpose'] ?? null) === 'candidate_'.$role.'_applicability_owner_evidence' && ($receipt['owner_domain'] ?? null) === $domain
            && ($receipt['owner_version'] ?? null) === AtlasRealEngineeringExecutionKernelService::CANDIDATE_SURFACE_APPLICABILITY_OWNER_VERSION
            && ($receipt['owner_identity'] ?? null) === AtlasRealEngineeringExecutionKernelService::class && ($receipt['role_id'] ?? null) === $role
            && ($receipt['status'] ?? null) === ($expected->status === 'not_applicable' ? 'not_applicable' : 'blocked')
            && ($receipt['disposition'] ?? null) === $expected->toArray()
            && ($receipt['binding'] ?? null) === $this->support->candidateOwnerBinding($case) && ($receipt['evidence_refs'] ?? null) === $refs
            && ($receipt['output'] ?? null) === $output && ($output['disposition'] ?? null) === $expected->toArray()
            && ($output['role_evidence_receipt'] ?? null) === $typed && hash_equals((string) $persisted->role_hash, (string) ($receipt['hash'] ?? ''))
            && hash_equals((string) ($receipt['hash'] ?? ''), EngineeringCompanyHash::make($unsigned))
            && $this->support->candidateOwnerProducerSealValid($unsigned, $domain);
    }
    /** @param array<string,mixed> $evidence */
    public function candidateSurfaceApplicabilityDisposition(CandidateQualityCase $case, string $role, array $evidence): RoleDisposition
    {
        $domain = AtlasRealEngineeringExecutionKernelService::surfaceApplicabilityOwnerDomain($role);
        $na = ($evidence['not_applicable'] ?? false) === true;
        $status = $na ? 'not_applicable' : 'block';
        $reason = $na ? 'signed_no_'.$role.'_surface_applicability' : $role.'_owner_evidence_required';
        $payload = ['case_hash' => $case->caseHash, 'role' => $role, 'status' => $status, 'reason' => $reason,
            'evidence_hash' => RealExecutionHash::make($evidence)];

        return RoleDisposition::surfaceApplicabilityAdjudicated($case, $role, $status, $reason, $domain,
            $this->support->candidateOwnerSignature($payload, $domain));
    }
    /** @return array<string,mixed> */
    public function candidateSurfaceApplicabilityEvidence(CandidateQualityCase $case, string $role, bool $writeArtifact): array
    {
        AtlasRealEngineeringExecutionKernelService::surfaceApplicabilityOwnerDomain($role);
        $verification = $this->support->verifiedMutativeVerificationReceipt($case->verification, $case->order, $writeArtifact);
        $binding = (array) data_get($verification->receipt, 'binding', []);
        $identities = (array) data_get($verification->receipt, 'identities', []);
        $tree = (string) ($binding['tree_hash'] ?? '');
        $sourceHashes = (array) ($binding['source_hashes'] ?? []);
        $facts = [];
        $unknown = false;
        foreach ($case->candidate->files as $file) {
            $source = $this->support->signedTreeFile($tree, $file, $case->candidate->sandboxRoot);
            if ($source === null || ! hash_equals((string) ($sourceHashes[$file] ?? ''), hash('sha256', $source))) {
                throw new \InvalidArgumentException('surface_applicability_signed_blob_unavailable');
            }
            if (! mb_check_encoding($source, 'UTF-8')) {
                $unknown = true;
                $facts[$file] = ['unknown_encoding'];

                continue;
            }
            $facts[$file] = $this->surfaceContentSignals($source, $role);
            if (preg_match('/\b(?:package\s+main|func\s+main|fn\s+main|#include\s*[<"]|using\s+System)\b/', $source) === 1) {
                $unknown = true;
                $facts[$file][] = 'unknown_language_ecosystem';
            }
            if (! str_contains($source, '<?php') && $facts[$file] === []) {
                $unknown = true;
                $facts[$file] = ['unknown_text_ecosystem'];
            }
        }
        $declared = data_get($case->order->operatorContract, 'applicability.'.$role);
        $expected = 'no_'.$role.'_change';
        $hasSignals = array_any($facts, static fn (array $signals): bool => $signals !== []);
        $na = ! $unknown && ! $hasSignals && $declared === $expected;
        $domain = AtlasRealEngineeringExecutionKernelService::surfaceApplicabilityOwnerDomain($role);
        $identityValues = [(string) ($identities['provider'] ?? ''), (string) ($identities['author'] ?? ''),
            (string) ($identities['verifier'] ?? '')];
        $identitySeparated = ! in_array($domain, $identityValues, true) && ! in_array(AtlasRealEngineeringExecutionKernelService::class, $identityValues, true)
            && count(array_unique($identityValues)) === 3 && ! in_array('', $identityValues, true)
            && ($identities['verifier_domain'] ?? null) === AtlasRealEngineeringExecutionKernelService::KERNEL_VERIFICATION_PRODUCER;
        $na = $na && $identitySeparated;
        $raw = ['schema_version' => 'atlas.surface_applicability_evidence.v1', 'role' => $role, 'case_hash' => $case->caseHash,
            'order_hash' => $case->order->canonicalHash(), 'spec_hash' => $case->order->specHash, 'candidate_hash' => $case->candidate->candidateHash,
            'tree_hash' => $case->candidate->treeHash, 'diff_hash' => $case->candidate->diffHash, 'files' => $case->candidate->files,
            'declared_applicability' => $declared, 'facts' => $facts, 'facts_hash' => RealExecutionHash::make($facts),
            'provider_identity_hash' => hash('sha256', (string) ($identities['provider'] ?? '')),
            'author_identity_hash' => hash('sha256', (string) ($identities['author'] ?? '')),
            'verifier_identity_hash' => hash('sha256', (string) ($identities['verifier'] ?? '')),
            'provider_identity' => $identities['provider'] ?? null, 'author_identity' => $identities['author'] ?? null,
            'verifier_identity' => $identities['verifier'] ?? null,
            'verifier_domain' => $identities['verifier_domain'] ?? null, 'identity_separated' => $identitySeparated,
            'unknown_ecosystem' => $unknown, 'relevant_signals_present' => $hasSignals, 'not_applicable' => $na];
        $root = $this->support->ownedAtlasArtifactRoot($case->candidate->sandboxRoot);
        $artifact = $root.'/'.$role.'-applicability-'.$case->caseHash.'.json';
        if ($writeArtifact) {
            File::put($artifact, json_encode($raw, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        }
        $real = realpath($artifact);
        if ($real === false || ! str_starts_with($real, $root.'/') || is_link($artifact)) {
            throw new \InvalidArgumentException('surface_applicability_artifact_unavailable');
        }

        return $raw + ['raw_artifact' => ['path' => $real, 'sha256' => hash_file('sha256', $real)]];
    }
    /** @return list<string> */
    public function surfaceContentSignals(string $source, string $role): array
    {
        AtlasRealEngineeringExecutionKernelService::surfaceApplicabilityOwnerDomain($role);
        $patterns = $role === 'frontend'
            ? ['react' => '/\b(?:React|useState|useEffect|createElement)\b/', 'vue' => '/\b(?:defineComponent|createApp|v-model)\b/',
                'markup' => '/<\/?(?:[a-z][a-z0-9]*|[A-Z][A-Za-z0-9]*|[a-z][a-z0-9]*-[a-z0-9-]+)(?:\s|\/?>)/',
                'html_attribute' => '/\s(?:class|className|style|id|href|src|on[A-Z][A-Za-z]+|data-[a-z-]+)\s*=/',
                'blade' => '/(?:@(?:if|foreach|section|extends|include|component)\b|\{\{.*?\}\}|\{!!.*?!!\})/s',
                'twig' => '/(?:\{%.*?%\}|\{#.*?#\}|\{\{.*?\}\})/s',
                'css' => '/(?:@media|display\s*:\s*(?:grid|flex)|--[a-z-]+\s*:|[.#][a-z][a-z0-9_-]*\s*\{)/i',
                'template' => '/\b(?:HTMLElement|document\.querySelector|window\.)\b/']
            : ['swift' => '/\b(?:SwiftUI|UIKit|UIViewController|@main)\b/', 'android' => '/\b(?:androidx\.|Activity|Fragment|Jetpack|Composable)\b/',
                'expo' => '/\b(?:expo-router|ReactNative|react-native)\b/', 'flutter' => '/\b(?:Flutter|StatelessWidget|StatefulWidget|package:flutter)\b/'];

        return array_keys(array_filter($patterns, static fn (string $pattern): bool => preg_match($pattern, $source) === 1));
    }
}
