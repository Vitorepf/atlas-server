<?php

declare(strict_types=1);

namespace App\Services\Ai\Reconciliation;

use App\Services\Ai\Cognition\AtlasCognitiveFunctionAtlasService;
use App\Services\Ai\Governance\AtlasAutonomyAdmissionService;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use App\Services\Ai\Reality\AtlasUnifiedRealityGraphTemporalService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionSubsystemBuilderService;
use App\Services\Ai\Support\AppendOnlyJsonlStore;
use App\Services\Ai\Teos\AtlasTeosI3CounterfactualService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Atlas Autonomous Reconciliation Runtime — Patamar 4 · 4.3.
 *
 * Motor da batida do loop fechado de Patamar 4. NÃO contém lógica de
 * gap-detection, propose, validate, admit ou tick — delega para:
 *   - AtlasCognitiveFunctionAtlasService (self-model + gaps)
 *   - AtlasAutonomyAdmissionService (admit + Constitutional Kernel)
 *   - AtlasUnifiedRealityGraphTemporalService (AURG-4D tick imutável)
 *
 * Authority doc:
 *   docs/engineering-knowledge-base/atlas-autonomous-reconciliation-runtime.md
 *
 * Invariantes:
 *   - Cada tick atravessa Constitutional Kernel via Admission;
 *   - Cada tick gera AURG-4D tick (hash chain imutável);
 *   - Append-only JSONL local-first;
 *   - noop_no_gap quando todos os pipelines estão ready (não inventa ação).
 */
class AtlasAutonomousReconciliationRuntimeService
{
    public const TICK_SCHEMA = 'atlas.autonomous_reconciliation.tick.v1';

    public const STEP_SCHEMA = 'atlas.autonomous_reconciliation.step.v1';

    public const OUTCOME_AUTO_APPLIED = 'auto_applied';

    public const OUTCOME_PENDING_APPROVAL = 'pending_approval';

    public const OUTCOME_BLOCKED_BY_KERNEL = 'blocked_by_kernel';

    public const OUTCOME_NOOP_NO_GAP = 'noop_no_gap';

    public const OUTCOME_DISABLED_BY_KERNEL = 'disabled_by_kernel_elastic';

    public const ACTION_KIND_STABILIZE_PIPELINE = 'stabilize_pipeline';

    public const ACTION_KIND_EVIDENCE_HEALTH_PROBE = 'evidence_health_probe';

    public const ACTION_KIND_DOC_HEALTH_PROBE = 'doc_health_probe';

    public const ACTION_KIND_TELEMETRY_AUDIT = 'telemetry_audit';

    public const VALID_ACTION_KINDS = [
        self::ACTION_KIND_STABILIZE_PIPELINE,
        self::ACTION_KIND_EVIDENCE_HEALTH_PROBE,
        self::ACTION_KIND_DOC_HEALTH_PROBE,
        self::ACTION_KIND_TELEMETRY_AUDIT,
    ];

    public const META_PROJECTION_MIN_IMPROVEMENT = 0.05;

    private ?string $ticksLogOverride = null;

    private ?AtlasTeosI3CounterfactualService $teosI3 = null;

    private ?AtlasConstitutionalKernelService $kernel = null;

    private ?\App\Services\Engineering\EngineeringDocumentationHealthService $docHealth = null;

    private ?\App\Services\Ai\Patamar4\AtlasSubsystemAutoRebalanceService $autoRebalance = null;

    public function __construct(
        private readonly AtlasCognitiveFunctionAtlasService $cfa,
        private readonly AtlasAutonomyAdmissionService $admission,
        private readonly AtlasUnifiedRealityGraphTemporalService $aurg,
        private readonly AtlasSelfConstructionSubsystemBuilderService $ascb,
    ) {}

    /**
     * Optional Kernel seam — when wired, Reconciliation honors elastic
     * invariants (autonomous_self_construction_enabled, teos_meta_projection_enabled)
     * before firing ASCB.propose() or TEOS-I3 projection.
     */
    public function setKernelForElasticChecks(?AtlasConstitutionalKernelService $kernel): void
    {
        $this->kernel = $kernel;
    }

