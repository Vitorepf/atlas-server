<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\LoadsFactsFileOption;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainBreakthroughPlanner;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCapabilityMapDriftDetector;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCapabilityDriftWorkProposer;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainDomainWaveReadinessManifest;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainEvidenceFreshnessBackfillPlanner;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainMaturityGapIndex;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Read-only operator surface: atlas:external-brain:domain-map
 *
 * Fuses four already-pure ExternalBrain organs into one domain-map runtime
 * so the originator targets missing proof and stale domains instead of
 * vanity queue growth:
 *
 *   - {@see AtlasExternalBrainMaturityGapIndex}               — final-95 maturity gaps (leverage + proof_gap)
 *   - {@see AtlasExternalBrainCapabilityMapDriftDetector}     — capability-map drift vs. queued areas
 *   - {@see AtlasExternalBrainEvidenceFreshnessBackfillPlanner} — stale/missing evidence backfill plan
 *   - {@see AtlasExternalBrainBreakthroughPlanner}            — quota-stall investigation plan
 *
 * Pure composition: no enqueue, no queue mutation, no provider calls. All
 * facts are supplied via a JSON facts file.
 *
 * Output (always JSON):
 *   schema, priority_target, reasons, maturity_gaps, capability_drift,
 *   evidence_backfill, breakthrough_plan
 */
final class AtlasExternalBrainDomainMapCommand extends Command
{
    use LoadsFactsFileOption;

    use EmitsCanonicalJson;

    private const SCHEMA = 'atlas.external_brain.domain_map.v1';

    /** @var string */
    protected $signature = 'atlas:external-brain:domain-map
        {--facts-file= : Path to a JSON facts file}
        {--json : Emit JSON output (always on)}';

    /** @var string */
    protected $description = 'Read-only: fuse maturity gaps, capability-map drift, evidence backfill, and breakthrough planning into one domain-map verdict.';

    public function handle(): int
    {
        $facts = $this->loadFacts();

        $maturityGapIndex = new AtlasExternalBrainMaturityGapIndex;
        $driftDetector = new AtlasExternalBrainCapabilityMapDriftDetector;
        $backfillPlanner = new AtlasExternalBrainEvidenceFreshnessBackfillPlanner;
        $breakthroughPlanner = new AtlasExternalBrainBreakthroughPlanner;
        $driftWorkProposer = new AtlasExternalBrainCapabilityDriftWorkProposer;
        $domainWaveReadiness = new AtlasExternalBrainDomainWaveReadinessManifest;

        $maturityGaps = $maturityGapIndex->compute(
            is_array($facts['rubric'] ?? null) ? $facts['rubric'] : [],
            is_array($facts['control_plane_snapshot'] ?? null) ? $facts['control_plane_snapshot'] : [],
        );

        $capabilityDrift = $driftDetector->detect([
            'map_entries' => $facts['map_entries'] ?? [],
            'queued_areas' => $facts['queued_areas'] ?? [],
            'outcomes' => $facts['outcomes'] ?? [],
        ]);

        $evidenceBackfill = $backfillPlanner->plan([
            'evidence_streams' => $facts['evidence_streams'] ?? [],
            'now_unix' => $facts['now_unix'] ?? 0,
        ]);

        $breakthroughPlan = $breakthroughPlanner->plan([
            'verified_count' => $facts['verified_count'] ?? 0,
            'requested_target' => $facts['requested_target'] ?? 1,
            'escalation_state' => $facts['escalation_state'] ?? [],
            'candidate_strategy' => is_string($facts['candidate_strategy'] ?? null) ? $facts['candidate_strategy'] : '',
            'backlog_freshness_facts' => is_array($facts['backlog_freshness_facts'] ?? null) ? $facts['backlog_freshness_facts'] : [],
        ]);

        $driftWork = $driftWorkProposer->propose([
            'findings' => $capabilityDrift['findings'] ?? [],
            'frozen_facts' => $facts['frozen_facts'] ?? [],
            'current_facts' => $facts['current_facts'] ?? [],
            'target_paths' => $facts['target_paths'] ?? [],
            'existing_proposals' => $facts['existing_proposals'] ?? [],
            'active_claims' => $facts['active_claims'] ?? [],
            'active_reservations' => $facts['active_reservations'] ?? [],
        ]);

        $waveReadiness = $domainWaveReadiness->evaluate([
            'requested_wave' => $facts['requested_wave'] ?? 1,
            'waves' => $facts['waves'] ?? [],
        ]);

        // Priority target: missing evidence and high-impact capability drift always come before
        // originating more work off an unproven or stale domain map.
        $hasMissingEvidence = (bool) ($evidenceBackfill['is_backfill_needed'] ?? false);
        $hasHighImpactDrift = array_values(array_filter(
            (array) ($capabilityDrift['findings'] ?? []),
            static fn (array $f): bool => ($f['impact_level'] ?? '') === 'high',
        )) !== [];
        $topGap = ($maturityGaps['gaps'][0] ?? null);

        $reasons = array_values(array_filter([
            $hasMissingEvidence ? 'evidence_backfill_required' : null,
            $hasHighImpactDrift ? 'high_impact_capability_drift_present' : null,
            $topGap !== null ? 'unproven_maturity_gap:'.$topGap['dimension'] : null,
        ]));

        $priorityTarget = match (true) {
            $hasMissingEvidence => 'backfill_evidence_first',
            $hasHighImpactDrift => 'repair_capability_map_drift_first',
            $topGap !== null => 'target_maturity_gap:'.$topGap['dimension'],
            default => 'domain_map_clean_continue_normal_origination',
        };

        $payload = [
            'schema' => self::SCHEMA,
            'priority_target' => $priorityTarget,
            'reasons' => $reasons === [] ? ['no_gap_or_drift_signal'] : $reasons,
            'maturity_gaps' => $maturityGaps,
            'capability_drift' => $capabilityDrift,
            'drift_work' => $driftWork,
            'domain_wave_readiness' => $waveReadiness,
            'evidence_backfill' => $evidenceBackfill,
            'breakthrough_plan' => $breakthroughPlan,
        ];

        $this->line($this->encode($payload));

        return self::SUCCESS;
    }

    /** @return array<string,mixed> */
}
