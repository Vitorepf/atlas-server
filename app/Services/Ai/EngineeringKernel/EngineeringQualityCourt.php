<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel;

use App\Models\AiEngineeringCompanyRoleRun;
use App\Models\AiRealExecutionTestRun;
use App\Services\Ai\EngineeringCompany\AtlasRealEngineeringCompanyRuntimeService;
use App\Services\Ai\EngineeringCompany\EngineeringCompanyHash;
use App\Services\Ai\RealExecution\AtlasRealEngineeringExecutionKernelService;
use App\Services\Ai\RealExecution\RealExecutionHash;
use InvalidArgumentException;

final class EngineeringQualityCourt
{
    public const VERIFIER_DOMAIN = 'atlas.engineering_kernel.read_only_quality_court.v1';

    public const MUTATIVE_ABSENCE_DOMAIN = 'atlas.engineering_kernel.mutative_owner_absence_court.v1';

    private const CORE_ROLES = ['qa_testing', 'evidence_audit', 'final_certification'];

    private const NOT_APPLICABLE_RULES = [
        'product_strategy' => 'no_product_behavior_change', 'product_management' => 'no_delivery_scope_change',
        'domain_research' => 'no_domain_assumption_change', 'ux_research' => 'no_user_experience_change',
        'interaction_design' => 'no_interaction_change', 'visual_design' => 'no_visual_change',
        'architecture' => 'no_architecture_change', 'backend' => 'no_backend_change', 'frontend' => 'no_frontend_change',
        'mobile' => 'no_mobile_change', 'data' => 'no_data_or_schema_change', 'appsec_privacy' => 'no_mutation_security_applicability_scan',
        'performance_resilience' => 'no_runtime_path_change', 'devops_sre' => 'no_infrastructure_change',
        'observability' => 'no_observable_runtime_change', 'release' => 'release_policy_none_read_only',
        'documentation_dx' => 'no_public_contract_or_dx_change', 'maintenance_simplification' => 'no_code_change',
        'outcome_analysis' => 'completed_read_only_has_no_production_outcome',
    ];

    /** @return array<string,mixed> */
    public function adjudicateRole(ExecutionOrder $order, AiRealExecutionTestRun $testRun, string $role): array
    {
        return $this->adjudicateReadOnlyRole($order, $testRun, $role);
    }

    public function adjudicateMutativeRole(CandidateQualityCase $case, string $role): RoleDisposition
    {
        if ($role === 'final_certification' || ! in_array($role, EngineeringRoleRoster::OFFICIAL_ROLES, true)) {
            throw new InvalidArgumentException('mutative_quality_role_invalid');
        }
        if ($role === 'qa_testing') {
            $rows = AiEngineeringCompanyRoleRun::query()
                ->where('engagement_record_id', $case->engagementRecordId)
                ->where('cycle_record_id', $case->cycleRecordId)
                ->where('role_id', 'qa_testing')->get()
                ->filter(static fn (AiEngineeringCompanyRoleRun $row): bool => data_get($row->receipt, 'binding.case_hash') === $case->caseHash);
            $owner = $rows->count() === 1 ? $rows->first() : null;
            if ($owner instanceof AiEngineeringCompanyRoleRun
                && app(AtlasRealEngineeringExecutionKernelService::class)->candidateQaOwnerReceiptValid($owner, $case)) {
                return app(AtlasRealEngineeringExecutionKernelService::class)->candidateQaDisposition($case);
            }
        }
        if ($role === 'architecture') {
            $rows = AiEngineeringCompanyRoleRun::query()
                ->where('engagement_record_id', $case->engagementRecordId)
                ->where('cycle_record_id', $case->cycleRecordId)
                ->where('role_id', 'architecture')->get()
                ->filter(static fn (AiEngineeringCompanyRoleRun $row): bool => data_get($row->receipt, 'binding.case_hash') === $case->caseHash);
            $owner = $rows->count() === 1 ? $rows->first() : null;
            if ($owner instanceof AiEngineeringCompanyRoleRun
                && app(AtlasRealEngineeringExecutionKernelService::class)->candidateArchitectureOwnerReceiptValid($owner, $case)) {
                $evidence = (array) data_get($owner->receipt, 'architecture_evidence', []);

                return app(AtlasRealEngineeringExecutionKernelService::class)->candidateArchitectureDisposition($case, $evidence);
            }
        }
        if ($role === 'data') {
            $rows = AiEngineeringCompanyRoleRun::query()->where('engagement_record_id', $case->engagementRecordId)
                ->where('cycle_record_id', $case->cycleRecordId)->where('role_id', 'data')->get()
                ->filter(static fn (AiEngineeringCompanyRoleRun $row): bool => data_get($row->receipt, 'binding.case_hash') === $case->caseHash);
            $owner = $rows->count() === 1 ? $rows->first() : null;
            if ($owner instanceof AiEngineeringCompanyRoleRun
                && app(AtlasRealEngineeringExecutionKernelService::class)->candidateDataOwnerReceiptValid($owner, $case)) {
                return app(AtlasRealEngineeringExecutionKernelService::class)->candidateDataDisposition(
                    $case, (array) data_get($owner->receipt, 'data_evidence', []),
                );
            }
        }
        if ($role === 'appsec_privacy') {
            $rows = AiEngineeringCompanyRoleRun::query()->where('engagement_record_id', $case->engagementRecordId)
                ->where('cycle_record_id', $case->cycleRecordId)->where('role_id', 'appsec_privacy')->get()
                ->filter(static fn (AiEngineeringCompanyRoleRun $row): bool => data_get($row->receipt, 'binding.case_hash') === $case->caseHash);
            $owner = $rows->count() === 1 ? $rows->first() : null;
            if ($owner instanceof AiEngineeringCompanyRoleRun
                && app(AtlasRealEngineeringExecutionKernelService::class)->candidateAppsecPrivacyOwnerReceiptValid($owner, $case)) {
                return app(AtlasRealEngineeringExecutionKernelService::class)->candidateAppsecPrivacyDisposition(
                    $case, (array) data_get($owner->receipt, 'appsec_privacy_evidence', []),
                );
            }
        }
        $payload = $this->mutativeAbsencePayload($case, $role);

        return RoleDisposition::ownerEvidenceAbsent(
            $case, $role, self::MUTATIVE_ABSENCE_DOMAIN,
            $this->sign(self::MUTATIVE_ABSENCE_DOMAIN, $payload),
        );
    }

