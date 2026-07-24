<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainArchitectureCompressionPlanner;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainComplexityDebtBurnDownPlanner;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOrganSprawlReductionPlanner;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainSimplificationRoiLedger;
use App\Services\Ai\SelfConstruction\Simplification\AtlasSelfConstructionSimplificationCampaignControlPlane;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Read-only simplification governor. Composes
 * {@see AtlasExternalBrainArchitectureCompressionPlanner} (delete/merge/simplify candidates,
 * worker-feed safety preserved), {@see AtlasExternalBrainComplexityDebtBurnDownPlanner} (ranked
 * debt burn-down with active-consumer/replacement-proof blocking),
 * {@see AtlasExternalBrainOrganSprawlReductionPlanner} (retire/merge/simplify/keep classification +
 * task-feed impact) and {@see AtlasExternalBrainSimplificationRoiLedger} (approved vs refused ROI)
 * into one governed simplification report, so structural sprawl never accumulates unchecked and
 * every consolidation wave preserves worker-feed safety.
 *
 * Never enqueues, mutates evidence, calls providers, or runs git — read-only reporting only.
 *
 * Input: a single JSON file (--input=PATH) with keys:
 *   { architecture_inventory:object, complexity_candidates:object, sprawl_organs:object, roi_candidates:object }
 * Missing/absent sections default to empty and simply produce no findings for that side.
 */
final class AtlasExternalBrainSimplificationGovernorCommand extends Command
{
    use EmitsCanonicalJson;

    private const SCHEMA = 'atlas.external_brain.simplification_governor.v1';

    /** @var string */
    protected $signature = 'atlas:external-brain:simplification-governor
        {--input= : Path to a JSON file with architecture_inventory, complexity_candidates, sprawl_organs, roi_candidates}';

    /** @var string */
    protected $description = 'Read-only: simplification governor (architecture compression + complexity burn-down + organ sprawl reduction + ROI ledger).';

    public function handle(
        AtlasExternalBrainArchitectureCompressionPlanner $compressionPlanner,
        AtlasExternalBrainComplexityDebtBurnDownPlanner $burnDownPlanner,
        AtlasExternalBrainOrganSprawlReductionPlanner $sprawlPlanner,
        AtlasExternalBrainSimplificationRoiLedger $roiLedger,
        AtlasSelfConstructionSimplificationCampaignControlPlane $campaignControlPlane,
    ): int {
        $inputPath = trim((string) $this->option('input'));
        if ($inputPath === '' || ! is_file($inputPath)) {
            $this->error('--input=<path> required and must exist');

            return self::FAILURE;
        }

        $decoded = json_decode((string) file_get_contents($inputPath), true);
        if (! is_array($decoded)) {
            $this->error('invalid input JSON');

            return self::FAILURE;
        }

        $architectureInventory = is_array($decoded['architecture_inventory'] ?? null) ? $decoded['architecture_inventory'] : [];
        $complexityCandidates = is_array($decoded['complexity_candidates'] ?? null) ? $decoded['complexity_candidates'] : [];
        $sprawlOrgans = is_array($decoded['sprawl_organs'] ?? null) ? $decoded['sprawl_organs'] : [];
        $roiCandidates = is_array($decoded['roi_candidates'] ?? null) ? $decoded['roi_candidates'] : [];

        $compressionPlan = $compressionPlanner->plan($architectureInventory);
        $burnDownPlan = $burnDownPlanner->plan($complexityCandidates);
        $sprawlPlan = $sprawlPlanner->plan($sprawlOrgans);
        $roiRecord = $roiLedger->record($roiCandidates);

        // Optional: bridge the governed circuit-consolidation (Self-Construction) campaign decision
        // into the same operator surface. Absent when self_construction_campaign isn't supplied —
        // never suppresses the pre-existing compression/burn-down/sprawl/ROI output either way.
        $selfConstructionControlPlane = null;
        if (is_array($decoded['self_construction_campaign'] ?? null)) {
            $decision = $campaignControlPlane->decide($decoded['self_construction_campaign']);
            $selfConstructionControlPlane = [
                'decision' => $decision['decision'],
                'reasons' => $decision['reasons'],
                'next_action' => $decision['next_action'],
                'proof_readiness' => $decision['proof_readiness'],
                'rollback_readiness' => $decision['rollback_readiness'],
                'docs_sync_required' => $decision['docs_sync_required'],
            ];
        }

        $payload = [
            'schema' => self::SCHEMA,
            'compression_plan' => $compressionPlan,
            'complexity_burn_down' => $burnDownPlan,
            'organ_sprawl_reduction' => $sprawlPlan,
            'simplification_roi' => $roiRecord,
            'first_safe_consolidation_wave' => $sprawlPlan['first_safe_batch'],
            'task_feed_impact' => $sprawlPlan['task_feed_impact'],
            'worker_feed_blocked_deletions' => array_values(array_filter(
                $compressionPlan['candidates'],
                static fn (array $c): bool => ($c['reason'] ?? '') === 'worker_feed_capacity_protected',
            )),
            'approved_roi' => $roiRecord['approved_roi'],
            'refused_roi' => $roiRecord['refused_roi'],
        ];

        if ($selfConstructionControlPlane !== null) {
            $payload['self_construction_control_plane'] = $selfConstructionControlPlane;
        }

        $this->line($this->encode($payload));

        return self::SUCCESS;
    }
}
