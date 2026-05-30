<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution;

use App\Services\Ai\Mission\MissionCanonicalHash;

/**
 * Pilar 1 · Plan Execution Orchestrator (wiring / E2E entry).
 *
 * Thin composer that wires the three already-green Plan Execution modules into a
 * single end-to-end entry, WITHOUT reimplementing any of their logic:
 *
 *   1. {@see BuildPlanDecomposerService::decompose()} — turn a build-plan doc into
 *      a decomposed_plan.v1 (slices + dependency graph + honest status).
 *   2. {@see PlanCompletionTrackerService::rollup()} — read the per-plan/area
 *      append-only JSONL completion ledger projected against the decomposed plan.
 *   3. {@see PlanDeliveryCertificationService::certify()} — render the PLAN-scope
 *      delivery verdict (operational pre-gate + tracker + real-cycle cert).
 *
 * Loop integration is FULLY NON-INVASIVE: this orchestrator never hooks
 * AutonomousEvolutionSessionService::runCycle()/selectCandidate(). The tracker
 * already owns the JSONL ledger; the certification already replays per-session
 * AP-786 records (the existing per-session JSONL written by the loop). So the
 * 24h loop's default behaviour is unchanged and no 5k-line file is edited here.
 *
 * Honest status floor (real-or-blocked), surfaced as `orchestration_status`:
 *   - `blocked` when decomposition is blocked (no source / no slices), OR the
 *     certification operational pre-gate blocks. Nothing downstream is claimed.
 *   - `complete` ONLY when the certification verdict is `complete`.
 *   - `partial` otherwise. NEVER promotes planned/partial to complete.
 *
 * Pure composer: no provider, no branch, no git, no merge. The only side effect
 * is the tracker's append-only JSONL (which it owns), and only when a raw loop
 * cycle is recorded via {@see self::recordCycle()}.
 */
final class PlanExecutionOrchestratorService
{
    public const RESULT_SCHEMA = 'atlas.plan_execution.orchestration_result.v1';

    public const STATUS_COMPLETE = 'complete';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_BLOCKED = 'blocked';

    public const STAGE_DECOMPOSE = 'decompose';

    public const STAGE_TRACK = 'track';

    public const STAGE_CERTIFY = 'certify';

    private ?BuildPlanDecomposerService $decomposer = null;

    private ?PlanCompletionTrackerService $tracker = null;

    private ?PlanDeliveryCertificationService $certification = null;

    public function __construct(
        ?BuildPlanDecomposerService $decomposer = null,
        ?PlanCompletionTrackerService $tracker = null,
        ?PlanDeliveryCertificationService $certification = null,
    ) {
        $this->decomposer = $decomposer;
        $this->tracker = $tracker;
        $this->certification = $certification;
    }

    public function setDecomposerForTesting(?BuildPlanDecomposerService $s): void
    {
        $this->decomposer = $s;
    }

    public function setTrackerForTesting(?PlanCompletionTrackerService $s): void
    {
        $this->tracker = $s;
    }

    public function setCertificationForTesting(?PlanDeliveryCertificationService $s): void
    {
        $this->certification = $s;
    }

    private function decomposer(): BuildPlanDecomposerService
    {
        return $this->decomposer ??= new BuildPlanDecomposerService;
    }

    private function tracker(): PlanCompletionTrackerService
    {
        return $this->tracker ??= new PlanCompletionTrackerService;
    }

    private function certification(): PlanDeliveryCertificationService
    {
        if ($this->certification !== null) {
            return $this->certification;
        }

        $cert = new PlanDeliveryCertificationService;
        // Wiring bridge: PlanDeliveryCertificationService consumes a tracker seam
        // shaped rollup(array): array, while PlanCompletionTrackerService exposes
        // rollup(string $planId, string $areaId, array $plan). Neither green module
        // is edited; the orchestrator adapts between the two contracts here so the
        // real tracker (with its real JSONL ledger) backs the real certification.
        $cert->setTrackerForTesting($this->trackerSeamForCert());

        return $this->certification = $cert;
    }