    /**
     * Optional doc-health seam — when wired, the doc_health_probe action_kind
     * pulls real violation/coverage counts from EngineeringDocumentationHealthService.
     * Default: null → probe falls back to honest "service not wired" payload.
     */
    public function setDocHealthService(?\App\Services\Engineering\EngineeringDocumentationHealthService $svc): void
    {
        $this->docHealth = $svc;
    }

    /**
     * Optional meta-cognition seam. When wired, Reconciliation projects the
     * outcome of a candidate ASCB proposal via TEOS-I3 before firing it.
     * If projected improvement < META_PROJECTION_MIN_IMPROVEMENT, the
     * propose() call is suppressed (recorded as 'projection_below_threshold').
     */
    /**
     * Optional auto-rebalance seam. When wired, every reconciliation tick
     * also calls plan() on all 4 canonical rebalance kinds (read-only;
     * receipts auto-recorded to F4 ledger). Operator never has to invoke
     * `atlas:rebalance` manually.
     */
    public function setAutoRebalanceService(?\App\Services\Ai\Patamar4\AtlasSubsystemAutoRebalanceService $svc): void
    {
        $this->autoRebalance = $svc;
    }

    public function setTeosI3ForMetaProjection(?AtlasTeosI3CounterfactualService $teosI3): void
    {
        $this->teosI3 = $teosI3;
    }

    public function setTicksLogPathForTesting(?string $path): void
    {
        $this->ticksLogOverride = $path;
    }

    public function ticksLogPath(): string
    {
        if ($this->ticksLogOverride !== null) {
            return $this->ticksLogOverride;
        }
        $base = function_exists('storage_path')
            ? storage_path('atlas/reconciliation')
            : sys_get_temp_dir().'/atlas/reconciliation';

        return $base.DIRECTORY_SEPARATOR.'ticks.jsonl';
    }

    /**
     * Execute one reconciliation cycle. Idempotent in spirit — caller decides cadence.
     *
     * @param  array<string,mixed>|null  $context
     * @return array<string,mixed>
     */
    public function tick(?array $context = null): array
    {
        $context = $context ?? [];

        // Elastic invariant gate: operator can pause the loop entirely without
        // breaking schedule registration. Tick returns a disabled receipt.
        if ($this->kernel !== null && ! $this->kernel->isElasticEnabled('reconciliation_cron_enabled')) {
            $at = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
            $tick = $this->buildTick(
                at: $at,
                selfModelHash: 'sha256:reconciliation_disabled',
                selectedGroup: null,
                gapSize: 0,
                step: null,
                outcome: self::OUTCOME_DISABLED_BY_KERNEL,
            );
            AppendOnlyJsonlStore::append($this->ticksLogPath(), $tick);

            return $tick;
        }

        $selfModel = $this->cfa->selfModel();
        $selfModelHash = 'sha256:'.hash('sha256', json_encode([
            'groups' => $selfModel['groups'] ?? [],
            'shape' => $selfModel['shape'] ?? [],
            'kernel_hash' => $selfModel['kernel_hash'] ?? '',
        ], JSON_THROW_ON_ERROR));

        $gaps = $this->cfa->gapsByGroup();
        $top = $gaps[0] ?? null;

        // Operator-driven force: target a specific group regardless of detected gaps.
        // Useful when registry is all-ready but the operator wants to exercise the loop
        // (e.g., to inspect a domain's reconciliation receipt). Top gap detection still
        // takes precedence when a real gap is present.
        $needsForce = ($top === null) || ((int) ($top['non_ready_pipeline'] ?? 0) === 0);
        if ($needsForce && ! empty($context['force_group'])) {
            $top = [
                'group' => (string) $context['force_group'],
                'non_ready_pipeline' => (int) ($context['force_gap_size'] ?? 1),
            ];
        }

        $at = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);

        if ($top === null || (int) $top['non_ready_pipeline'] === 0) {
            // Even in noop ticks, run auto-rebalance sweep so probes update
            // independently of gap detection. Sweep is read-only.
            $noopSweep = $this->runAutoRebalanceSweep();
            $noopStep = $noopSweep['wired']
                ? [
                    'schema_version' => self::STEP_SCHEMA,
                    'step_kind' => 'noop_with_rebalance_sweep',
                    'rebalance_sweep' => $noopSweep,
                ]
                : null;
            $tick = $this->buildTick(
                at: $at,
                selfModelHash: $selfModelHash,
                selectedGroup: null,
                gapSize: 0,
                step: $noopStep,
                outcome: self::OUTCOME_NOOP_NO_GAP,
            );
            AppendOnlyJsonlStore::append($this->ticksLogPath(), $tick);

            return $tick;
        }

