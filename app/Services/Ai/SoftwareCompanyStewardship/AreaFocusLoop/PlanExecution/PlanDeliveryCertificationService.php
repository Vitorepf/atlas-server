<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Ap786RealCycleCertificationService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusLoopOperationalCertificationService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusStringListNormalizer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusUtcClock;

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

        $support = new PlanDeliveryCertificationServiceSupport();

        // ---- Real-cycle certification: one cert per session, indexed by finding_id ----
        $certifiedFindingIds = $support->certifiedFindingIds($sessionIds, $this->realCycleCert());

        // ---- Per-slice verdict ----
        $perSliceCertification = $support->perSliceCertification($plan, $sliceStates, $certifiedFindingIds, $totalSlices);
        $slices = $perSliceCertification['slices'];
        $perSlice = $perSliceCertification['per_slice'];
        $blockers = $perSliceCertification['blockers'];
        $deliveredSlices = $perSliceCertification['delivered_slices'];
        $allMergedWithProof = $perSliceCertification['all_merged_with_proof'];
        $allAcceptanceMet = $perSliceCertification['all_acceptance_met'];

        $dependencyOrderPreserved = PlanSliceReadModel::dependencyOrderPreserved($slices, $sliceStates);
        $integrationCheck = is_array($input['integration_check'] ?? null) ? $input['integration_check'] : [];
        $integrationGreen = (bool) ($integrationCheck['green'] ?? false);

        $blockers = $support->collectExternalBlockers($blockers, $dependencyOrderPreserved, $integrationGreen);

        $completionPct = $totalSlices > 0 ? round($deliveredSlices / $totalSlices * 100, 2) : 0.0;

        $complete = $support->isComplete(
            $totalSlices,
            $deliveredSlices,
            $allMergedWithProof,
            $allAcceptanceMet,
            $dependencyOrderPreserved,
            $integrationGreen,
            $operationalGate,
            AreaFocusLoopOperationalCertificationService::STATUS_OPERATIONAL,
            $perSlice,
        );

        $status = $support->resolveStatus(
            $complete,
            $totalSlices,
            $blockers,
            self::STATUS_COMPLETE,
            self::STATUS_BLOCKED,
            self::STATUS_PARTIAL,
        );

        return $this->finalize([
            'schema_version' => self::CERT_SCHEMA,
            'plan_id' => $planId,
            'status' => $status,
            'total_slices' => $totalSlices,
            'delivered_slices' => $deliveredSlices,
            'completion_pct' => $completionPct,
            'all_slices_merged_with_provider_proof' => $support->mergeAndAcceptanceGated($totalSlices, $allMergedWithProof),
            'all_acceptance_met' => $support->mergeAndAcceptanceGated($totalSlices, $allAcceptanceMet),
            'dependency_order_preserved' => $dependencyOrderPreserved,
            'integration_green' => $integrationGreen,
            'operational_gate' => $operationalGate,
            'per_slice' => $perSlice,
            'blockers' => AreaFocusStringListNormalizer::uniqueStringValues($blockers),
            'claim_policy' => $this->claimPolicy(),
        ]);
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
        return $this->realCycleCert ??= new Ap786RealCycleCertificationService;
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
        $payload['certified_at'] = AreaFocusUtcClock::atomNow();
        $payload['plan_delivery_cert_hash'] = 'sha256:'.MissionCanonicalHash::sha256($identity);

        return $payload;
    }
}