    /**
     * Adapter object exposing rollup(array): array, delegating to the real
     * PlanCompletionTrackerService::rollup(string,string,array).
     */
    private function trackerSeamForCert(): object
    {
        $tracker = $this->tracker();

        return new class($tracker)
        {
            public function __construct(private PlanCompletionTrackerService $tracker) {}

            /**
             * @param  array<string,mixed>  $input
             * @return array<string,mixed>
             */
            public function rollup(array $input): array
            {
                $plan = is_array($input['decomposed_plan'] ?? null) ? $input['decomposed_plan'] : [];
                $planId = (string) ($plan['plan_id'] ?? '');
                $areaId = (string) ($input['area_id'] ?? '');

                return $this->tracker->rollup($planId, $areaId, $plan);
            }
        };
    }

    /**
     * Stage 1 — decompose a build-plan doc into a decomposed_plan.v1.
     *
     * @param  array{doc_path?:string,build_plan_md?:string,mode?:string,scope_profile?:string}  $input
     * @return array<string,mixed>
     */
    public function decompose(array $input): array
    {
        return $this->decomposer()->decompose($input);
    }

    /**
     * Stage 2 (read) — project the completion ledger for a plan/area.
     *
     * @param  array<string,mixed>  $decomposedPlan
     * @return array<string,mixed>
     */
    public function track(string $planId, string $areaId, array $decomposedPlan): array
    {
        return $this->tracker()->rollup($planId, $areaId, $decomposedPlan);
    }

    /**
     * Stage 2 (write) — record one RAW loop cycle against a decomposed plan.
     * Delegates entirely to the tracker (which owns the append-only JSONL).
     *
     * @param  array{decomposed_plan:array<string,mixed>,area_id:string,cycle:array<string,mixed>}  $input
     * @return array<string,mixed>
     */
    public function recordCycle(array $input): array
    {
        return $this->tracker()->recordCycle($input);
    }

    /**
     * Stage 3 — render the PLAN-scope delivery verdict.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function certify(array $input): array
    {
        return $this->certification()->certify($input);
    }

    /**
     * E2E — decompose -> track (rollup) -> certify, in one honest pass.
     *
     * @param  array{
     *     doc_path?:string,
     *     build_plan_md?:string,
     *     mode?:string,
     *     scope_profile?:string,
     *     area_id?:string,
     *     session_ids?:list<string>,
     *     integration_check?:array<string,mixed>
     * }  $input
     * @return array<string,mixed>
     */
    public function execute(array $input): array
    {
        $areaId = trim((string) ($input['area_id'] ?? 'agentic_engineering_os'));
        if ($areaId === '') {
            $areaId = 'agentic_engineering_os';
        }
        $sessionIds = array_values(array_filter(
            (array) ($input['session_ids'] ?? []),
            static fn ($v): bool => is_string($v) && $v !== '',
        ));
        $integrationCheck = is_array($input['integration_check'] ?? null) ? $input['integration_check'] : [];

        // ---- Stage 1: decompose ----
        $plan = $this->decompose([
            'doc_path' => (string) ($input['doc_path'] ?? ''),
            'build_plan_md' => is_string($input['build_plan_md'] ?? null) ? $input['build_plan_md'] : '',
            'mode' => (string) ($input['mode'] ?? BuildPlanDecomposerService::MODE_DRY_RUN),
            'scope_profile' => (string) ($input['scope_profile'] ?? ''),
        ]);

        $planId = (string) ($plan['plan_id'] ?? '');
        $decompositionStatus = (string) ($plan['decomposition_status'] ?? BuildPlanDecomposerService::STATUS_BLOCKED);

        // Real-or-blocked: a blocked decomposition stops the pipeline. Nothing
        // downstream is invented; track/certify are NOT run.
        if ($decompositionStatus === BuildPlanDecomposerService::STATUS_BLOCKED) {
            return $this->finalize([
                'plan_id' => $planId,
                'area_id' => $areaId,
                'orchestration_status' => self::STATUS_BLOCKED,
                'stage_reached' => self::STAGE_DECOMPOSE,
                'decomposition' => $plan,
                'completion' => null,
                'certification' => null,
                'blockers' => $this->prefixBlockers(self::STAGE_DECOMPOSE, (array) ($plan['blockers'] ?? [])),
            ]);
        }

        // ---- Stage 2: track (rollup, read-only) ----
        $completion = $this->track($planId, $areaId, $plan);

        // ---- Stage 3: certify ----
        $certification = $this->certify([
            'decomposed_plan' => $plan,
            'area_id' => $areaId,
            'session_ids' => $sessionIds,
            'integration_check' => $integrationCheck,
        ]);

        $certStatus = (string) ($certification['status'] ?? PlanDeliveryCertificationService::STATUS_BLOCKED);

        $blockers = [];
        $blockers = array_merge($blockers, $this->prefixBlockers(self::STAGE_TRACK, (array) ($completion['blockers'] ?? [])));
        $blockers = array_merge($blockers, $this->prefixBlockers(self::STAGE_CERTIFY, (array) ($certification['blockers'] ?? [])));

        $orchestrationStatus = $this->deriveStatus($decompositionStatus, $certStatus);

        return $this->finalize([
            'plan_id' => $planId,
            'area_id' => $areaId,
            'orchestration_status' => $orchestrationStatus,
            'stage_reached' => self::STAGE_CERTIFY,
            'decomposition' => $plan,
            'completion' => $completion,
            'certification' => $certification,
            'blockers' => array_values(array_unique($blockers)),
        ]);
    }

