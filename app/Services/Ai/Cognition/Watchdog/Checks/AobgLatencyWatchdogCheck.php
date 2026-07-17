<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition\Watchdog\Checks;

use App\Console\Commands\AtlasAcosFreezeCommand;
use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheck;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheckResult;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\OpenBrain\AtlasAobgLatencyLedger;
use App\Services\Ai\Support\AiValueNormalizer;
use Throwable;

final readonly class AobgLatencyWatchdogCheck implements AtlasWatchdogCheck
{
    public const SCHEMA_VERSION = 'atlas.acos.watchdog.aobg_latency.v1';

    public const CHECK_ID = 'wdg-01.aobg_latency';

    public const DEFAULT_MEASURE_ID = 'aobg.latency_ledger.v1';

    public const DEFAULT_DENOMINATOR_MIN = 5;

    public const DEFAULT_PACK_P95_MS_ALERT = 18000.0;

    public const DEFAULT_RECALL_P95_MS_ALERT = 15000.0;

    public const DEFAULT_HOOK_P95_MS_ALERT = 20000.0;

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
        return self::CHECK_ID;
    }

    public function run(): AtlasWatchdogCheckResult
    {
        $freeze = $this->freezePayloadOverride ?? $this->latestFreezePayload() ?? AtlasAcosFreezeCommand::defaultFreezePayload();
        $thresholds = AiValueNormalizer::arrayOrEmpty($freeze['thresholds'] ?? null);
        $denominatorMin = max(1, (int) (AiValueNormalizer::finiteFloatOrNull($freeze['denominator_min'] ?? null) ?? AiValueNormalizer::finiteFloatOrNull($thresholds['denominator_min_samples'] ?? null) ?? self::DEFAULT_DENOMINATOR_MIN));
        $day = gmdate('Y-m-d');
        $report = $this->ledger->report(day: $day);
        $ops = AiValueNormalizer::arrayOrEmpty(data_get($report, 'days.'.$day.'.ops', []));

        $insufficient = [];
        foreach (AtlasAobgLatencyLedger::OPS as $op) {
            $samples = (int) (AiValueNormalizer::finiteFloatOrNull(data_get($ops, $op.'.samples', 0)) ?? 0);
            if ($samples < $denominatorMin || data_get($ops, $op.'.p95_ms') === null) {
                $insufficient[] = [
                    'op' => $op,
                    'samples' => $samples,
                    'required' => $denominatorMin,
                ];
            }
        }

        $evidence = [
            'schema_version' => self::SCHEMA_VERSION,
            'measure_id' => AiValueNormalizer::trimmedStringOrNull($freeze['measure_id'] ?? null) ?? self::DEFAULT_MEASURE_ID,
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
            AtlasAobgLatencyLedger::OP_PACK => AiValueNormalizer::finiteFloatOrNull($thresholds['pack_p95_ms_alert'] ?? null) ?? self::DEFAULT_PACK_P95_MS_ALERT,
            AtlasAobgLatencyLedger::OP_RECALL => AiValueNormalizer::finiteFloatOrNull($thresholds['recall_p95_ms_alert'] ?? null) ?? self::DEFAULT_RECALL_P95_MS_ALERT,
            AtlasAobgLatencyLedger::OP_HOOK => AiValueNormalizer::finiteFloatOrNull($thresholds['hook_p95_ms_alert'] ?? null) ?? self::DEFAULT_HOOK_P95_MS_ALERT,
        ] as $op => $floor) {
            $p95 = AiValueNormalizer::finiteFloatOrNull(data_get($ops, $op.'.p95_ms', 0.0)) ?? 0.0;
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
