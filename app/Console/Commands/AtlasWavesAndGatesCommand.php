<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasWavesAndGatesService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Legacy Cleanup Waves And Gates sequencer CLI.
 *
 *   php artisan atlas:aaeos:waves-and-gates [--json]
 *
 * Read-only and deterministic. Evaluates one cleanup wave's gate and emits the
 * verdict (pass | blocked), the allowed next wave and an audit receipt. With the
 * safe default (Wave 0, gate not yet satisfied) it demonstrates the contract: an
 * unsatisfied gate is `blocked` and the session may NOT advance to the next wave.
 *
 * @see docs/engineering-knowledge-base/legacy-cleanup/waves-and-gates.md
 */
class AtlasWavesAndGatesCommand extends Command
{
    protected $signature = 'atlas:aaeos:waves-and-gates {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas legacy-cleanup · waves and gates sequencer (pass|blocked) for one cleanup wave.';

    public function handle(AtlasWavesAndGatesService $service): int
    {
        try {
            // Safe default: Wave 0 with its gate not yet satisfied — the gate must
            // be blocked and the session may NOT advance to Wave 1.
            $decision = $service->evaluateGate([
                'wave' => '0',
                'gate_satisfied' => false,
            ]);

            $this->line((string) json_encode([
                'ok' => true,
                'decision' => $decision,
                'wave_order' => $service->waveOrder(),
                'rollback_plan' => $service->rollbackPlan(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'waves_and_gates_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