        $group = (string) $top['group'];
        $gapSize = (int) $top['non_ready_pipeline'];
        $privacy = (string) ($context['privacy_class'] ?? 'normal');

        $actionKind = (string) ($context['action_kind'] ?? self::ACTION_KIND_STABILIZE_PIPELINE);
        if (! in_array($actionKind, self::VALID_ACTION_KINDS, true)) {
            $actionKind = self::ACTION_KIND_STABILIZE_PIPELINE;
        }

        // Build canonical change proposal.
        $change = [
            'change_kind' => 'reconciliation_step',
            'proposed_effect' => "stabilize pipeline status of group '{$group}' towards ready",
            'scope' => [
                'group' => $group,
                'privacy_class' => $privacy,
            ],
            'actor' => 'autonomous_reconciliation_runtime',
            'requested_autonomy' => (string) ($context['requested_autonomy'] ?? 'execute_with_approval'),
        ];

        // Admit (which chains through Constitutional Kernel).
        $admission = $this->admission->admit($change);

        $outcome = match ($admission['decision']) {
            AtlasAutonomyAdmissionService::DECISION_ALLOW_AUTONOMOUS => self::OUTCOME_AUTO_APPLIED,
            AtlasAutonomyAdmissionService::DECISION_ALLOW_WITH_APPROVAL => self::OUTCOME_PENDING_APPROVAL,
            AtlasAutonomyAdmissionService::DECISION_DENY => self::OUTCOME_BLOCKED_BY_KERNEL,
            default => self::OUTCOME_PENDING_APPROVAL,
        };

        // Emit AURG-4D tick (rationale_event — we're not capturing a graph snapshot here).
        $aurgTick = $this->aurg->recordTick([
            'kind' => 'rationale_event',
            'actor' => 'self_construction',
            'rationale' => "reconciliation_step on group='{$group}' outcome={$outcome}",
        ]);

        // REAL ACTION: when outcome=auto_applied AND ASCB is wired, fire a real proposal.
        // Proposals are append-only and require_human_approval=true — pétreo invariant.
        // META-COGNITION: when TEOS-I3 is wired, the runtime projects expected improvement
        // BEFORE firing the proposal. Sub-threshold projections are recorded as
        // 'projection_below_threshold' so the operator sees them without polluting ASCB.
        $ascbProposalId = null;
        $ascbProposalHash = null;
        $projectionBranchId = null;
        $projectionImprovement = null;
        if ($outcome === self::OUTCOME_AUTO_APPLIED) {
            $shouldFire = true;
            $suppressionReason = 'projection_below_threshold';
            // Elastic invariant gate: operator can flip
            // autonomous_self_construction_enabled=false to keep the loop
            // running while suppressing real ASCB proposals.
            if ($this->kernel !== null && ! $this->kernel->isElasticEnabled('autonomous_self_construction_enabled')) {
                $shouldFire = false;
                $suppressionReason = 'kernel_elastic_disabled';
            }
            if ($shouldFire && $this->teosI3 !== null
                && (! $this->kernel || $this->kernel->isElasticEnabled('teos_meta_projection_enabled'))) {
                try {
                    $projection = $this->teosI3->branch([
                        'anchor_decision_id' => 'reconciliation_'.$group,
                        'alternative' => ['decision_kind' => 'policy_swap', 'value' => 'propose_subsystem'],
                        'factual_outcome_score' => 0.5,
                        'projected_outcome_score' => 0.5 + min(0.5, $gapSize * 0.05),
                        'scope' => $change['scope'],
                    ]);
                    $projectionBranchId = (string) ($projection['branch_id'] ?? '');
                    $projectionImprovement = (float) ($projection['projected_outcome_score'] ?? 0) - (float) ($projection['factual_outcome_score'] ?? 0);
                    if ($projectionImprovement < self::META_PROJECTION_MIN_IMPROVEMENT) {
                        $shouldFire = false;
                    }
                } catch (\Throwable $e) {
                    // Projection failed: do not block the loop; proceed with default behavior.
                    $projectionImprovement = null;
                }
            }
            if ($shouldFire) {
                try {
                    $proposal = $this->ascb->propose([
                        'gap_kind' => AtlasSelfConstructionSubsystemBuilderService::GAP_PIPELINE_NOT_PROVEN,
                        'subsystem_acronym' => $this->deriveSubsystemAcronym($group),
                        'subsystem_name' => 'Reconciliation candidate for '.$group,
                        'group' => $this->mapGroupForAscb($group),
                        'rationale' => "Autonomous reconciliation tick on group '{$group}' (gap_size={$gapSize}). Admission decision=allow_autonomous.",
                    ]);
                    $ascbProposalId = (string) ($proposal['proposal_id'] ?? '');
                    $ascbProposalHash = (string) ($proposal['proposal_hash'] ?? '');
                } catch (\Throwable $e) {
                    $ascbProposalId = null;
                    $ascbProposalHash = 'sha256:propose_failed';
                }
            } else {
                $ascbProposalHash = 'sha256:suppressed_'.$suppressionReason;
            }
        }

