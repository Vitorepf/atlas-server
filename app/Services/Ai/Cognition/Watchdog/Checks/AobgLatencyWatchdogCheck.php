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

    public const REASON_INSUFFICIENT_SIGNAL = 'insufficient_signal';

    public const REASON_LATENCY_FLOOR_EXCEEDED = 'latency_floor_exceeded';

    public const REASON_SUFFICIENT_SIGNAL_WITHIN_FLOORS = 'sufficient_signal_within_floors';
    public const FIELD_REASON = 'reason';
    public const FIELD_SAMPLES = 'samples';
    public const FIELD_REQUIRED = 'required';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_MEASURE_ID = 'measure_id';
    public const FIELD_DAY = 'day';
    public const FIELD_THRESHOLDS = 'thresholds';
    public const FIELD_DENOMINATOR_MIN = 'denominator_min';
    public const FIELD_DENOMINATOR_MIN_SAMPLES = 'denominator_min_samples';
    public const FIELD_FREEZE_SOURCE = 'freeze_source';


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
        $thresholds = AiValueNormalizer::arrayOrEmpty($freeze[self::FIELD_THRESHOLDS] ?? null);
        $denominatorMin = max(1, (int) (AiValueNormalizer::finiteFloatOrNull($freeze[self::FIELD_DENOMINATOR_MIN] ?? null) ?? AiValueNormalizer::finiteFloatOrNull($thresholds[self::FIELD_DENOMINATOR_MIN_SAMPLES] ?? null) ?? self::DEFAULT_DENOMINATOR_MIN));
        $day = gmdate('Y-m-d');
        $report = $this->ledger->report(day: $day);
        $ops = AiValueNormalizer::arrayOrEmpty(data_get($report, 'days.'.$day.'.ops', []));

        $insufficient = [];
        foreach (AtlasAobgLatencyLedger::OPS as $op) {
            $samples = (int) (AiValueNormalizer::finiteFloatOrNull(data_get($ops, $op.'.samples', 0)) ?? 0);
            if ($samples < $denominatorMin || data_get($ops, $op.'.p95_ms') === null) {
                $insufficient[] = [
                    'op' => $op,
                    self::FIELD_SAMPLES => $samples,
                    self::FIELD_REQUIRED => $denominatorMin,
                ];
            }
        }

        $evidence = [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_MEASURE_ID => AiValueNormalizer::trimmedStringOrNull($freeze[self::FIELD_MEASURE_ID] ?? null) ?? self::DEFAULT_MEASURE_ID,
            self::FIELD_DAY => $day,
            self::FIELD_THRESHOLDS => $thresholds,
            self::FIELD_DENOMINATOR_MIN => $denominatorMin,
            'report' => $report,
            self::FIELD_FREEZE_SOURCE => $this->freezePayloadOverride !== null ? 'override' : ($this->latestFreezePayload() !== null ? 'evidence_ledger' : 'default_payload'),
        ];

        if ($insufficient !== []) {
            return AtlasWatchdogCheckResult::skipped($evidence + [
                self::FIELD_REASON => self::REASON_INSUFFICIENT_SIGNAL,
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
            return AtlasWatchdogCheckResult::alert($evidence + [self::FIELD_REASON => self::REASON_LATENCY_FLOOR_EXCEEDED], [
                'code' => 'aobg_latency_p95_exceeded',
                'message' => 'AOBG latency p95 exceeded frozen floors.',
                'violations' => $alerts,
            ]);
        }

        return AtlasWatchdogCheckResult::ok($evidence + [self::FIELD_REASON => self::REASON_SUFFICIENT_SIGNAL_WITHIN_FLOORS]);
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
