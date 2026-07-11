<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel;

use App\Models\AiEngineeringCompanyRoleRun;
use App\Models\AiRealExecutionPatchRun;
use App\Models\AiRealExecutionTestRun;
use App\Models\AtlasLedgerEvent;
use App\Services\Ai\EngineeringCompany\AtlasRealEngineeringCompanyRuntimeService;
use App\Services\Ai\EngineeringCompany\EngineeringCompanyHash;
use App\Services\Ai\Kernel\Decision\DecisionReceipt;
use App\Services\Ai\Kernel\Decision\DecisionReceiptRuntimeGuard;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\RealExecution\AtlasRealEngineeringExecutionKernelService;
use App\Services\Ai\RealExecution\RealExecutionHash;
use App\Services\Ai\SelfConstruction\MergeGovernor\AtlasMergeGovernorAdmissionPolicy;
use App\Services\Ai\SelfConstruction\MergeGovernor\AtlasMergeGovernorReleaseDecisionLedger;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class KernelEvidenceAuthority
{
    public const EMITTER_STAGE = 'atlas.engineering_kernel.evidence_authority';

    public const SCHEMA = 'atlas.engineering_kernel.evidence_authority.v1';

    public function __construct(private readonly AtlasEvidenceLedger $ledger, private readonly DecisionReceiptRuntimeGuard $decisionGuard) {}

    /** @param array<string,mixed> $context */
    public function issueDecision(DecisionReceipt $receipt, ExecutionOrder $order, array $context): AtlasLedgerEvent
    {
        if ($this->decisionGuard->violationForReceipt(['receipt_v2' => $receipt->toArray()]) !== null) {
            throw new InvalidArgumentException('kernel_decision_receipt_guard_refused');
        }
        $expectedMetadata = [
            'delivery_id' => $order->deliveryId, 'order_hash' => $order->canonicalHash(),
            'spec_hash' => $order->specHash, 'roster_hash' => CanonicalKernelPayload::hash($order->roleRoster),
            'mode' => $order->mode,
        ];
        $flow = ['dev' => 'atlas.dev', 'forge' => 'atlas.forge', 'autonomos' => 'atlas.autonomos'][$order->mode];
        $risk = in_array($order->riskClass, ['R0', 'R1'], true) ? 'low' : (in_array($order->riskClass, ['R2', 'R3'], true) ? 'medium' : ($order->riskClass === 'R4' ? 'high' : 'critical'));
        if ($receipt->envelopeId !== $order->runId || $receipt->flow !== $flow || $receipt->risk !== $risk
            || array_intersect_key($receipt->metadata, $expectedMetadata) !== $expectedMetadata) {
            throw new InvalidArgumentException('kernel_decision_receipt_order_binding_invalid');
        }

        return $this->issue('decision', LedgerEventType::DecisionIssued, $this->bindingPayload($order) + [
            'event_name' => 'decision.issued',
            'authority_hash' => CanonicalKernelPayload::hash($order->authorityEnvelope),
            'role_roster' => $order->roleRoster,
            'decision_receipt_hash' => $receipt->receiptHash,
        ], $context);
    }

    /** @param array<string,mixed> $context */
    /** @param list<AiEngineeringCompanyRoleRun> $roleRuns @param array<string,mixed> $context */
    public function issueEvidenceBundle(AiRealExecutionTestRun $testRun, array $roleRuns, ExecutionOrder $order, array $context): AtlasLedgerEvent
    {
        $persisted = $this->persistedTestRun($testRun, $order);
        $derived = $this->bundleArray(app(ReadOnlyQualityCourt::class)->buildBundle($order, $persisted, $roleRuns));

        return $this->issue('evidence_bundle', LedgerEventType::EvidencePacked, $this->bindingPayload($order) + [
            'event_name' => 'evidence.bundle.recorded',
            'acceptance_bundle' => $derived,
            'test_run_id' => $persisted->test_run_id, 'test_hash' => $persisted->test_hash,
        ], $context);
    }

    /** @param array<string,mixed> $context */
    public function issueRoleDisposition(AiEngineeringCompanyRoleRun $roleRun, ExecutionOrder $order, array $context): AtlasLedgerEvent
    {
        if (! $roleRun->exists || $roleRun->getKey() === null) {
            throw new InvalidArgumentException('kernel_role_run_not_persisted');
        }
        $roleRun = AiEngineeringCompanyRoleRun::query()->find($roleRun->getKey());
        if (! $roleRun instanceof AiEngineeringCompanyRoleRun) {
            throw new InvalidArgumentException('kernel_role_run_not_persisted');
        }
        $role = (string) $roleRun->getAttribute('role_id');
        $roleHash = (string) $roleRun->getAttribute('role_hash');
        $evidenceRefs = $roleRun->getAttribute('evidence_refs');
        $output = $roleRun->getAttribute('output');
        $receipt = $roleRun->getAttribute('receipt');
        $expectedBinding = ['run_id' => $order->runId, 'delivery_id' => $order->deliveryId,
            'order_hash' => $order->canonicalHash(), 'spec_hash' => $order->specHash,
            'engagement_record_id' => (string) $roleRun->engagement_record_id,
            'cycle_record_id' => (string) $roleRun->cycle_record_id];
        if (! in_array($role, EngineeringRoleRoster::OFFICIAL_ROLES, true)
            || preg_match('/^[a-f0-9]{64}$/', $roleHash) !== 1 || ! is_array($evidenceRefs) || $evidenceRefs === []
            || ! is_array($output) || ! is_array($output['disposition'] ?? null) || ! is_array($receipt)) {
            throw new InvalidArgumentException('kernel_role_run_invalid');
        }
        $unsigned = $receipt;
        $receiptHash = (string) ($unsigned['hash'] ?? '');
        unset($unsigned['hash']);
        $verificationHash = is_string($evidenceRefs[0] ?? null) && str_starts_with($evidenceRefs[0], 'verification:') ? substr($evidenceRefs[0], 13) : '';
        $verification = AiRealExecutionTestRun::query()->where('test_hash', $verificationHash)->first();
        if (! hash_equals($roleHash, $receiptHash) || ! hash_equals($roleHash, EngineeringCompanyHash::make($unsigned))
            || ($unsigned['role_id'] ?? null) !== $role || ($unsigned['status'] ?? null) !== $roleRun->status
            || ($unsigned['evidence_refs'] ?? null) !== $evidenceRefs || ($unsigned['output'] ?? null) !== $output
            || ($unsigned['binding'] ?? null) !== $expectedBinding
            || ($unsigned['disposition'] ?? null) !== $output['disposition']
            || ! $verification instanceof AiRealExecutionTestRun
            || ! app(ReadOnlyQualityCourt::class)->dispositionValid($order, $verification, $role, $output['disposition'])
            || ! $this->producerSealValid($unsigned, AtlasRealEngineeringCompanyRuntimeService::QUALITY_ROLE_PRODUCER, false)) {
            throw new InvalidArgumentException('kernel_role_run_receipt_binding_invalid');
        }

        return $this->issue('role_disposition', LedgerEventType::GateEvaluated, $this->bindingPayload($order) + [
            'event_name' => 'role.disposition.recorded', 'role' => $role,
            'role_hash' => $roleHash, 'evidence_refs' => $evidenceRefs, 'disposition' => $output['disposition'],
        ], $context);
    }

    /** @param array<string,mixed> $context */
    public function issueAcceptanceVerdict(CertVerdict $verdict, ExecutionOrder $order, AtlasLedgerEvent $evidenceEvent, array $context): AtlasLedgerEvent
    {
        return $this->issue('acceptance', LedgerEventType::GateEvaluated, $this->bindingPayload($order) + [
            'event_name' => 'acceptance.adjudicated', 'verdict' => $verdict->toArray(),
            'evidence_event_id' => $evidenceEvent->event_id,
            'evidence_event_hash' => (string) $evidenceEvent->getAttribute('event_hash'),
        ], $context);
    }

    public function issueReleaseAuthorization(CanonicalReleaseAuthorizationRequest $request): AtlasLedgerEvent
    {
        $row = null;
        foreach ((new AtlasMergeGovernorReleaseDecisionLedger($request->releaseLedgerPath))->all() as $candidate) {
            if (hash_equals((string) ($candidate['decision_hash'] ?? ''), $request->decisionHash)) {
                $row = $candidate;
                break;
            }
        }
        if (! is_array($row)
            || ($row['decision'] ?? null) !== AtlasMergeGovernorAdmissionPolicy::DECISION_ADMITTED
            || ! hash_equals((string) ($row['decision_hash'] ?? ''), $request->decisionHash)
            || ! str_starts_with((string) ($row['rollback_posture'] ?? ''), 'revertible:')) {
            throw new InvalidArgumentException('release_authorization_governor_decision_not_persisted_admitted');
        }

        return $this->issue('release_authorization', LedgerEventType::ReleaseAuthorized, [
            'event_name' => 'release.authorized',
            'task_packet_id' => (string) ($row['task_packet_id'] ?? ''),
            'action' => 'commit',
            'candidate_hash' => (string) ($row['candidate_hash'] ?? ''),
            'decision_hash' => (string) ($row['decision_hash'] ?? ''),
            'verification_hash' => (string) ($row['verification_hash'] ?? ''),
            'rollback_hash' => (string) ($row['rollback_hash'] ?? ''),
            'rollback_posture' => (string) ($row['rollback_posture'] ?? ''),
            'changed_files' => $request->files,
            'scope_hash' => $request->scopeHash,
            'base_commit' => $request->baseCommit,
            'tree_hash' => $request->treeHash,
            'lease_id' => $request->leaseId,
            'lease_owner' => $request->leaseOwner,
            'fencing_token' => $request->fencingToken,
            'nonce' => $request->nonce,
            'issued_at' => $request->issuedAt,
            'expires_at' => $request->expiresAt,
        ], $request->context, 300);
    }

    public function verifyReleaseAuthorization(AtlasLedgerEvent $event): bool
    {
        return $this->verifyEvent($event, 'release_authorization');
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $context */
    private function issue(string $kind, LedgerEventType $type, array $payload, array $context, int $validForSeconds = 3600): AtlasLedgerEvent
    {
        $this->assertAllowed($kind, $type);
        $now = CarbonImmutable::now()->startOfSecond();
        $payload['_authority'] = $this->seal($kind, $payload, $now, $now->addSeconds($validForSeconds));
        $event = $this->ledger->record($type, $payload, array_replace($context, [
            'emitter_stage' => self::EMITTER_STAGE,
            'emitter_version' => self::SCHEMA,
        ]));
        if ($event === null) {
            throw new \RuntimeException('kernel_evidence_authority_ledger_unavailable');
        }

        return $event;
    }

    public function verifyEvent(AtlasLedgerEvent $event, string $kind): bool
    {
        $expectedType = match ($kind) {
            'decision' => LedgerEventType::DecisionIssued,
            'evidence_bundle' => LedgerEventType::EvidencePacked,
            'acceptance', 'role_disposition' => LedgerEventType::GateEvaluated,
            'release_authorization' => LedgerEventType::ReleaseAuthorized,
            default => null,
        };
        if ($expectedType === null || $event->event_type !== $expectedType->value
            || $event->emitter_stage !== self::EMITTER_STAGE
            || $event->emitter_version !== self::SCHEMA
            || ! $this->ledger->eventIntegrityValid($event)) {
            return false;
        }
        $payload = $event->getAttribute('payload');
        if (! is_array($payload) || ! is_array($payload['_authority'] ?? null)) {
            return false;
        }
        $authority = $payload['_authority'];
        unset($payload['_authority']);

        return $this->verifySeal($authority, $kind, $payload);
    }

    /** @param array<string,mixed> $outcome @return array<string,mixed> */
    public function sealOutcome(array $outcome): array
    {
        $now = CarbonImmutable::now()->startOfSecond();

        return $this->seal('engineering_outcome', $this->outcomePayload($outcome), $now, null);
    }

    /** @param array<string,mixed> $outcome */
    public function verifyOutcome(array $outcome): bool
    {
        $authority = data_get($outcome, 'evidence_bundle.authority');

        return is_array($authority) && $this->verifySeal($authority, 'engineering_outcome', $this->outcomePayload($outcome));
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function seal(string $kind, array $payload, CarbonImmutable $issuedAt, ?CarbonImmutable $expiresAt): array
    {
        $envelope = [
            'schema_version' => self::SCHEMA,
            'kind' => $kind,
            'issued_at' => $issuedAt->format(DATE_ATOM),
            'expires_at' => $expiresAt?->format(DATE_ATOM),
            'provenance' => 'kernel_evidence_authority',
            'key_id' => $this->currentKeyId(),
            'payload_hash' => CanonicalKernelPayload::hash($payload),
        ];
        $envelope['signature'] = hash_hmac('sha256', CanonicalKernelPayload::hash($envelope), $this->keyring()[$this->currentKeyId()]);

        return $envelope;
    }

    /** @param array<string,mixed> $authority @param array<string,mixed> $payload */
    private function verifySeal(array $authority, string $kind, array $payload): bool
    {
        try {
            $issued = CarbonImmutable::createFromFormat(DATE_ATOM, CanonicalKernelPayload::requireString($authority, 'issued_at'));
            $expiresRaw = $authority['expires_at'] ?? null;
            $expires = is_string($expiresRaw) ? CarbonImmutable::createFromFormat(DATE_ATOM, $expiresRaw) : null;
            $signature = CanonicalKernelPayload::requireHash($authority, 'signature');
        } catch (InvalidArgumentException) {
            return false;
        }
        if ($issued === null || CarbonImmutable::now()->lt($issued)
            || ($kind !== 'engineering_outcome' && ($expires === null || CarbonImmutable::now()->gte($expires)))
            || ($kind === 'engineering_outcome' && $expiresRaw !== null)
            || ($authority['schema_version'] ?? null) !== self::SCHEMA || ($authority['kind'] ?? null) !== $kind
            || ($authority['provenance'] ?? null) !== 'kernel_evidence_authority'
            || ! hash_equals((string) ($authority['payload_hash'] ?? ''), CanonicalKernelPayload::hash($payload))) {
            return false;
        }
        $unsigned = $authority;
        unset($unsigned['signature']);

        $keyId = $authority['key_id'] ?? null;
        $key = is_string($keyId) ? $this->keyring()[$keyId] ?? null : null;

        return is_string($key) && hash_equals($signature, hash_hmac('sha256', CanonicalKernelPayload::hash($unsigned), $key));
    }

    /** @return array<string,string> */
    private function keyring(): array
    {
        $appKey = $this->applicationKey((string) config('app.key'));
        if ($appKey === '') {
            throw new \RuntimeException('kernel_evidence_authority_app_key_missing');
        }

        $keys = [$this->currentKeyId() => hash_hmac('sha256', 'atlas.engineering_kernel.evidence_authority.v1', $appKey, true)];
        foreach ((array) config('atlas.engineering_kernel.evidence_authority.previous_keys', []) as $id => $key) {
            if (is_string($id) && $id !== '' && is_string($key) && $key !== '') {
                $keys[$id] = hash_hmac('sha256', 'atlas.engineering_kernel.evidence_authority.v1', $this->applicationKey($key), true);
            }
        }

        return $keys;
    }

    private function currentKeyId(): string
    {
        return 'app-key-'.substr(hash('sha256', $this->applicationKey((string) config('app.key'))), 0, 16);
    }

    private function applicationKey(string $key): string
    {
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            return $decoded === false ? '' : $decoded;
        }

        return $key;
    }

    private function assertAllowed(string $kind, LedgerEventType $type): void
    {
        $allowed = ($kind === 'decision' && $type === LedgerEventType::DecisionIssued)
            || ($kind === 'evidence_bundle' && $type === LedgerEventType::EvidencePacked)
            || (in_array($kind, ['acceptance', 'role_disposition'], true) && $type === LedgerEventType::GateEvaluated);
        $allowed = $allowed || ($kind === 'release_authorization' && $type === LedgerEventType::ReleaseAuthorized);
        if (! $allowed) {
            throw new InvalidArgumentException('kernel_evidence_authority_kind_type_forbidden');
        }
    }

    /** @param array<string,mixed> $outcome @return array<string,mixed> */
    private function outcomePayload(array $outcome): array
    {
        unset($outcome['outcome_hash'], $outcome['evidence_bundle']['authority']);

        return $outcome;
    }

    /** @return array<string,mixed> */
    private function bindingPayload(ExecutionOrder $order): array
    {
        return [
            'delivery_id' => $order->deliveryId, 'order_hash' => $order->canonicalHash(),
            'spec_hash' => $order->specHash,
            'role_roster_catalog_hash' => CanonicalKernelPayload::hash($order->roleRoster),
        ];
    }

    /** @return array<string,mixed> */
    private function bundleArray(AcceptanceBundle $bundle): array
    {
        return [
            'criteria_hash' => $bundle->criteriaHash, 'frozen_hash' => $bundle->frozenHash,
            'changed_files' => $bundle->changedFiles, 'changed_public_symbols' => $bundle->changedPublicSymbols,
            'execution' => ['commands' => $bundle->execution->commands, 'claimed_status' => $bundle->execution->claimedStatus, 'tests_run' => $bundle->execution->testsRun, 'assertions_executed' => $bundle->execution->assertionsExecuted, 'selected_tests' => $bundle->execution->selectedTests, 'artifacts' => $bundle->execution->artifacts],
            'mutation_report' => $bundle->mutationReport, 'security_scan' => $bundle->securityScan,
            'judges' => $bundle->judges, 'context_sufficiency' => $bundle->contextSufficiency,
            'non_functional' => $bundle->nonFunctional, 'criteria' => $bundle->criteria, 'repair' => $bundle->repair,
        ];
    }

    private function persistedTestRun(AiRealExecutionTestRun $testRun, ExecutionOrder $order): AiRealExecutionTestRun
    {
        if (! $testRun->exists || $testRun->getKey() === null) {
            throw new InvalidArgumentException('kernel_test_run_not_persisted');
        }
        $persisted = AiRealExecutionTestRun::query()->find($testRun->getKey());
        $receipt = $persisted?->receipt;
        if (! $persisted instanceof AiRealExecutionTestRun || ! is_array($receipt)) {
            throw new InvalidArgumentException('kernel_test_run_not_persisted');
        }
        $unsigned = $receipt;
        $hash = (string) ($unsigned['hash'] ?? '');
        unset($unsigned['hash']);
        $binding = ['run_id' => $order->runId, 'delivery_id' => $order->deliveryId,
            'order_hash' => $order->canonicalHash(), 'spec_hash' => $order->specHash];
        $patch = AiRealExecutionPatchRun::query()->find($persisted->patch_run_record_id);
        $junitPath = data_get($unsigned, 'junit_artifact.path');
        $junitHash = data_get($unsigned, 'junit_artifact.sha256');
        if ($persisted->status !== 'passed' || $persisted->exit_code !== 0
            || ! hash_equals((string) $persisted->test_hash, $hash)
            || ! hash_equals($hash, RealExecutionHash::make($unsigned))
            || ($unsigned['binding'] ?? null) !== $binding
            || ($unsigned['test_run_id'] ?? null) !== $persisted->test_run_id
            || ($unsigned['status'] ?? null) !== $persisted->status
            || ($unsigned['selected_tests'] ?? null) !== $persisted->selected_tests
            || ($unsigned['evidence_refs'] ?? null) !== $persisted->evidence_refs
            || ($unsigned['goal_record_id'] ?? null) !== (string) $persisted->goal_record_id
            || ($unsigned['patch_run_record_id'] ?? null) !== (string) $persisted->patch_run_record_id
            || $persisted->goal_record_id === null || $persisted->patch_run_record_id === null
            || ! $patch instanceof AiRealExecutionPatchRun
            || ($unsigned['target'] ?? null) !== 'typed_contract_smoke'
            || ($unsigned['base_commit'] ?? null) !== $order->baseCommit
            || ($unsigned['patch_hash'] ?? null) !== $patch->patch_hash
            || ! is_string($junitPath) || ! is_file($junitPath) || ! is_string($junitHash)
            || ! hash_equals($junitHash, (string) hash_file('sha256', $junitPath))
            || ! is_array($unsigned['acceptance_bundle'] ?? null)
            || ! $this->producerSealValid($unsigned, AtlasRealEngineeringExecutionKernelService::KERNEL_VERIFICATION_PRODUCER, true)) {
            throw new InvalidArgumentException('kernel_test_run_receipt_binding_invalid');
        }
        $execution = $unsigned['acceptance_bundle']['execution'] ?? null;
        if (! is_array($execution) || ($execution['claimed_status'] ?? null) !== 'passed'
            || ($execution['selected_tests'] ?? null) !== $persisted->selected_tests
            || ! is_array($execution['commands'] ?? null) || $execution['commands'] === []
            || ! is_int($execution['tests_run'] ?? null) || $execution['tests_run'] < count((array) $persisted->selected_tests)
            || ! is_int($execution['assertions_executed'] ?? null) || $execution['assertions_executed'] < 1) {
            throw new InvalidArgumentException('kernel_test_run_execution_evidence_invalid');
        }

        return $persisted;
    }

    /** @param array<string,mixed> $receipt */
    private function producerSealValid(array $receipt, string $domain, bool $realExecution): bool
    {
        $producer = $receipt['producer'] ?? null;
        if (! is_array($producer) || ($producer['domain'] ?? null) !== $domain) {
            return false;
        }
        $unsigned = $receipt;
        unset($unsigned['producer']);
        $payloadHash = $realExecution ? RealExecutionHash::make($unsigned) : EngineeringCompanyHash::make($unsigned);
        if (! hash_equals((string) ($producer['payload_hash'] ?? ''), $payloadHash)) {
            return false;
        }
        $unsignedProducer = $producer;
        $signature = (string) ($unsignedProducer['signature'] ?? '');
        unset($unsignedProducer['signature']);
        $producerHash = $realExecution ? RealExecutionHash::make($unsignedProducer) : EngineeringCompanyHash::make($unsignedProducer);
        $key = $this->keyring()[(string) ($producer['key_id'] ?? '')] ?? null;

        return is_string($key) && hash_equals($signature, hash_hmac('sha256', $producerHash, hash_hmac('sha256', $domain, $key, true)));
    }
}