        // Probe handlers — alternate action kinds. The pipeline-stabilize path
        // above is the default; when context.action_kind selects a probe, the
        // tick still chains through Kernel + Admission + AURG but emits a probe
        // receipt instead of an ASCB proposal. Probes are READ-ONLY by canon.
        $probeReceipt = null;
        if ($actionKind !== self::ACTION_KIND_STABILIZE_PIPELINE) {
            $probeReceipt = $this->dispatchProbe($actionKind, $group, $gapSize, $selfModel);
        }

        // Auto-rebalance sweep: when wired, every tick calls plan() on all
        // 4 canonical kinds. Read-only — plans are persisted by the
        // rebalance service to its own JSONL. We attach the hashes here so
        // the tick receipt links to them.
        $rebalanceSweep = $this->runAutoRebalanceSweep();

        $step = [
            'schema_version' => self::STEP_SCHEMA,
            'step_kind' => $actionKind,
            'scope' => $change['scope'],
            'admission_envelope' => $admission,
            'aurg_tick_id' => (string) ($aurgTick['tick_id'] ?? ''),
            'aurg_tick_hash' => (string) ($aurgTick['tick_hash'] ?? ''),
            'ascb_proposal_id' => $ascbProposalId,
            'ascb_proposal_hash' => $ascbProposalHash,
            'projection_branch_id' => $projectionBranchId,
            'projection_improvement_delta' => $projectionImprovement !== null ? round($projectionImprovement, 4) : null,
            'probe_receipt' => $probeReceipt,
            'rebalance_sweep' => $rebalanceSweep,
        ];

        $tick = $this->buildTick(
            at: $at,
            selfModelHash: $selfModelHash,
            selectedGroup: $group,
            gapSize: $gapSize,
            step: $step,
            outcome: $outcome,
        );
        AppendOnlyJsonlStore::append($this->ticksLogPath(), $tick);

        return $tick;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listTicks(): array
    {
        return AppendOnlyJsonlStore::read($this->ticksLogPath());
    }

    public function lastTick(): ?array
    {
        $ticks = $this->listTicks();

        return $ticks === [] ? null : $ticks[count($ticks) - 1];
    }

    /**
     * @return array<string,mixed>
     */
    public function summary(): array
    {
        $tally = [
            self::OUTCOME_AUTO_APPLIED => 0,
            self::OUTCOME_PENDING_APPROVAL => 0,
            self::OUTCOME_BLOCKED_BY_KERNEL => 0,
            self::OUTCOME_NOOP_NO_GAP => 0,
        ];
        $ticks = $this->listTicks();
        foreach ($ticks as $t) {
            $o = (string) ($t['outcome'] ?? '');
            if (isset($tally[$o])) {
                $tally[$o]++;
            }
        }

        return [
            'schema_version' => 'atlas.autonomous_reconciliation.summary.v1',
            'tick_count' => count($ticks),
            'outcomes' => $tally,
            'last_tick_at' => $ticks === [] ? null : (string) ($ticks[count($ticks) - 1]['at'] ?? ''),
        ];
    }

