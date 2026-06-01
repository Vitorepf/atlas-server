<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasEvidenceLoopService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Evidence Loop signal-governance CLI.
 *
 *   php artisan atlas:aaeos:evidence-loop
 *     [--kind=cost|latency|gate_failure|repair_attempt|outcome]
 *     [--evidence-ref=<ledger-id>] [--evidence-complete]
 *     [--gate=pass|fail] [--has-outcome] [--claims-success]
 *     [--metric=<float>] [--metric-min=<float>] [--metric-max=<float>]
 *     [--contaminated]
 *     [--json]
 *
 * Read-only, deterministic. Emits the per-signal govern/quarantine/reject
 * decision (the doc invariant: a signal without evidence does not govern a
 * decision) plus a receipt.
 *
 * @see docs/engineering-knowledge-base/system-graph/evidence-loop.md
 */
class AtlasEvidenceLoopCommand extends Command
{
    protected $signature = 'atlas:aaeos:evidence-loop
        {--kind= : signal kind (cost|latency|gate_failure|repair_attempt|outcome)}
        {--evidence-ref= : id/path of the evidence-ledger record backing the signal}
        {--evidence-complete : the evidence record is whole (not partial)}
        {--gate= : gate verdict (pass|fail)}
        {--has-outcome : a real outcome was recorded}
        {--claims-success : the signal asserts a success result}
        {--metric= : raw metric value (cost/latency/...)}
        {--metric-min= : lower sane bound for the metric}
        {--metric-max= : upper sane bound for the metric}
        {--contaminated : caller already flagged the metric as tainted}
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas evidence · govern one Evidence Loop signal (govern|quarantine|reject).';

    public function handle(AtlasEvidenceLoopService $service): int
    {
        try {
            $signal = [
                'kind' => $this->option('kind') ?? 'outcome',
                'evidence_ref' => (string) ($this->option('evidence-ref') ?? ''),
                'evidence_complete' => (bool) $this->option('evidence-complete'),
                'gate_result' => (string) ($this->option('gate') ?? ''),
                'has_outcome' => (bool) $this->option('has-outcome'),
                'claims_success' => (bool) $this->option('claims-success'),
                'contaminated' => (bool) $this->option('contaminated'),
            ];

            if ($this->option('metric') !== null) {
                $signal['metric_value'] = (float) $this->option('metric');
            }
            if ($this->option('metric-min') !== null) {
                $signal['metric_min'] = (float) $this->option('metric-min');
            }
            if ($this->option('metric-max') !== null) {
                $signal['metric_max'] = (float) $this->option('metric-max');
            }

            $decision = $service->governSignal($signal);

            $this->line((string) json_encode(
                ['ok' => true, 'decision' => $decision],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'evidence_loop_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
