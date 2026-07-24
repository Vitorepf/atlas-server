<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainControlPlaneConvergenceRuntimeBridge;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainControlPlaneIntegrationGate;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainControlPlaneStopGoBridge;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Read-only convergence audit: uses {@see AtlasExternalBrainControlPlaneIntegrationGate} to check
 * whether important organs are actually wired into the control plane (not ornamental helpers), then
 * feeds the resulting integration_coverage_percent into {@see AtlasExternalBrainControlPlaneStopGoBridge}
 * so the stop/go decision is driven by real integrated proof, not a self-declared "done".
 *
 * Never enqueues, mutates evidence, calls providers, or runs git — read-only reporting only.
 *
 * Input: a single JSON file (--input=PATH) with keys:
 *   { integration_gate:{organs:list}, stop_go_bridge:{...} }
 * stop_go_bridge.integration_coverage_percent, when absent, defaults to the gate's computed
 * integration_coverage_percent so the stop-go path always uses real integrated proof.
 */
final class AtlasExternalBrainControlPlaneConvergenceCommand extends Command
{
    use EmitsCanonicalJson;

    /** @var string */
    protected $signature = 'atlas:external-brain:control-plane-convergence
        {--input= : Path to a JSON file with integration_gate and stop_go_bridge sections}';

    /** @var string */
    protected $description = 'Read-only control-plane convergence audit (organ integration gate feeds the stop-go bridge with real integrated proof).';

    public function handle(
        AtlasExternalBrainControlPlaneIntegrationGate $integrationGate,
        AtlasExternalBrainControlPlaneStopGoBridge $stopGoBridge,
        AtlasExternalBrainControlPlaneConvergenceRuntimeBridge $runtimeBridge,
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

        $gateSection = is_array($decoded['integration_gate'] ?? null) ? $decoded['integration_gate'] : [];
        $organs = is_array($gateSection['organs'] ?? null) ? $gateSection['organs'] : [];

        $bridgeInput = is_array($decoded['stop_go_bridge'] ?? null) ? $decoded['stop_go_bridge'] : [];

        $gateResult = $integrationGate->evaluateBatch($organs);

        // Convergence: the stop-go path must use REAL integrated proof, not an ornamental
        // self-declared coverage figure — default to the gate's computed coverage whenever the
        // caller has not explicitly overridden it.
        if (! array_key_exists('integration_coverage_percent', $bridgeInput)) {
            $bridgeInput['integration_coverage_percent'] = $gateResult['integration_coverage_percent'];
        }

        $bridgeResult = $stopGoBridge->decide($bridgeInput);

        $runtimeSignals = $runtimeBridge->translate([
            'total_organs' => $gateResult['total_organs'],
            'integration_coverage_percent' => $gateResult['integration_coverage_percent'],
            'blocked_organs' => $gateResult['blocked_organs'],
            'stop_go_decision' => $bridgeResult['stop_go_decision'],
            'stop_go_reasons' => $bridgeResult['reasons'],
            'next_action' => $bridgeResult['next_action'],
        ]);

        $payload = [
            'status' => 'ok',
            'total_organs' => $gateResult['total_organs'],
            'integration_coverage_percent' => $gateResult['integration_coverage_percent'],
            'delivered_organs' => $gateResult['delivered_organs'],
            'blocked_organs' => $gateResult['blocked_organs'],
            'integration_blockers_by_organ' => $gateResult['integration_blockers_by_organ'],
            'stop_go_decision' => $bridgeResult['stop_go_decision'],
            'stop_go_signal' => $bridgeResult['stop_go_signal'],
            'stop_go_reasons' => $bridgeResult['reasons'],
            'next_action' => $bridgeResult['next_action'],
            'simplification_pressure' => $runtimeSignals['simplification_pressure'],
            'blocked_organ_ratio' => $runtimeSignals['blocked_organ_ratio'],
        ];

        $this->line($this->encode($payload));

        return self::SUCCESS;
    }
}