    /** @param array<string,mixed> $disposition */
    public function mutativeDispositionValid(CandidateQualityCase $case, string $role, array $disposition): bool
    {
        try {
            return $role !== 'final_certification'
                && $this->adjudicateMutativeRole($case, $role)->toArray() === $disposition;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string,mixed> */
    private function adjudicateReadOnlyRole(ExecutionOrder $order, AiRealExecutionTestRun $testRun, string $role): array
    {
        if ($role === 'final_certification') {
            throw new InvalidArgumentException('final_certification_requires_separate_owner');
        }
        $test = $this->verifiedTestOwner($order, $testRun);
        if (! in_array($role, EngineeringRoleRoster::OFFICIAL_ROLES, true)) {
            throw new InvalidArgumentException('read_only_court_role_invalid');
        }
        $status = in_array($role, self::CORE_ROLES, true) ? 'pass' : 'not_applicable';
        $reason = $status === 'pass' ? 'independent_read_only_evidence_verified' : (self::NOT_APPLICABLE_RULES[$role] ?? 'missing_role_applicability_rule');
        if ($reason === 'missing_role_applicability_rule') {
            throw new InvalidArgumentException('read_only_court_applicability_rule_missing');
        }
        $probeFacts = ['authority_read_only' => ($order->authorityEnvelope['kind'] ?? null) === 'read_only',
            'mutation_forbidden' => ($order->toolPermissions['mutate'] ?? null) === false,
            'release_none' => ($order->releasePolicy['kind'] ?? null) === 'none_read_only',
            'provider_none' => ($order->providerRoute['provider'] ?? null) === 'none',
            'explicit_role_policy' => data_get($order->operatorContract, 'applicability.'.$role) === $reason,
            'scope_read_only_docs' => array_reduce($order->allowedScope, static fn (bool $ok, string $path): bool => $ok && (str_ends_with($path, '.md') || str_starts_with($path, 'docs/')), true)];
        if ($status === 'not_applicable' && in_array(false, $probeFacts, true)) {
            $status = 'block';
            $reason = 'insufficient_applicability_evidence';
        }
        $payload = ['role' => $role, 'status' => $status, 'reason' => $reason,
            'order_hash' => $order->canonicalHash(), 'evidence_hash' => (string) $test->test_hash,
            'justification' => $reason, 'applicability_rule' => $status === 'pass' ? 'core_read_only_court_role' : $reason,
            'probe_facts' => $probeFacts];
        $domain = self::VERIFIER_DOMAIN;
        $payload['signer_context'] = $domain;
        $payload['signature'] = $this->sign($domain, $payload);

        return $payload;
    }

    /** @return array<string,string> */
    private function mutativeAbsencePayload(CandidateQualityCase $case, string $role): array
    {
        return [
            'purpose' => 'mutative_owner_evidence_absence',
            'role' => $role, 'status' => 'block', 'reason' => 'owner_evidence_absent',
            'order_hash' => $case->order->canonicalHash(), 'spec_hash' => $case->order->specHash,
            'candidate_hash' => $case->candidate->candidateHash, 'diff_hash' => $case->candidate->diffHash,
            'tree_hash' => $case->candidate->treeHash, 'case_hash' => $case->caseHash,
            'signer_context' => self::MUTATIVE_ABSENCE_DOMAIN,
        ];
    }

    /** @param array<string,mixed> $disposition */
    public function dispositionValid(ExecutionOrder $order, AiRealExecutionTestRun $testRun, string $role, array $disposition): bool
    {
        if ($role === 'final_certification') {
            return app(EngineeringFinalCertifier::class)->dispositionValid($order, $testRun, $disposition);
        }
        try {
            return $this->adjudicateRole($order, $testRun, $role) === $disposition;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param list<AiEngineeringCompanyRoleRun> $prior */
    public function priorReceiptsValid(ExecutionOrder $order, AiRealExecutionTestRun $test, array $prior): bool
    {
        if (count($prior) !== 21) {
            return false;
        }
        $roles = [];
        foreach ($prior as $run) {
            $disposition = data_get($run->output, 'disposition');
            if (! is_array($disposition) || $run->role_id === 'final_certification' || ! $this->roleReceiptValid($order, $test, $run, $disposition)
                || ($disposition['status'] ?? null) === 'block'
                || ! $this->dispositionValid($order, $test, (string) $run->role_id, $disposition)) {
                return false;
            }
            $roles[] = $run->role_id;
        }

        return $roles === array_values(array_filter(EngineeringRoleRoster::OFFICIAL_ROLES, static fn (string $role): bool => $role !== 'final_certification'));
    }

    /** @param list<AiEngineeringCompanyRoleRun> $roleRuns */
    public function buildBundle(ExecutionOrder $order, AiRealExecutionTestRun $testRun, array $roleRuns): AcceptanceBundle
    {
        $test = $this->verifiedTestOwner($order, $testRun);
        $byRole = [];
        foreach ($roleRuns as $candidate) {
            $run = $candidate->exists ? AiEngineeringCompanyRoleRun::query()->find($candidate->getKey()) : null;
            $role = (string) $run?->role_id;
            $disposition = data_get($run?->output, 'disposition');
            if (! $run instanceof AiEngineeringCompanyRoleRun || isset($byRole[$role]) || ! is_array($disposition)
                || ! $this->roleReceiptValid($order, $test, $run, $disposition)
                || ! $this->dispositionValid($order, $test, $role, $disposition)) {
                throw new InvalidArgumentException('read_only_court_role_receipt_invalid');
            }
            $byRole[$role] = $disposition;
        }
        if (array_keys($byRole) !== EngineeringRoleRoster::OFFICIAL_ROLES) {
            throw new InvalidArgumentException('read_only_court_role_roster_incomplete');
        }

        $execution = (array) data_get($test->receipt, 'acceptance_bundle.execution', []);
        $verifiedFacts = array_sum(array_map(static fn (array $receipt): int => count(array_filter((array) ($receipt['probe_facts'] ?? []))), $byRole));
        $totalFacts = array_sum(array_map(static fn (array $receipt): int => count((array) ($receipt['probe_facts'] ?? [])), $byRole));
        $contextScore = $totalFacts > 0 ? (int) floor(($verifiedFacts / $totalFacts) * 100) : 0;

        return AcceptanceBundle::fromArray([
            'criteria_hash' => $order->specHash, 'frozen_hash' => $order->specHash,
            'changed_files' => [], 'changed_public_symbols' => [], 'execution' => $execution,
            'mutation_report' => ['applicability' => $byRole['maintenance_simplification']],
            'security_scan' => ['ran' => false, 'applicability' => $byRole['appsec_privacy']],
            'judges' => [],
            'context_sufficiency' => $contextScore,
            'non_functional' => ['performance_budget' => ['applicability' => $byRole['performance_resilience']],
                'migration_safety' => ['applicability' => $byRole['data']],
                'architecture_no_regression' => ['applicability' => $byRole['architecture']],
                'property_clean_for_tagged' => ['applicability' => $byRole['appsec_privacy']],
                'judge_diversity' => ['deterministic_courts' => [$byRole['evidence_audit'], $byRole['final_certification']]]],
            'criteria' => [], 'repair' => [],
        ]);
    }

    private function verifiedTestOwner(ExecutionOrder $order, AiRealExecutionTestRun $testRun): AiRealExecutionTestRun
    {
        $test = $testRun->exists ? AiRealExecutionTestRun::query()->find($testRun->getKey()) : null;
        $receipt = $test?->getAttribute('receipt');
        $frozen = is_array($receipt) ? ($receipt['frozen_order'] ?? null) : null;
        $producerDomain = data_get($receipt, 'producer.domain');
        $junitPath = data_get($receipt, 'junit_artifact.path');
        $junitHash = data_get($receipt, 'junit_artifact.sha256');
        if (! $test instanceof AiRealExecutionTestRun || ! is_array($receipt) || ! is_array($frozen)
            || ExecutionOrder::fromArray($frozen)->canonicalHash() !== $order->canonicalHash()
            || $producerDomain !== AtlasRealEngineeringExecutionKernelService::KERNEL_VERIFICATION_PRODUCER
            || ! is_string($junitPath) || ! is_file($junitPath) || ! is_string($junitHash)
            || ! hash_equals($junitHash, (string) hash_file('sha256', $junitPath))) {
            throw new InvalidArgumentException('read_only_court_test_owner_invalid');
        }
        $unsigned = $receipt;
        $hash = (string) $unsigned['hash'];
        unset($unsigned['hash']);
        if (! hash_equals((string) $test->test_hash, $hash) || ! hash_equals($hash, RealExecutionHash::make($unsigned))) {
            throw new InvalidArgumentException('read_only_court_test_hash_invalid');
        }
        $producer = $unsigned['producer'];
        unset($unsigned['producer']);
        if (! is_array($producer) || ! hash_equals((string) ($producer['payload_hash'] ?? ''), RealExecutionHash::make($unsigned))) {
            throw new InvalidArgumentException('read_only_court_test_producer_invalid');
        }
        $signature = (string) ($producer['signature'] ?? '');
        unset($producer['signature']);
        $authorityKey = hash_hmac('sha256', 'atlas.engineering_kernel.evidence_authority.v1', $this->keyMaterial(), true);
        $producerKey = hash_hmac('sha256', AtlasRealEngineeringExecutionKernelService::KERNEL_VERIFICATION_PRODUCER, $authorityKey, true);
        if (! hash_equals($signature, hash_hmac('sha256', RealExecutionHash::make($producer), $producerKey))) {
            throw new InvalidArgumentException('read_only_court_test_producer_invalid');
        }

        return $test;
    }

    /** @param array<string,mixed> $disposition */
    private function roleReceiptValid(ExecutionOrder $order, AiRealExecutionTestRun $test, AiEngineeringCompanyRoleRun $run, array $disposition): bool
    {
        $receipt = $run->receipt;
        if (! is_array($receipt) || ($receipt['binding']['order_hash'] ?? null) !== $order->canonicalHash()
            || ($receipt['evidence_refs'] ?? null) !== ['verification:'.$test->test_hash]
            || ($receipt['output']['disposition'] ?? null) !== $disposition
            || ($receipt['status'] ?? null) !== $run->status) {
            return false;
        }
        $unsigned = $receipt;
        $hash = (string) ($unsigned['hash'] ?? '');
        unset($unsigned['hash']);
        if (! hash_equals((string) $run->role_hash, $hash) || ! hash_equals($hash, EngineeringCompanyHash::make($unsigned))) {
            return false;
        }
        $producer = $unsigned['producer'] ?? null;
        if (! is_array($producer) || ($producer['domain'] ?? null) !== AtlasRealEngineeringCompanyRuntimeService::QUALITY_ROLE_PRODUCER) {
            return false;
        }
        unset($unsigned['producer']);
        if (! hash_equals((string) ($producer['payload_hash'] ?? ''), EngineeringCompanyHash::make($unsigned))) {
            return false;
        }
        $signature = (string) ($producer['signature'] ?? '');
        unset($producer['signature']);
        $authorityKey = hash_hmac('sha256', 'atlas.engineering_kernel.evidence_authority.v1', $this->keyMaterial(), true);
        $producerKey = hash_hmac('sha256', AtlasRealEngineeringCompanyRuntimeService::QUALITY_ROLE_PRODUCER, $authorityKey, true);

        return hash_equals($signature, hash_hmac('sha256', EngineeringCompanyHash::make($producer), $producerKey));
    }

    /** @param array<string,mixed> $payload */
    private function sign(string $domain, array $payload): string
    {
        return hash_hmac('sha256', CanonicalKernelPayload::hash($payload), hash_hmac('sha256', $domain, $this->keyMaterial(), true));
    }

    private function keyMaterial(): string
    {
        $key = (string) config('app.key');
        $decoded = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;

        return is_string($decoded) ? $decoded : '';
    }
}
