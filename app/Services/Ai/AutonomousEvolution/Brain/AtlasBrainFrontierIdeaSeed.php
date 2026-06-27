<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * FRONTIER IDEA SEED — frontier-harvest organ. Second-generation seed list of brain ideas not
 * yet implemented (the original FrontierMethodCatalog filled to 12/12 — this lists the next
 * wave). Pure const so the brain can answer "what's next on my own horizon?" without scanning
 * docs or hallucinating fresh ideas every cycle.
 *
 * Pétreo: réu would erase ideas to hide its own unbuilt capabilities.
 */
final class AtlasBrainFrontierIdeaSeed
{
    public const SCHEMA = 'atlas.brain.frontier_idea_seed.v1';

    /** @var list<array{id:string, summary:string}> */
    private const SEEDS = [
        ['id' => 'kl_divergence_path_distribution', 'summary' => 'KL divergence of observed path distribution vs target (e.g. uniform)'],
        ['id' => 'per_scope_signal_correlation', 'summary' => 'cross-scope correlation matrix of perception signals'],
        ['id' => 'origination_seed_lineage_tree', 'summary' => 'per-seed lineage tree showing which originations spawned children'],
        ['id' => 'critic_independence_score', 'summary' => 'pairwise critic correlation; flag critics that always vote together'],
        ['id' => 'gate_throughput_meter', 'summary' => 'gates-per-cycle moving average; flag throughput collapse'],
        ['id' => 'evidence_window_overlap', 'summary' => 'detect findings whose evidence windows overlap (double-counted)'],
        ['id' => 'reflection_dedup_hash', 'summary' => 'content hash for reflection dedup beyond cycle_id'],
        ['id' => 'path_starvation_recovery_clock', 'summary' => 'time-to-first-pick after starvation alarm fires'],
        ['id' => 'organ_call_frequency_heatmap', 'summary' => 'per-organ call frequency over cycles; surface idle organs'],
        ['id' => 'composite_brain_grade', 'summary' => 'single A-F grade fusing coverage + diversity + calibration'],
    ];

    /**
     * @return list<array{id:string, summary:string}>
     */
    public function seeds(): array
    {
        return self::SEEDS;
    }

    public function count(): int
    {
        return count(self::SEEDS);
    }
}
