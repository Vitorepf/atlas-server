<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

/**
 * Immutable value object for ONE autonomous multi-file obra CANDIDATE detected by
 * {@see AtlasLoopObraClusterDetectorService} from the live loop's leverage signals.
 *
 * It is a PROPOSAL of work, NEVER the work itself: it carries a hub file, the >=2 genuinely
 * coupled files (hub + its MEASURED production callers), the deterministic cluster hash that
 * dedupes re-proposals, and the leverage rationale (the measured numbers that fired). It holds
 * NO code content, NO provider data, NO acceptance numbers and NO completion claim — the actual
 * cross-file refactor stays Forge-class, operator-authorized, behind the never-merge floor.
 */
final class AtlasLoopObraClusterCandidate
{
    public const SHAPE = 'obra_candidate';

    public const OBJECTIVE_KIND = 'refactor_reduce_complexity';

    /**
     * @param  list<string>  $allowedFiles  hub + measured caller rel paths (>=2, sorted, deduped)
     * @param  array<string,mixed>  $leverageSignals  the measured numbers (cyclomatic, refactor_leverage, caller_count)
     * @param  array<string,mixed>  $routingRationale  which thresholds fired and their measured values
     */
    public function __construct(
        public readonly string $hubPath,
        public readonly array $allowedFiles,
        public readonly string $clusterHash,
        public readonly array $leverageSignals,
        public readonly array $routingRationale,
    ) {}

    /**
     * Build the candidate from the hub + its measured caller paths. Returns null when the
     * cluster cannot honestly be formed (fewer than 2 distinct files after dedup) — the caller
     * then emits NO candidate (the >=2-file floor invariant lives here, single source).
     *
     * @param  list<string>  $callerPaths  MEASURED production caller rel paths for the hub
     * @param  array<string,mixed>  $leverageSignals
     * @param  array<string,mixed>  $routingRationale
     */
    public static function fromHub(string $hubPath, array $callerPaths, array $leverageSignals, array $routingRationale): ?self
    {
        $hubPath = ltrim(trim($hubPath), '/');
        if ($hubPath === '') {
            return null;
        }

        $files = [$hubPath => true];
        foreach ($callerPaths as $caller) {
            if (! is_string($caller)) {
                continue;
            }
            $caller = ltrim(trim($caller), '/');
            if ($caller !== '') {
                $files[$caller] = true;
            }
        }
        $allowedFiles = array_keys($files);
        sort($allowedFiles);

        // The >=2 coupled-file invariant: a single-file "cluster" is never an obra candidate
        // (it would be a plain single-file refactor, which the existing lanes already cover).
        if (count($allowedFiles) < 2) {
            return null;
        }

        // Deterministic hash: the sorted file set + the structural complexity total. Stable
        // across cycles for the SAME cluster (so the cooldown dedup bites), distinct when the
        // file set or complexity changes (so genuinely new work is not suppressed).
        $clusterHash = hash('sha256', implode('|', $allowedFiles).'#'.(string) ($leverageSignals['cyclomatic_total'] ?? 0));

        return new self($hubPath, $allowedFiles, $clusterHash, $leverageSignals, $routingRationale);
    }

    /**
     * Shape the createProposal envelope for the SHARED operator-review backlog
     * ({@see \App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalBacklogService}) — the
     * SAME door {@see \App\Services\Ai\AutonomousEvolution\AtlasLoopAutoArchitectureProposalService}
     * uses, so both big-work producers (scheduled module-hotspot + live grind-loop hub-cluster)
     * feed ONE queue (anti-fragmentation). The coupled files go in `allowed_paths` (a recognized
     * persisted field); a nested `obra_cluster_candidate` block carries full fidelity for display.
     *
     * @return array<string,mixed>
     */
    public function toBacklogProposalPayload(): array
    {
        $hubName = basename($this->hubPath);
        $callerCount = max(0, count($this->allowedFiles) - 1);

        return [
            'title' => 'Multi-file obra candidate: simplify high-leverage hub '.$hubName.' + '.$callerCount.' coupled caller(s)',
            'problem_statement' => 'The Evolution Loop measured a WIRED high-complexity hub ('.$this->hubPath.') with '
                .$callerCount.' production caller(s); a coordinated cross-file simplification spanning '
                .count($this->allowedFiles).' files pays back more than isolated micro-fixes, but exceeds the loop\'s '
                .'single-file grind and must be reviewed as an obra.',
            'business_rule' => 'Multi-file loop-detected obra candidates are PROPOSAL-ONLY: operator review (and, for execution, '
                .'L4-10 real-execution certification) is required before any code change; the loop never auto-applies or merges them.',
            'target_capability' => 'loop_live_signal_obra_candidate',
            'why_now' => 'The 24/7 grind loop now surfaces leverage-ranked big-work candidates from real caller+complexity signals '
                .'instead of only micro-fixes, while preserving the never-merge / operator-review floor.',
            'expected_power_gain' => 'coordinated_simplification_of_a_measured_hub_cluster_under_review_gates',
            'success_metrics' => [
                'wired_hub_selected_from_measured_caller_and_complexity_signals',
                'coupled_cluster_has_two_or_more_files',
                'candidate_parked_for_operator_review_no_code_applied',
                'never_merge_and_l4_10_gates_unchanged',
            ],
            'acceptance_gates' => [
                'operator_review_recorded_before_execution',
                'l4_10_real_execution_receipt_required_before_any_merge',
            ],
            'canonical_docs' => [
                'docs/engineering-knowledge-base/atlas-programming-forge-flow.md',
            ],
            'allowed_paths' => $this->allowedFiles,
            'forbidden_paths' => [
                'app/Services/Ai/AutonomousEvolution/AtlasLoopHarnessGuard.php',
                'app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php',
                'app/Services/Ai/AutonomousEvolution/AtlasLoopAutoMergeService.php',
            ],
            'risk_level' => 'high',
            'human_review_required' => true,
            'autopromotion_requested' => false,
            'requires_provider_cost_approval' => true,
            // Full-fidelity provenance so operators / tests can filter loop-produced obra
            // candidates deterministically (the backlog item persists recognized fields; this
            // nested block is the canonical record alongside the detector's own durable index).
            'obra_cluster_candidate' => [
                'schema_version' => 'atlas.loop.obra_cluster_candidate.v1',
                'producer' => 'atlas_loop_obra_cluster_detector',
                'shape' => self::SHAPE,
                'objective_kind' => self::OBJECTIVE_KIND,
                'hub_path' => $this->hubPath,
                'allowed_files' => $this->allowedFiles,
                'cluster_hash' => $this->clusterHash,
                'leverage_signals' => $this->leverageSignals,
                'routing_rationale' => $this->routingRationale,
                'never_merge_changed' => false,
                'code_mutated' => false,
                'provider_call_made' => false,
            ],
        ];
    }
}
