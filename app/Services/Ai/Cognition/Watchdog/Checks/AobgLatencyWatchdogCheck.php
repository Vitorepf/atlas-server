<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition\Watchdog\Checks;

use App\Console\Commands\AtlasAcosFreezeCommand;
use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheck;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheckResult;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\OpenBrain\AtlasAobgLatencyLedger;
use Throwable;

final readonly class AobgLatencyWatchdogCheck implements AtlasWatchdogCheck
{
    /**
     * @param  array<string,mixed>|null  $freezePayloadOverride
     */
    public function __construct(
        private AtlasAobgLatencyLedger $ledger,
        private ?AtlasEvidenceLedger $evidenceLedger = null,
        private ?array $freezePayloadOverride = null,
    ) {}

    public function id(): string
    {
        return 'wdg-01.aobg_latency';
    }

    public function run(): AtlasWatchdogCheckResult
    {
        $freeze = $this->freezePayloadOverride ?? $this->latestFreezePayload() ?? AtlasAcosFreezeCommand::defaultFreezePayload();
        $thresholds = (array) ($freeze['thresholds'] ?? []);
        $denominatorMin = max(1, (int) ($freeze['denominator_min'] ?? $thresholds['denominator_min_samples'] ?? 5));
        $day = gmdate('Y-m-d');
        $report = $this->ledger->report(day: $day);
        $ops = (array) data_get($report, 'days.'.$day.'.ops', []);

        $insufficient = [];
        foreach (AtlasAobgLatencyLedger::OPS as $op) {
            $samples = (int) data_get($ops, $op.'.samples', 0);
            if ($samples < $denominatorMin || data_get($ops, $op.'.p95_ms') === null) {
                $insufficient[] = [
                    'op' => $op,
                    'samples' => $samples,
                    'required' => $denominatorMin,
                ];
            }
        }

        $evidence = [
            'schema_version' => 'atlas.acos.watchdog.aobg_latency.v1',
            'measure_id' => (string) ($freeze['measure_id'] ?? 'aobg.latency_ledger.v1'),
            'day' => $day,
            'thresholds' => $thresholds,
            'denominator_min' => $denominatorMin,
            'report' => $report,
            'freeze_source' => $this->freezePayloadOverride !== null ? 'override' : ($this->latestFreezePayload() !== null ? 'evidence_ledger' : 'default_payload'),
        ];

        if ($insufficient !== []) {
            return AtlasWatchdogCheckResult::skipped($evidence + [
                'reason' => 'insufficient_signal',
                'insufficient_ops' => $insufficient,
            ]);
        }

        $alerts = [];
        foreach ([
            AtlasAobgLatencyLedger::OP_PACK => (float) ($thresholds['pack_p95_ms_alert'] ?? 18000),
            AtlasAobgLatencyLedger::OP_RECALL => (float) ($thresholds['recall_p95_ms_alert'] ?? 15000),
            AtlasAobgLatencyLedger::OP_HOOK => (float) ($thresholds['hook_p95_ms_alert'] ?? 20000),
        ] as $op => $floor) {
            $p95 = (float) data_get($ops, $op.'.p95_ms', 0.0);
            if ($p95 > $floor) {
                $alerts[] = ['op' => $op, 'p95_ms' => $p95, 'floor_ms' => $floor];
            }
        }

        if ($alerts !== []) {
            return AtlasWatchdogCheckResult::alert($evidence + ['reason' => 'latency_floor_exceeded'], [
                'code' => 'aobg_latency_p95_exceeded',
                'message' => 'AOBG latency p95 exceeded frozen floors.',
                'violations' => $alerts,
            ]);
        }

        return AtlasWatchdogCheckResult::ok($evidence + ['reason' => 'sufficient_signal_within_floors']);
    }

    /** @return array<string,mixed>|null */
    private function latestFreezePayload(): ?array
    {
        try {
            $ledger = $this->evidenceLedger ?? (function_exists('app') ? app(AtlasEvidenceLedger::class) : null);
            if (! $ledger instanceof AtlasEvidenceLedger) {
                return null;
            }

            $event = $ledger->latestForScope('measure', 'aobg.latency_ledger.v1', 'measure.freeze.recorded');

            return $event instanceof AtlasLedgerEvent && is_array($event->payload) ? $event->payload : null;
        } catch (Throwable) {
            return null;
        }
    }
}
