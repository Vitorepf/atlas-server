<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel;

use App\Models\AiEngineeringCompanyRoleRun;
use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Decision\DecisionReceipt;
use App\Services\Ai\Kernel\Decision\DecisionReceiptRuntimeGuard;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
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

        return $this->issue('decision', LedgerEventType::DecisionIssued, $this->bindingPayload($order) + [
            'event_name' => 'decision.issued',
            'authority_hash' => CanonicalKernelPayload::hash($order->authorityEnvelope),
            'role_roster' => $order->roleRoster,
            'decision_receipt_hash' => $receipt->receiptHash,
        ], $context);
    }

    /** @param array<string,mixed> $context */
    public function issueEvidenceBundle(AcceptanceBundle $bundle, ExecutionOrder $order, array $context): AtlasLedgerEvent
    {
        return $this->issue('evidence_bundle', LedgerEventType::EvidencePacked, $this->bindingPayload($order) + [
            'event_name' => 'evidence.bundle.recorded',
            'acceptance_bundle' => $this->bundleArray($bundle),
        ], $context);
    }

    /** @param array<string,mixed> $context */
    public function issueRoleDisposition(AiEngineeringCompanyRoleRun $roleRun, ExecutionOrder $order, array $context): AtlasLedgerEvent
    {
        $role = (string) $roleRun->getAttribute('role_id');
        $roleHash = (string) $roleRun->getAttribute('role_hash');
        $evidenceRefs = $roleRun->getAttribute('evidence_refs');
        $output = $roleRun->getAttribute('output');
        if (! in_array($role, EngineeringRoleRoster::OFFICIAL_ROLES, true)
            || preg_match('/^[a-f0-9]{64}$/', $roleHash) !== 1 || ! is_array($evidenceRefs) || $evidenceRefs === []
            || ! is_array($output) || ! is_array($output['disposition'] ?? null)) {
            throw new InvalidArgumentException('kernel_role_run_invalid');
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
            'payload_hash' => CanonicalKernelPayload::hash($payload),
        ];
        $envelope['signature'] = hash_hmac('sha256', CanonicalKernelPayload::hash($envelope), $this->key());

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

        return hash_equals($signature, hash_hmac('sha256', CanonicalKernelPayload::hash($unsigned), $this->key()));
    }

    private function key(): string
    {
        $appKey = (string) config('app.key');
        if (str_starts_with($appKey, 'base64:')) {
            $decoded = base64_decode(substr($appKey, 7), true);
            $appKey = $decoded === false ? '' : $decoded;
        }
        if ($appKey === '') {
            throw new \RuntimeException('kernel_evidence_authority_app_key_missing');
        }

        return hash_hmac('sha256', 'atlas.engineering_kernel.evidence_authority.v1', $appKey, true);
    }

    private function assertAllowed(string $kind, LedgerEventType $type): void
    {
        $allowed = ($kind === 'decision' && $type === LedgerEventType::DecisionIssued)
            || ($kind === 'evidence_bundle' && $type === LedgerEventType::EvidencePacked)
            || (in_array($kind, ['acceptance', 'role_disposition'], true) && $type === LedgerEventType::GateEvaluated);
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
}
