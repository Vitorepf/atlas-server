<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainModelCapabilityAmplifier;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainModelTierGovernanceRunner;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainScaffoldOverfitDetector;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Read-only operator entry point combining {@see AtlasExternalBrainModelCapabilityAmplifier}
 * (does the model+scaffold combination PROVE a real lift, or must it escalate to frontier/human
 * review?) with {@see AtlasExternalBrainScaffoldOverfitDetector} (is the claimed lift itself
 * overfit, low-confidence, or proxy-prone?) and
 * {@see AtlasExternalBrainModelTierGovernanceRunner} (quality SLO, calibrated tier, weakness guard,
 * escalation policy) — so a smaller model is only trusted to operate autonomously with scaffold
 * when ALL checks are clean.
 *
 * Never mutates files, calls providers, or runs git — read-only reporting only.
 *
 * Input: a single JSON file (--input=PATH) with keys:
 *   { amplifier:{...Amplifier input...}, scaffold_metrics, model_tier:{...GovernanceRunner input...} }
 * Missing/absent sections default to empty/defaults.
 */
final class AtlasExternalBrainModelAmplifierCommand extends Command
{
    use EmitsCanonicalJson;

    /** @var string */
    protected $signature = 'atlas:external-brain:model-amplifier
        {--input= : Path to a JSON file with amplifier, scaffold_metrics, and model_tier sections}';

    /** @var string */
    protected $description = 'Read-only model-capability amplification + scaffold-overfit + model-tier-governance report: proves when a smaller model can safely operate with scaffold, and when escalation is mandatory.';

    public function handle(
        AtlasExternalBrainModelCapabilityAmplifier $amplifier,
        AtlasExternalBrainScaffoldOverfitDetector $overfitDetector,
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

        $amplifierInput = is_array($decoded['amplifier'] ?? null) ? $decoded['amplifier'] : [];
        $scaffoldMetrics = is_array($decoded['scaffold_metrics'] ?? null) ? $decoded['scaffold_metrics'] : [];
        $modelTierInput = is_array($decoded['model_tier'] ?? null) ? $decoded['model_tier'] : [];

        $amplification = $amplifier->amplify($amplifierInput);
        $overfit = $overfitDetector->detect(['scaffold_metrics' => $scaffoldMetrics]);
        $tierGovernance = (new AtlasExternalBrainModelTierGovernanceRunner)->run($modelTierInput);

        // Mandatory escalation whenever EITHER check demands it.
        $mustEscalate = (bool) $amplification['escalation_recommendation']['escalate'] || $overfit['overfit_detected'];
        $safeToOperateAutonomously = $amplification['autonomous_execution_allowed'] && ! $overfit['overfit_detected'];

        $payload = [
            'status' => 'ok',
            'model_profile' => $amplification['model_profile'],
            'amplifier_escalation' => $amplification['escalation_recommendation'],
            'heldout_lift_proof' => $amplification['heldout_lift_proof'],
            'autonomous_execution_allowed' => $amplification['autonomous_execution_allowed'],
            'overfit_detected' => $overfit['overfit_detected'],
            'suspect_scaffolds' => $overfit['suspect_scaffolds'],
            'overfit_recommended_action' => $overfit['recommended_action'],
            'overfit_risk_score' => $overfit['risk_score'],
            'model_tier_governance' => $tierGovernance,
            'must_escalate' => $mustEscalate,
            'safe_to_operate_autonomously' => $safeToOperateAutonomously,
        ];

        $this->line($this->encode($payload));

        return self::SUCCESS;
    }
}
