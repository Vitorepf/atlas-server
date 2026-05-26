<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AtlasDecide\AtlasSwarmConductorService;
use App\Services\Ai\CrossDomain\AtlasTemporaryDomainCompositionService;
use App\Services\Ai\Patamar4\AtlasPatamar4StateService;
use App\Services\Ai\Reconciliation\AtlasAutonomousReconciliationRuntimeService;
use App\Services\Ai\Teos\AtlasTeosI3CounterfactualService;
use App\Services\Ai\Teos\AtlasTeosI4CounterfactualTreeService;
use Illuminate\Console\Command;

/**
 * End-to-end smoke of the entire Patamar 4 loop.
 *
 *   php artisan atlas:patamar4:run-loop-once [--group=cognitive_immune] [--json]
 *
 * Triggers one tick of each of the 7 services. Each tick passes through
 * Constitutional Kernel + Autonomy Admission and produces real append-only
 * receipts on disk. Returns an aggregate envelope summarising what fired.
 *
 * The command is harmless — no provider calls, no DB mutation. Each
 * artifact (admission ticket, reconciliation tick, AURG-4D temporal tick,
 * ASCB proposal, TEOS-I4 tree, swarm dispatch, TDC capsule) is local-first
 * JSONL.
 */
class AtlasPatamar4RunLoopOnceCommand extends Command
{
    protected $signature = 'atlas:patamar4:run-loop-once
        {--group=cognitive_immune : Group to drive reconciliation against (operator-forced)}
        {--privacy=public : Privacy class for all gated operations}
        {--autonomy=autonomous : Requested autonomy for reconciliation tick}
        {--json : Emit a JSON envelope of all artifacts produced}';

    protected $description = 'Patamar 4 — fire one full loop iteration end-to-end (Reconciliation + TEOS-I4 + Swarm + TDC), returning all artifacts produced.';

    public function handle(
        AtlasAutonomousReconciliationRuntimeService $reconciliation,
        AtlasTeosI3CounterfactualService $teosI3,
        AtlasTeosI4CounterfactualTreeService $teosI4,
        AtlasSwarmConductorService $swarm,
        AtlasTemporaryDomainCompositionService $tdc,
        AtlasPatamar4StateService $state,
    ): int {
        $group = (string) $this->option('group');
        $privacy = (string) $this->option('privacy');
        $autonomy = (string) $this->option('autonomy');
        $json = (bool) $this->option('json');

        $artifacts = [];

        // 1. Reconciliation tick (drives CFA → Admission → Kernel → AURG-4D → ASCB).
        $artifacts['reconciliation_tick'] = $reconciliation->tick([
            'force_group' => $group,
            'privacy_class' => $privacy,
            'requested_autonomy' => $autonomy,
        ]);

        // 2. TEOS-I3 branch (with AURG-4D chain ON).
        $artifacts['teos_i3_branch'] = $teosI3->branch([
            'anchor_decision_id' => 'p4_smoke_anchor_'.bin2hex(random_bytes(4)),
            'alternative' => ['decision_kind' => 'policy_swap', 'value' => 'strict'],
            'factual_outcome_score' => 0.5,
            'projected_outcome_score' => 0.72,
            'scope' => ['privacy_class' => $privacy],
        ]);

        // 3. TEOS-I4 tree expansion.
        $artifacts['teos_i4_tree'] = $teosI4->expand([
            'anchor_decision_id' => $artifacts['teos_i3_branch']['anchor_decision_id'],
            'alternatives' => [
                ['decision_kind' => 'policy_swap', 'value' => 'strict'],
                ['decision_kind' => 'provider_swap', 'value' => 'local'],
            ],
            'max_breadth' => 2,
            'max_depth' => 2,
            'scope' => ['privacy_class' => $privacy],
            'factual_outcome_score' => 0.5,
            'projected_outcome_score' => 0.65,
        ]);

        // 4. Swarm dispatch (honest — may yield 0 arms if ADML has no evidence).
        $artifacts['swarm_dispatch'] = $swarm->dispatch([
            'task_category' => 'code_generation',
            'role' => 'primary',
            'parallelism' => 2,
            'scope' => ['privacy_class' => $privacy],
            'requested_autonomy' => 'execute_with_approval',
        ]);

        // 5. Temporary Domain Composition capsule.
        $artifacts['tdc_capsule'] = $tdc->compose([
            'domains' => ['engineering', 'governance'],
            'purpose' => 'patamar4_smoke',
            'privacy_class' => 'normal',
            'ttl_seconds' => 600,
            'actor' => 'operator',
            'requested_autonomy' => 'execute_with_approval',
            'rationale' => 'Patamar 4 end-to-end smoke run',
        ]);

        // 6. State snapshot (aggregate read-model).
        $artifacts['state_snapshot'] = $state->snapshot(3);

        $summary = [
            'schema_version' => 'atlas.patamar4.smoke_envelope.v1',
            'group' => $group,
            'privacy' => $privacy,
            'autonomy_requested' => $autonomy,
            'fired' => [
                'reconciliation_outcome' => $artifacts['reconciliation_tick']['outcome'],
                'reconciliation_aurg_tick_id' => $artifacts['reconciliation_tick']['step']['aurg_tick_id'] ?? null,
                'reconciliation_ascb_proposal_id' => $artifacts['reconciliation_tick']['step']['ascb_proposal_id'] ?? null,
                'teos_i3_branch_id' => $artifacts['teos_i3_branch']['branch_id'],
                'teos_i3_aurg_tick_id' => $artifacts['teos_i3_branch']['aurg_tick_id'] ?? null,
                'teos_i4_tree_id' => $artifacts['teos_i4_tree']['tree_id'],
                'teos_i4_admission_decision' => $artifacts['teos_i4_tree']['admission_decision'],
                'swarm_dispatch_id' => $artifacts['swarm_dispatch']['dispatch_id'],
                'swarm_effective_parallelism' => $artifacts['swarm_dispatch']['effective_parallelism'],
                'tdc_capsule_id' => $artifacts['tdc_capsule']['capsule_id'],
                'tdc_capsule_status' => $artifacts['tdc_capsule']['status'],
            ],
            'artifacts' => $artifacts,
        ];

        if ($json) {
            $this->line((string) json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } else {
            $this->line('Patamar 4 loop end-to-end smoke completed.');
            $this->line('  reconciliation.outcome    = '.$summary['fired']['reconciliation_outcome']);
            $this->line('  reconciliation.aurg_tick  = '.($summary['fired']['reconciliation_aurg_tick_id'] ?? '(noop)'));
            $this->line('  reconciliation.ascb_prop  = '.($summary['fired']['reconciliation_ascb_proposal_id'] ?? '(none)'));
            $this->line('  teos_i3.branch_id         = '.$summary['fired']['teos_i3_branch_id']);
            $this->line('  teos_i3.aurg_chain_tick   = '.($summary['fired']['teos_i3_aurg_tick_id'] ?? '(off)'));
            $this->line('  teos_i4.tree_id           = '.$summary['fired']['teos_i4_tree_id']);
            $this->line('  teos_i4.admission         = '.$summary['fired']['teos_i4_admission_decision']);
            $this->line('  swarm.dispatch_id         = '.$summary['fired']['swarm_dispatch_id']);
            $this->line('  swarm.parallelism         = '.$summary['fired']['swarm_effective_parallelism']);
            $this->line('  tdc.capsule_id            = '.$summary['fired']['tdc_capsule_id']);
            $this->line('  tdc.capsule_status        = '.$summary['fired']['tdc_capsule_status']);
        }

        return self::SUCCESS;
    }
}