    /**
     * Probe dispatch — READ-ONLY canonical handler set for alternate
     * reconciliation action kinds. Each probe emits a receipt embedded in the
     * tick step; no external side effects beyond the JSONL trail.
     *
     * @param  array<string,mixed>  $selfModel
     * @return array<string,mixed>
     */
    /**
     * Auto-rebalance sweep: when the auto-rebalance service is wired (via
     * setAutoRebalanceService), iterate the 4 canonical kinds and call
     * plan() on each. Plans are read-only and append their own JSONL
     * receipt — we only echo the plan_hash + observed metric into the
     * tick receipt so the operator can trace the sweep end-to-end.
     *
     * @return array<string,mixed>
     */
    private function runAutoRebalanceSweep(): array
    {
        if ($this->autoRebalance === null) {
            return [
                'wired' => false,
                'plans' => [],
            ];
        }
        $plans = [];
        foreach (\App\Services\Ai\Patamar4\AtlasSubsystemAutoRebalanceService::VALID_KINDS as $kind) {
            try {
                $plan = $this->autoRebalance->plan($kind, 'reconciliation_tick_auto');
                $plans[] = [
                    'kind' => $kind,
                    'status' => $plan['status'] ?? 'unknown',
                    'plan_hash' => $plan['plan_hash'] ?? null,
                    'probe_status' => $plan['diagnostics']['probe_status'] ?? 'unknown',
                    'observed' => $plan['diagnostics']['observed'] ?? null,
                ];
            } catch (\Throwable $e) {
                $plans[] = [
                    'kind' => $kind,
                    'status' => 'sweep_error',
                    'error' => substr($e->getMessage(), 0, 120),
                ];
            }
        }

        return [
            'wired' => true,
            'plans' => $plans,
        ];
    }

    private function dispatchProbe(string $actionKind, string $group, int $gapSize, array $selfModel): array
    {
        $base = [
            'probe_kind' => $actionKind,
            'group' => $group,
            'gap_size' => $gapSize,
        ];

        switch ($actionKind) {
            case self::ACTION_KIND_EVIDENCE_HEALTH_PROBE:
                // Honest read: assert the reconciliation log itself is append-only
                // by counting current tick count + reporting last tick id.
                $ticks = $this->listTicks();
                $base['observed_tick_count'] = count($ticks);
                $base['last_tick_id'] = $ticks === [] ? null : ($ticks[count($ticks) - 1]['tick_id'] ?? null);
                $base['append_only_assumed'] = true;
                $base['note'] = 'evidence_ledger_external_check_not_yet_wired';
                break;

            case self::ACTION_KIND_DOC_HEALTH_PROBE:
                // Real doc-health read when wired; honest fallback otherwise.
                if ($this->docHealth !== null) {
                    try {
                        $report = $this->docHealth->report();
                        $summary = (array) ($report['summary'] ?? []);
                        $violations = (array) ($report['violations'] ?? []);
                        $warnings = (array) ($report['warnings'] ?? []);
                        $base['doc_status'] = (string) ($report['status'] ?? 'unknown');
                        $base['doc_count'] = (int) ($summary['doc_count'] ?? 0);
                        $base['required_doc_count'] = (int) ($summary['required_doc_count'] ?? 0);
                        $base['violation_count'] = count($violations);
                        $base['warning_count'] = count($warnings);
                        $base['top_violations'] = array_slice(
                            array_map(static fn ($v) => is_array($v) ? ($v['kind'] ?? $v['type'] ?? 'unspecified') : 'unspecified', $violations),
                            0, 5
                        );
                        $base['note'] = 'doc_health_service_real';
                    } catch (\Throwable $e) {
                        $base['note'] = 'doc_health_service_error:'.substr($e->getMessage(), 0, 120);
                        $shape = (array) ($selfModel['shape'] ?? []);
                        $base['groups_observed'] = array_keys($shape);
                        $base['groups_count'] = count($shape);
                    }
                } else {
                    // Fallback honest read: self-model shape only.
                    $shape = (array) ($selfModel['shape'] ?? []);
                    $base['groups_observed'] = array_keys($shape);
                    $base['groups_count'] = count($shape);
                    $base['note'] = 'doc_health_service_not_wired';
                }
                break;

            case self::ACTION_KIND_TELEMETRY_AUDIT:
                // Aggregate real metrics: AURG temporal ticks, Kernel violations,
                // reconciliation outcome tally. Provider-safe: counts only, no
                // claim comparisons emitted.
                $aurgRecent = method_exists($this->aurg, 'listTicks')
                    ? (array) $this->aurg->listTicks()
                    : [];
                $reconTicks = $this->listTicks();
                $tally = [
                    self::OUTCOME_AUTO_APPLIED => 0,
                    self::OUTCOME_PENDING_APPROVAL => 0,
                    self::OUTCOME_BLOCKED_BY_KERNEL => 0,
                    self::OUTCOME_NOOP_NO_GAP => 0,
                    self::OUTCOME_DISABLED_BY_KERNEL => 0,
                ];
                foreach ($reconTicks as $t) {
                    $o = (string) ($t['outcome'] ?? '');
                    if (isset($tally[$o])) {
                        $tally[$o]++;
                    }
                }
                $kernelViolations = 0;
                if ($this->kernel !== null) {
                    try {
                        $kernelViolations = count($this->kernel->listViolations());
                    } catch (\Throwable $e) {
                        $kernelViolations = -1; // honest unknown
                    }
                }
                $base['aurg_recent_count'] = count($aurgRecent);
                $base['reconciliation_tick_count'] = count($reconTicks);
                $base['reconciliation_outcome_tally'] = $tally;
                $base['kernel_violation_count'] = $kernelViolations;
                $base['last_aurg_tick_id'] = $aurgRecent === [] ? null : ($aurgRecent[count($aurgRecent) - 1]['tick_id'] ?? null);
                $base['note'] = 'telemetry_aggregated_from_runtime_state';
                break;

            default:
                $base['note'] = 'unknown_probe_kind';
                break;
        }

        $base['receipt_hash'] = 'sha256:'.hash('sha256', json_encode($base, JSON_THROW_ON_ERROR));

        return $base;
    }