    /**
     * Orchestration status NEVER outranks the certification verdict:
     *   - complete ONLY when cert is complete.
     *   - blocked when cert is blocked.
     *   - partial otherwise.
     */
    private function deriveStatus(string $decompositionStatus, string $certStatus): string
    {
        if ($certStatus === PlanDeliveryCertificationService::STATUS_COMPLETE) {
            return self::STATUS_COMPLETE;
        }
        if ($certStatus === PlanDeliveryCertificationService::STATUS_BLOCKED) {
            return self::STATUS_BLOCKED;
        }

        return self::STATUS_PARTIAL;
    }

    /**
     * @param  array<int,mixed>  $blockers
     * @return list<string>
     */
    private function prefixBlockers(string $stage, array $blockers): array
    {
        $out = [];
        foreach ($blockers as $blocker) {
            if (is_string($blocker) && $blocker !== '') {
                $out[] = $stage.':'.$blocker;
            }
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function finalize(array $payload): array
    {
        $payload = array_merge(['schema_version' => self::RESULT_SCHEMA], $payload);

        $identity = $payload;
        unset($identity['orchestration_hash']);

        $payload['orchestration_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->hashable($identity));

        return $payload;
    }

    /**
     * Deterministic projection: hash the verdicts/ids, not volatile per-run
     * timestamps embedded in the composed sub-results.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function hashable(array $payload): array
    {
        $decomposition = is_array($payload['decomposition'] ?? null) ? $payload['decomposition'] : [];
        $certification = is_array($payload['certification'] ?? null) ? $payload['certification'] : [];
        $completion = is_array($payload['completion'] ?? null) ? $payload['completion'] : [];

        return [
            'plan_id' => (string) ($payload['plan_id'] ?? ''),
            'area_id' => (string) ($payload['area_id'] ?? ''),
            'orchestration_status' => (string) ($payload['orchestration_status'] ?? ''),
            'stage_reached' => (string) ($payload['stage_reached'] ?? ''),
            'decomposition_plan_hash' => (string) ($decomposition['plan_hash'] ?? ''),
            'completion_status' => (string) ($completion['status'] ?? ''),
            'certification_status' => (string) ($certification['status'] ?? ''),
            'certification_hash' => (string) ($certification['plan_delivery_cert_hash'] ?? ''),
            'blockers' => array_values((array) ($payload['blockers'] ?? [])),
        ];
    }
}
