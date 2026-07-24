<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainBacklogCostModel;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCapabilityGapTaskChainCompiler;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainConvergenceCriteriaCompiler;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainEnqueueValueThrottle;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainQueueSaturationStopPolicy;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainRunPolicyCompiler;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainTaskFamilyYieldModel;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Read-only autonomy-governor report combining {@see AtlasExternalBrainRunPolicyCompiler}
 * (compile+evaluate run contract), {@see AtlasExternalBrainQueueSaturationStopPolicy}
 * (enqueue vs consolidate/audit), {@see AtlasExternalBrainTaskFamilyYieldModel} (which
 * families actually deliver value) and {@see AtlasExternalBrainEnqueueValueThrottle}
 * (final fail-closed batch gate) into one continue/pause/self-heal/trim/pivot verdict —
 * so the originator's continuation decision is driven by real family yield and queue
 * saturation, not ad hoc heuristics.
 *
 * Never mutates files, calls providers, or runs git — read-only reporting only.
 *
 * Input: a single JSON file (--input=PATH) with keys:
 *   { run_policy_config:{...}, run_state:{...}, saturation:{...}, families:{...}, throttle:{...}, convergence:{...} }
 * Missing/absent sections default to empty and produce a conservative report.
 */
final class AtlasExternalBrainAutonomyGovernorCommand extends Command
{
    use EmitsCanonicalJson;

    /** @var string */
    protected $signature = 'atlas:external-brain:autonomy-governor
        {--input= : Path to a JSON file with run_policy_config, run_state, saturation, families and throttle sections}';

    /** @var string */
    protected $description = 'Read-only autonomy-governor report: run policy compliance, queue saturation, task-family yield, and enqueue value throttle.';

    public function handle(
        AtlasExternalBrainRunPolicyCompiler $runPolicyCompiler,
        AtlasExternalBrainQueueSaturationStopPolicy $saturationPolicy,
        AtlasExternalBrainTaskFamilyYieldModel $yieldModel,
        AtlasExternalBrainEnqueueValueThrottle $throttle,
        AtlasExternalBrainBacklogCostModel $backlogCostModel,
        AtlasExternalBrainConvergenceCriteriaCompiler $convergenceCompiler,
        AtlasExternalBrainCapabilityGapTaskChainCompiler $capabilityGapTaskChainCompiler,
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

        $runPolicyConfig = is_array($decoded['run_policy_config'] ?? null) ? $decoded['run_policy_config'] : [];
        $runState = is_array($decoded['run_state'] ?? null) ? $decoded['run_state'] : [];
        $saturationInput = is_array($decoded['saturation'] ?? null) ? $decoded['saturation'] : [];
        $familiesFacts = is_array($decoded['families'] ?? null) ? $decoded['families'] : [];
        $throttleInput = is_array($decoded['throttle'] ?? null) ? $decoded['throttle'] : [];
        $backlogCostInput = is_array($decoded['backlog_cost'] ?? null) ? $decoded['backlog_cost'] : [];
        $convergenceInput = is_array($decoded['convergence'] ?? null) ? $decoded['convergence'] : [];
        $capabilityGapsInput = is_array($decoded['capability_gaps'] ?? null) ? $decoded['capability_gaps'] : [];

        $compiledPolicy = $runPolicyCompiler->compile($runPolicyConfig);
        $runEvaluation = $runPolicyCompiler->evaluate($compiledPolicy, $runState);
        $saturation = $saturationPolicy->evaluate($saturationInput);
        $yield = $yieldModel->model($familiesFacts);
        $throttleResult = $throttle->throttle($throttleInput);
        $backlogCost = $backlogCostModel->model($backlogCostInput);
        $convergence = $convergenceCompiler->compile($convergenceInput);
        $capabilityGapTaskChain = $capabilityGapTaskChainCompiler->compile(['gaps' => $capabilityGapsInput]);

        $shouldPause = ! $runEvaluation['can_stop'] && $runEvaluation['violations'] !== [];
        $shouldSelfHeal = ($saturation['decision'] ?? null) === 'unblock_first';
        $shouldTrimBatch = $throttleResult['trimmed_task_ids'] !== [];
        $shouldPivotFamilies = $yield['low_yield_families'] !== [];

        $governorAction = match (true) {
            $shouldSelfHeal => 'self_heal',
            $runEvaluation['can_stop'] && ! $shouldPause => 'stop',
            $throttleResult['allow_enqueue'] === false && $shouldTrimBatch => 'trim_batch_volume',
            $throttleResult['allow_enqueue'] === false => 'pause',
            $shouldPivotFamilies => 'pivot_families',
            default => 'continue',
        };

        $payload = [
            'status' => 'ok',
            'run_policy' => $compiledPolicy,
            'run_evaluation' => $runEvaluation,
            'queue_saturation' => $saturation,
            'family_yield' => $yield,
            'enqueue_throttle' => $throttleResult,
            'backlog_cost' => $backlogCost,
            'convergence' => $convergence,
            'capability_gap_task_chain' => $capabilityGapTaskChain,
            'governor_action' => $governorAction,
        ];

        $this->line($this->encode($payload));

        return self::SUCCESS;
    }
}