    // ---------- internals ----------

    /**
     * Map cognitive-function-atlas group → ASCB-accepted group (subset that ASCB knows).
     * Unknown groups fall back to 'self_construction' so the propose() call succeeds.
     */
    private function mapGroupForAscb(string $cfaGroup): string
    {
        $known = AtlasSelfConstructionSubsystemBuilderService::VALID_GROUPS;
        if (in_array($cfaGroup, $known, true)) {
            return $cfaGroup;
        }
        // CFA may report group names that ASCB doesn't know (e.g., 'governance', 'autonomy', 'cognition').
        // Map them to 'self_construction' which is the canonical home for new subsystems.
        return 'self_construction';
    }

    /**
     * Deterministic acronym for the reconciled subsystem candidate. Idempotent
     * per group (so re-ticks on the same group don't generate duplicate proposals).
     */
    private function deriveSubsystemAcronym(string $group): string
    {
        $normalized = strtoupper(preg_replace('/[^a-z0-9]+/i', '_', $group) ?? $group);

        return 'RCN_'.$normalized;
    }

    /**
     * @param  array<string,mixed>|null  $step
     * @return array<string,mixed>
     */
    private function buildTick(
        string $at,
        string $selfModelHash,
        ?string $selectedGroup,
        int $gapSize,
        ?array $step,
        string $outcome,
    ): array {
        $tickId = 'rcn_'.substr(hash('sha256', $at.'|'.$selfModelHash.'|'.($selectedGroup ?? '').'|'.$outcome), 0, 12);
        $tick = [
            'schema_version' => self::TICK_SCHEMA,
            'tick_id' => $tickId,
            'at' => $at,
            'self_model_hash' => $selfModelHash,
            'selected_group' => $selectedGroup,
            'selected_gap_size' => $gapSize,
            'step' => $step,
            'outcome' => $outcome,
        ];
        $tick['tick_hash'] = 'sha256:'.hash('sha256', json_encode([
            'tick_id' => $tickId,
            'self_model_hash' => $selfModelHash,
            'selected_group' => $selectedGroup,
            'outcome' => $outcome,
        ], JSON_THROW_ON_ERROR));

        return $tick;
    }

}
