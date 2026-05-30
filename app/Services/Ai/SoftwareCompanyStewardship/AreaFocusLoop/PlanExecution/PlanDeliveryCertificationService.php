<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Ap786RealCycleCertificationService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusLoopOperationalCertificationService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Pilar 1 — Plan delivery certification (PLAN scope).
 *
 * Renders a PLAN-scope verdict by COMPOSING three existing judges, never
 * reimplementing them:
 *   - AreaFocusLoopOperationalCertificationService::certify(record_evidence=false)
 *     as a hard operational pre-gate (if not 'operational' => status='blocked'
 *     before any slice is judged).
 *   - PlanCompletionTrackerService::rollup() over the decomposed_plan to learn
 *     per-slice delivery / provider-proof / acceptance state.
 *   - Ap786RealCycleCertificationService::certify() once per session_id to prove
 *     each delivered slice was actually merged by a real owner-flow cycle.
 *
 * Real-or-blocked: a slice the tracker calls "delivered" is only honoured as
 * delivered-with-provider-proof when a real AP-786 cycle (matched by
 * finding_id on the cert's TOP-LEVEL cycles[]) is STATUS_CERTIFIED. Otherwise
 * real_cycle_certified=false downgrades the plan to 'partial'. This judge never
 * fabricates completion and never trusts caller-supplied "done" flags.
 */
final class PlanDeliveryCertificationService
{
    public const CERT_SCHEMA = 'atlas.plan_execution.plan_delivery_certification.v1';

    public const STATUS_COMPLETE = 'complete';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_BLOCKED = 'blocked';

    /**
     * Seams. The concrete neighbours (Ap786RealCycleCertificationService,
     * AreaFocusLoopOperationalCertificationService) are `final`, so they cannot
     * be subclassed for test doubles. The setters therefore accept any object
     * exposing the same `certify(array): array` / `rollup(array): array` surface
     * (duck-typed), which keeps composition honest: production injects the real
     * services, tests inject lightweight doubles with the identical method shape.
     */
    private ?object $realCycleCert = null;

    /** @var object{rollup:callable}|null A PlanCompletionTrackerService-shaped seam. */
    private ?object $tracker = null;

    private ?object $operationalCert = null;

    public function setRealCycleCertForTesting(object $cert): void
    {
        $this->realCycleCert = $cert;
    }

    public function setTrackerForTesting(object $tracker): void
    {
        $this->tracker = $tracker;
    }

    public function setOperationalCertForTesting(object $cert): void
    {
        $this->operationalCert = $cert;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function certify(array $input): array
    {
        $plan = is_array($input['decomposed_plan'] ?? null) ? $input['decomposed_plan'] : [];
        $areaId = trim((string) ($input['area_id'] ?? ''));
        $planId = (string) ($plan['plan_id'] ?? '');
        $sessionIds = array_values(array_filter(
            (array) ($input['session_ids'] ?? []),
            static fn ($v): bool => is_string($v) && $v !== '',
        ));

        // ---- Operational pre-gate (hard floor) ----
        $operational = $this->operationalCert();
        $opResult = $operational->certify(['area_id' => $areaId, 'record_evidence' => false]);
        $operationalGate = (string) ($opResult['status'] ?? AreaFocusLoopOperationalCertificationService::STATUS_BLOCKED);

        if ($operationalGate !== AreaFocusLoopOperationalCertificationService::STATUS_OPERATIONAL) {
            return $this->finalize([
                'schema_version' => self::CERT_SCHEMA,
                'plan_id' => $planId,
                'status' => self::STATUS_BLOCKED,
                'total_slices' => 0,
                'delivered_slices' => 0,
                'completion_pct' => 0.0,
                'all_slices_merged_with_provider_proof' => false,
                'all_acceptance_met' => false,
                'dependency_order_preserved' => false,
                'integration_green' => false,
                'operational_gate' => $operationalGate,
                'per_slice' => [],
                'blockers' => ['operational_gate_not_operational:'.$operationalGate],
                'claim_policy' => $this->claimPolicy(),
            ]);
        }

        // ---- Completion ledger (tracker rollup) ----
        $ledger = $this->tracker()->rollup(['decomposed_plan' => $plan, 'area_id' => $areaId]);
        $ledger = is_array($ledger) ? $ledger : [];

        $sliceStates = is_array($ledger['slice_states'] ?? null) ? $ledger['slice_states'] : [];
        $totalSlices = (int) ($ledger['total_slices'] ?? count($sliceStates));

        // ---- Real-cycle certification: one cert per session, indexed by finding_id ----
        $certifiedFindingIds = $this->certifiedFindingIds($sessionIds);

        // ---- Per-slice verdict ----
        $slices = array_values(array_filter((array) ($plan['slices'] ?? []), 'is_array'));
        $perSlice = [];
        $blockers = [];
        $deliveredSlices = 0;
        $allMergedWithProof = $totalSlices > 0;
        $allAcceptanceMet = $totalSlices > 0;
        $allRealCertified = $totalSlices > 0;

        foreach ($slices as $slice) {
            $sliceId = (string) ($slice['slice_id'] ?? '');
            $findingId = (string) (data_get($slice, 'finding.finding_id', '') ?: $sliceId);
            $state = is_array($sliceStates[$sliceId] ?? null) ? $sliceStates[$sliceId] : [];

            $trackerDelivered = (string) ($state['state'] ?? '') === 'delivered';
            $providerProof = (bool) ($state['provider_proof'] ?? false);
            $acceptanceMet = (bool) ($state['acceptance_met'] ?? false);
            $mergeHash = $state['merge_hash'] ?? null;
            $mergeHash = is_string($mergeHash) && $mergeHash !== '' ? $mergeHash : null;

            $realCycleCertified = $findingId !== '' && isset($certifiedFindingIds[$findingId]);

            $sliceBlockers = [];
            if (! $trackerDelivered) {
                $sliceBlockers[] = 'slice_not_delivered:'.((string) ($state['state'] ?? 'unknown'));
            }
            if ($trackerDelivered && ! $providerProof) {
                $sliceBlockers[] = 'delivered_without_provider_proof';
            }
            if ($trackerDelivered && ! $acceptanceMet) {
                $sliceBlockers[] = 'acceptance_not_met';
            }
            if ($trackerDelivered && ! $realCycleCertified) {
                // Provider-proof honesty: tracker says delivered but no real,
                // certified owner-flow cycle backs it. Never counts as complete.
                $sliceBlockers[] = 'no_certified_real_cycle';
            }

            // A slice counts as truly delivered ONLY with tracker delivery AND
            // provider proof AND acceptance AND a real certified cycle.
            $delivered = $trackerDelivered && $providerProof && $acceptanceMet && $realCycleCertified;
            if ($delivered) {
                $deliveredSlices++;
            }

            $allMergedWithProof = $allMergedWithProof && $providerProof && $mergeHash !== null;
            $allAcceptanceMet = $allAcceptanceMet && $acceptanceMet;
            $allRealCertified = $allRealCertified && $realCycleCertified;

            if ($sliceBlockers !== []) {
                foreach ($sliceBlockers as $b) {
                    $blockers[] = $sliceId.':'.$b;
                }
            }

            $perSlice[] = [
                'slice_id' => $sliceId,
                'delivered' => $delivered,
                'merge_hash' => $mergeHash,
                'provider_proof' => $providerProof,
                'acceptance_met' => $acceptanceMet,
                'real_cycle_certified' => $realCycleCertified,
                'blockers' => array_values($sliceBlockers),
            ];
        }

        $dependencyOrderPreserved = $this->dependencyOrderPreserved($slices, $sliceStates);
        if (! $dependencyOrderPreserved) {
            $blockers[] = 'dependency_order_not_preserved';
        }

        $integrationCheck = is_array($input['integration_check'] ?? null) ? $input['integration_check'] : [];
        $integrationGreen = (bool) ($integrationCheck['green'] ?? false);
        if (! $integrationGreen) {
            $blockers[] = 'integration_not_green';
        }

        $completionPct = $totalSlices > 0 ? round($deliveredSlices / $totalSlices * 100, 2) : 0.0;

        $complete = $totalSlices > 0
            && $deliveredSlices === $totalSlices
            && $allMergedWithProof
            && $allAcceptanceMet
            && $dependencyOrderPreserved
            && $integrationGreen
            && $operationalGate === AreaFocusLoopOperationalCertificationService::STATUS_OPERATIONAL
            && $this->everyRealCycleCertified($perSlice);

        if ($complete) {
            $status = self::STATUS_COMPLETE;
        } elseif ($totalSlices === 0) {
            $status = self::STATUS_BLOCKED;
            $blockers[] = 'zero_slices';
        } else {
            $status = self::STATUS_PARTIAL;
        }

        return $this->finalize([
            'schema_version' => self::CERT_SCHEMA,
            'plan_id' => $planId,
            'status' => $status,
            'total_slices' => $totalSlices,
            'delivered_slices' => $deliveredSlices,
            'completion_pct' => $completionPct,
            'all_slices_merged_with_provider_proof' => $totalSlices > 0 && $allMergedWithProof,
            'all_acceptance_met' => $totalSlices > 0 && $allAcceptanceMet,
            'dependency_order_preserved' => $dependencyOrderPreserved,
            'integration_green' => $integrationGreen,
            'operational_gate' => $operationalGate,
            'per_slice' => $perSlice,
            'blockers' => array_values(array_unique($blockers)),
            'claim_policy' => $this->claimPolicy(),
        ]);
    }

    /**
     * Build the set of finding_ids proven by a real, certified owner-flow cycle.
     *
     * The join key is explicit: the per-session AP-786 cert's TOP-LEVEL cycles[]
     * entry must carry selected_finding.finding_id AND status === STATUS_CERTIFIED.
     * three_cycle_audit.per_cycle[] is deliberately NOT used (it omits finding_id).
     *
     * @param  list<string>  $sessionIds
     * @return array<string,true>
     */
    private function certifiedFindingIds(array $sessionIds): array
    {
        $certified = [];
        $cert = $this->realCycleCert();

        foreach ($sessionIds as $sessionId) {
            $result = $cert->certify(['session_id' => $sessionId]);
            $cycles = is_array($result['cycles'] ?? null) ? $result['cycles'] : [];
            foreach ($cycles as $cycle) {
                if (! is_array($cycle)) {
                    continue;
                }
                $findingId = (string) data_get($cycle, 'selected_finding.finding_id', '');
                $cycleStatus = (string) ($cycle['status'] ?? '');
                if ($findingId !== '' && $cycleStatus === Ap786RealCycleCertificationService::STATUS_CERTIFIED) {
                    $certified[$findingId] = true;
                }
            }
        }

        return $certified;
    }

    /**
     * Dependency order is preserved when no slice is delivered before all of its
     * declared depends_on slices are themselves delivered.
     *
     * @param  list<array<string,mixed>>  $slices
     * @param  array<string,mixed>  $sliceStates
     */
    private function dependencyOrderPreserved(array $slices, array $sliceStates): bool
    {
        $isDelivered = static function (string $id) use ($sliceStates): bool {
            $state = is_array($sliceStates[$id] ?? null) ? $sliceStates[$id] : [];

            return (string) ($state['state'] ?? '') === 'delivered';
        };

        foreach ($slices as $slice) {
            $sliceId = (string) ($slice['slice_id'] ?? '');
            if (! $isDelivered($sliceId)) {
                continue;
            }
            foreach ((array) ($slice['depends_on'] ?? []) as $dep) {
                if (is_string($dep) && $dep !== '' && ! $isDelivered($dep)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param  list<array<string,mixed>>  $perSlice
     */
    private function everyRealCycleCertified(array $perSlice): bool
    {
        foreach ($perSlice as $slice) {
            if (($slice['real_cycle_certified'] ?? false) !== true) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{ready_from_synthetic_shape:false,benchmark:false,rivals:false,superiority:false}
     */
    private function claimPolicy(): array
    {
        return [
            'ready_from_synthetic_shape' => false,
            'benchmark' => false,
            'rivals' => false,
            'superiority' => false,
        ];
    }

    private function realCycleCert(): object
    {
        return $this->realCycleCert ??= new Ap786RealCycleCertificationService();
    }

    private function tracker(): object
    {
        if ($this->tracker !== null) {
            return $this->tracker;
        }

        $fqcn = 'App\\Services\\Ai\\SoftwareCompanyStewardship\\AreaFocusLoop\\PlanExecution\\PlanCompletionTrackerService';
        if (class_exists($fqcn)) {
            /** @var object $instance */
            $instance = app($fqcn);

            return $this->tracker = $instance;
        }

        throw new \RuntimeException('PlanCompletionTrackerService seam not available; inject via setTrackerForTesting().');
    }

    private function operationalCert(): object
    {
        return $this->operationalCert ??= app(AreaFocusLoopOperationalCertificationService::class);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function finalize(array $payload): array
    {
        $identity = $payload;
        unset($identity['plan_delivery_cert_hash'], $identity['certified_at']);
        $payload['certified_at'] = $this->now();
        $payload['plan_delivery_cert_hash'] = 'sha256:'.MissionCanonicalHash::sha256($identity);

        return $payload;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }
}
