<?php

declare(strict_types=1);

namespace App\Services\Ai\Reconciliation;

use App\Services\Ai\Cognition\AtlasCognitiveFunctionAtlasService;
use App\Services\Ai\Governance\AtlasAutonomyAdmissionService;
use App\Services\Ai\Reality\AtlasUnifiedRealityGraphTemporalService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionSubsystemBuilderService;
use App\Services\Ai\Teos\AtlasTeosI3CounterfactualService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\File;

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

    public const META_PROJECTION_MIN_IMPROVEMENT = 0.05;

    private ?string $ticksLogOverride = null;

    private ?AtlasTeosI3CounterfactualService $teosI3 = null;

    public function __construct(
        private readonly AtlasCognitiveFunctionAtlasService $cfa,
        private readonly AtlasAutonomyAdmissionService $admission,
        private readonly AtlasUnifiedRealityGraphTemporalService $aurg,
        private readonly AtlasSelfConstructionSubsystemBuilderService $ascb,
    ) {}

    /**
     * Optional meta-cognition seam. When wired, Reconciliation projects the
     * outcome of a candidate ASCB proposal via TEOS-I3 before firing it.
     * If projected improvement < META_PROJECTION_MIN_IMPROVEMENT, the
     * propose() call is suppressed (recorded as 'projection_below_threshold').
     */
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
            $tick = $this->buildTick(
                at: $at,
                selfModelHash: $selfModelHash,
                selectedGroup: null,
                gapSize: 0,
                step: null,
                outcome: self::OUTCOME_NOOP_NO_GAP,
            );
            $this->appendJsonl($this->ticksLogPath(), $tick);

            return $tick;
        }

        $group = (string) $top['group'];
        $gapSize = (int) $top['non_ready_pipeline'];
        $privacy = (string) ($context['privacy_class'] ?? 'normal');

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
            if ($this->teosI3 !== null) {
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
                $ascbProposalHash = 'sha256:suppressed_projection_below_threshold';
            }
        }

        $step = [
            'schema_version' => self::STEP_SCHEMA,
            'step_kind' => 'stabilize_pipeline',
            'scope' => $change['scope'],
            'admission_envelope' => $admission,
            'aurg_tick_id' => (string) ($aurgTick['tick_id'] ?? ''),
            'aurg_tick_hash' => (string) ($aurgTick['tick_hash'] ?? ''),
            'ascb_proposal_id' => $ascbProposalId,
            'ascb_proposal_hash' => $ascbProposalHash,
            'projection_branch_id' => $projectionBranchId,
            'projection_improvement_delta' => $projectionImprovement !== null ? round($projectionImprovement, 4) : null,
        ];

        $tick = $this->buildTick(
            at: $at,
            selfModelHash: $selfModelHash,
            selectedGroup: $group,
            gapSize: $gapSize,
            step: $step,
            outcome: $outcome,
        );
        $this->appendJsonl($this->ticksLogPath(), $tick);

        return $tick;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listTicks(): array
    {
        return $this->readJsonl($this->ticksLogPath());
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

    /**
     * @return list<array<string,mixed>>
     */
    private function readJsonl(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        $out = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }

        return $out;
    }

    private function appendJsonl(string $path, array $payload): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            if (function_exists('app')) {
                File::ensureDirectoryExists($dir);
            } else {
                @mkdir($dir, 0775, true);
            }
        }
        $fp = fopen($path, 'ab');
        if ($fp === false) {
            throw new \RuntimeException("Could not open {$path} for writing.");
        }
        try {
            if (flock($fp, LOCK_EX)) {
                fwrite($fp, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
                fflush($fp);
                flock($fp, LOCK_UN);
            }
        } finally {
            fclose($fp);
        }
    }
}
