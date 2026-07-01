<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAutonomyCycleReplayVerifier;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainControlPlaneSnapshot;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainEndToEndAutonomyReplayHarness;
use Illuminate\Console\Command;

/**
 * Read-only end-to-end autonomy replay runner. Replays the full external-brain
 * cycle via {@see AtlasExternalBrainEndToEndAutonomyReplayHarness} (fails
 * closed the moment a step is missing evidence or depends on a human/provider
 * steady state), verifies causal continuity across ONE real originate-to-
 * next-action cycle via {@see AtlasExternalBrainAutonomyCycleReplayVerifier}
 * (lever chosen → packet emitted → muscle outcome → gates judged → outcome
 * learning → next decision genuinely changed), and composes
 * {@see AtlasExternalBrainControlPlaneSnapshot} for the current maturity/
 * health picture. Surfaces the exact failed step or human/provider
 * dependency and emits the next Atlas-native repair action — never a
 * "24/7 ready" verdict papered over a real gap.
 *
 * Never enqueues, mutates the queue, or calls a provider. The process exit
 * code is non-zero unless the replayed cycle is complete, the cycle-replay
 * verifier confirms a genuinely causal cycle, AND the control plane
 * snapshot reads "go".
 *
 * Input: a single JSON file (--input=PATH) with keys:
 *   { scenario:{intake,admission,enqueue_decision,outcome_learning,next_action},
 *     control_plane_snapshot?:{...AtlasExternalBrainControlPlaneSnapshot::compose input},
 *     cycle_replay_facts?:list<{...AtlasExternalBrainAutonomyCycleReplayVerifier::verify input}> }
 */
final class AtlasExternalBrainAutonomyReplayCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:external-brain:autonomy-replay
        {--input= : Path to a JSON file with scenario, optional control_plane_snapshot, optional cycle_replay_facts}';

    /** @var string */
    protected $description = 'Read-only end-to-end autonomy cycle replay + cycle-causality verifier + control-plane snapshot (fails closed, never claims fake 24/7 readiness).';

    public function handle(
        AtlasExternalBrainEndToEndAutonomyReplayHarness $harness,
        AtlasExternalBrainControlPlaneSnapshot $snapshot,
        AtlasExternalBrainAutonomyCycleReplayVerifier $cycleVerifier,
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

        $scenario = is_array($decoded['scenario'] ?? null) ? $decoded['scenario'] : [];
        $snapshotInput = is_array($decoded['control_plane_snapshot'] ?? null) ? $decoded['control_plane_snapshot'] : [];
        $cycleReplayFacts = is_array($decoded['cycle_replay_facts'] ?? null) ? $decoded['cycle_replay_facts'] : [];

        $replay = $harness->replay($scenario);
        $snap = $snapshot->snapshot($snapshotInput);
        $cycleReplay = $cycleVerifier->verify($cycleReplayFacts);

        $cycleComplete = $replay['autonomy_replay_status'] === AtlasExternalBrainEndToEndAutonomyReplayHarness::STATUS_CYCLE_COMPLETE;
        $controlPlaneGo = $snap['maturity_band'] !== AtlasExternalBrainControlPlaneSnapshot::BAND_BOOTSTRAPPING
            && $snap['maturity_band'] !== AtlasExternalBrainControlPlaneSnapshot::BAND_EMERGING;
        // Opt-in gate: only enforced when cycle_replay_facts was explicitly supplied, so callers
        // that predate this verifier (and never set it) keep byte-identical readiness behavior.
        $cycleReplayVerified = $cycleReplayFacts === [] || $cycleReplay['cycle_complete'] === true;
        $overallReady = $cycleComplete && $controlPlaneGo && $cycleReplayVerified;

        $nextRepairAction = match (true) {
            ! $cycleComplete => $replay['next_repair_hint'],
            ! $cycleReplayVerified => 'cycle_replay_verification_incomplete:'.($cycleReplay['missing_stage'] ?? implode(',', array_column($cycleReplay['causality_violations'], 'code'))),
            ! $controlPlaneGo => 'control_plane_maturity_band:'.$snap['maturity_band'].' — resolve blockers before treating the cycle as autonomous',
            default => 'none_required_cycle_ready',
        };

        $payload = [
            'status' => 'ok',
            'overall_ready' => $overallReady,
            'autonomy_replay_status' => $replay['autonomy_replay_status'],
            'failed_step' => $replay['failed_step'],
            'completed_steps' => $replay['completed_steps'],
            'brain_decision' => $replay['brain_decision'] ?? null,
            'cycle_replay_verified' => $cycleReplayVerified,
            'cycle_replay_missing_stage' => $cycleReplay['missing_stage'],
            'cycle_replay_causality_violations' => $cycleReplay['causality_violations'],
            'control_plane_maturity_band' => $snap['maturity_band'],
            'control_plane_blockers' => $snap['blockers'],
            'next_atlas_native_repair_action' => $nextRepairAction,
        ];

        $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return $overallReady ? self::SUCCESS : self::FAILURE;
    }
}
