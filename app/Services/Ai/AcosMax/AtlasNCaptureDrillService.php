<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Services\Ai\Support\AiValueNormalizer;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * TETO-01 — N-Capture Drill: measured proof of the N×M thesis.
 *
 * The Atlas core claim ("provider jumps N× ⇒ Atlas captures N× via wrapper
 * governance") becomes a MEASURED EXERCISE instead of faith. Each drill is a
 * receipt for an installed-but-not-routed engine (candidates in the registry
 * of AiProviderManager) covering the three times the plan pins:
 *   - `time_to_first_routed_task_seconds`  (engine appears in cold-start peek)
 *   - `time_to_first_proven_real_seconds`  (yardstick + regret proof)
 *   - `hours_of_integration`
 *
 * Guards (protocol/pétreo — case negativo):
 *   - `admission.bypass=true` ⇒ REFUSED (`admission_via_bypass_forbidden`)
 *   - `admission.cold_start_via != 'maxk02'` while admitted ⇒ REFUSED
 *   - `yardstick.golden_v2_passed=false` while admitted ⇒ REFUSED
 *   - `capability_spec.verified=false` while admitted ⇒ REFUSED
 *   - required fields missing ⇒ REFUSED (`drill_receipt_incomplete`)
 *
 * The service is READ-ONLY over its own JSONL receipt store. It never mutates
 * the AiProviderManager registry, never routes traffic, never promotes an
 * engine — those live in MAXK-02 (cold-start) + the routing plane. This slice
 * is the RULER.
 */
final class AtlasNCaptureDrillService
{
    public const FIELD_PATH = 'path';
    public const FIELD_PEEK_MODE = 'peek_mode';
    public const SCHEMA_VERSION = 'atlas.acos_max.n_capture_drill.v1';

    public const MEASURE_ID = 'atlas.n_capture_drill.v1';

    public const FORMULA_VERSION = 'n_capture_drill.v1';

    public const RELATIVE_LEDGER_PATH = 'app/atlas/evidence/acos-max-teto-01-n-capture-drill.jsonl';

    public const DEFAULT_DAYS_BETWEEN_DRILLS_MAX = 180;

    public const KIND_MEASURE_FREEZE = 'measure_freeze';

    public const COLD_START_CHANNEL_MAXK02 = 'maxk02';

    public const REASON_DRILL_RECEIPT_INCOMPLETE = 'drill_receipt_incomplete';

    public const REASON_ADMISSION_VIA_BYPASS_FORBIDDEN = 'admission_via_bypass_forbidden';

    public const REASON_COLD_START_CHANNEL_INVALID = 'cold_start_channel_invalid';

    public const REASON_CAPABILITY_SPEC_VIOLATION = 'capability_spec_violation';

    public const REASON_YARDSTICK_FAILED_BUT_ADMITTED = 'yardstick_failed_but_admitted';

    public const TRIGGER_UNKNOWN = 'unknown';

    public const STATUS_OK = 'ok';

    public const STATUS_INSUFFICIENT_SIGNAL = 'insufficient_signal';
    public const FIELD_REASON = 'reason';
    public const FIELD_FIELD = 'field';
    public const FIELD_STATUS = 'status';
    public const FIELD_OK = 'ok';
    public const FIELD_DRILL = 'drill';
    public const FIELD_EXPECTED = 'expected';
    public const FIELD_ACTUAL = 'actual';
    public const FIELD_MEASURE_ID = 'measure_id';
    public const FIELD_FORMULA_VERSION = 'formula_version';
    public const FIELD_DENOMINATOR_MIN = 'denominator_min';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_KIND = 'kind';
    public const FIELD_FORMULA = 'formula';
    public const FIELD_THRESHOLDS = 'thresholds';
    public const FIELD_DAYS_BETWEEN_DRILLS_MAX = 'days_between_drills_max';
    public const FIELD_COLD_START_CHANNELS_ALLOWED = 'cold_start_channels_allowed';
    public const FIELD_BYPASS_FORBIDDEN = 'bypass_forbidden';
    public const FIELD_YARDSTICK_REQUIRED_SERIES = 'yardstick_required_series';
    public const FIELD_PEEK_ONLY = 'peek_only';
    public const FIELD_ADMISSION = 'admission';
    public const FIELD_ADMITTED = 'admitted';
    public const FIELD_ADMITTED_COUNT = 'admitted_count';
    public const FIELD_AGGREGATE = 'aggregate';
    public const FIELD_AUTHOR_ENGINE_ID = 'author_engine_id';
    public const FIELD_BYPASS = 'bypass';
    public const FIELD_CAPABILITY_SPEC = 'capability_spec';
    public const FIELD_COLD_START_VIA = 'cold_start_via';
    public const FIELD_DENOMINATORS = 'denominators';
    public const FIELD_DRILL_ID = 'drill_id';
    public const FIELD_DRILLS = 'drills';
    public const FIELD_DRILLS_IN_WINDOW = 'drills_in_window';
    public const FIELD_ENGINE_ID = 'engine_id';
    public const FIELD_TRIGGER = 'trigger';
    public const FIELD_RECORDED_AT = 'recorded_at';
    public const FIELD_REQUIRED_FIELDS = 'required_fields';
    public const FIELD_TTL_DAYS = 'ttl_days';
    public const FIELD_JUDGE_ENGINE_ID = 'judge_engine_id';
    public const FIELD_DUAL_READ_REQUIRED = 'dual_read_required';
    public const FIELD_ENGINES = 'engines';
    public const FIELD_FREEZE = 'freeze';
    public const FIELD_GENERATED_AT = 'generated_at';
    public const FIELD_GOLDEN_V2_PASSED = 'golden_v2_passed';
    public const FIELD_GOLDEN_V2_SCORE = 'golden_v2_score';
    public const FIELD_HOURS_OF_INTEGRATION = 'hours_of_integration';
    public const FIELD_LATEST = 'latest';
    public const FIELD_SERIES_REGISTRY = 'series_registry';
    public const FIELD_SOURCE_TYPE = 'source_type';
    public const FIELD_FUNCTION = 'function';
    public const FIELD_VERIFIED = 'verified';
    public const FIELD_PROVEN_REAL_OUTCOMES_OBSERVED = 'proven_real_outcomes_observed';
    public const FIELD_REFUSED_COUNT = 'refused_count';

    private readonly string $ledgerPath;

    public function __construct(?string $ledgerPath = null)
    {
        $this->ledgerPath = $ledgerPath ?? storage_path(self::RELATIVE_LEDGER_PATH);
    }

    /** @return array<string,mixed> */
    public static function freezePayload(): array
    {
        return [
            self::FIELD_KIND => self::KIND_MEASURE_FREEZE,
            self::FIELD_MEASURE_ID => self::MEASURE_ID,
            self::FIELD_FORMULA => 'N-Capture Drill: for each installed-but-not-routed engine, publish {time_to_first_routed_task_seconds, time_to_first_proven_real_seconds, hours_of_integration} with denominators; admission only via MAXK-02 cold-start; capability spec ELEV-29s must verify; yardstick = golden v2 + MAXK-01 regret in peek mode.',
            self::FIELD_FORMULA_VERSION => self::FORMULA_VERSION,
            self::FIELD_THRESHOLDS => [
                self::FIELD_DAYS_BETWEEN_DRILLS_MAX => self::DEFAULT_DAYS_BETWEEN_DRILLS_MAX,
                self::FIELD_COLD_START_CHANNELS_ALLOWED => [self::COLD_START_CHANNEL_MAXK02],
                self::FIELD_BYPASS_FORBIDDEN => true,
                self::FIELD_YARDSTICK_REQUIRED_SERIES => [
                    'golden_v2',
                    'atlas.decide.route_regret.v2',
                ],
                self::FIELD_PEEK_ONLY => true,
                self::FIELD_REQUIRED_FIELDS => [
                    'engine_id',
                    'capability_spec.verified',
                    'yardstick.golden_v2_passed',
                    'times.time_to_first_routed_task_seconds',
                    'times.time_to_first_proven_real_seconds',
                    'times.hours_of_integration',
                    'admission.admitted',
                    'admission.cold_start_via',
                    'admission.bypass',
                ],
            ],
            self::FIELD_DENOMINATOR_MIN => 1,
            self::FIELD_TTL_DAYS => 365,
            self::FIELD_AUTHOR_ENGINE_ID => 'cursor-acos-max-teto01',
            self::FIELD_JUDGE_ENGINE_ID => 'codex-independent-teto01-judge',
            self::FIELD_DUAL_READ_REQUIRED => false,
            self::FIELD_SERIES_REGISTRY => [
                'series' => self::MEASURE_ID,
                self::FIELD_PATH => 'atlas:teto:n-capture-drill --json',
                self::FIELD_SOURCE_TYPE => 'jsonl',
            ],
        ];
    }

    /**
     * Record one drill receipt. Returns the sealed receipt on success.
     *
     * @param  array<string,mixed>  $drill
     * @return array<string,mixed>
     *
     * @throws RuntimeException on protocol violation (case negativo).
     */
    public function record(array $drill): array
    {
        $violations = $this->validate($drill);
        if ($violations !== []) {
            throw new RuntimeException('teto01_drill_refused:'.$violations[0][self::FIELD_REASON]);
        }

        $receipt = [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_MEASURE_ID => self::MEASURE_ID,
            self::FIELD_FORMULA_VERSION => self::FORMULA_VERSION,
            self::FIELD_DRILL_ID => AiValueNormalizer::trimmedStringOrNull($drill[self::FIELD_DRILL_ID] ?? null) ?? (string) Str::uuid(),
            self::FIELD_ENGINE_ID => AiValueNormalizer::trimmedScalarStringOrNull($drill[self::FIELD_ENGINE_ID] ?? null) ?? '',
            self::FIELD_CAPABILITY_SPEC => [
                self::FIELD_FUNCTION => AiValueNormalizer::trimmedStringOrNull(data_get($drill, 'capability_spec.function')) ?? 'engine',
                self::FIELD_VERIFIED => (AiValueNormalizer::boolOrNull(data_get($drill, 'capability_spec.verified')) ?? false),
                'violations' => array_values(AiValueNormalizer::arrayOrEmpty(data_get($drill, 'capability_spec.violations', []))),
            ],
            'yardstick' => [
                self::FIELD_GOLDEN_V2_PASSED => (AiValueNormalizer::boolOrNull(data_get($drill, 'yardstick.golden_v2_passed')) ?? false),
                self::FIELD_GOLDEN_V2_SCORE => data_get($drill, 'yardstick.golden_v2_score'),
                'regret_measure_id' => AiValueNormalizer::trimmedStringOrNull(data_get($drill, 'yardstick.regret_measure_id')) ?? 'atlas.decide.route_regret.v2',
                self::FIELD_PEEK_MODE => true,
            ],
            'times' => [
                'time_to_first_routed_task_seconds' => (int) data_get($drill, 'times.time_to_first_routed_task_seconds'),
                'time_to_first_proven_real_seconds' => (int) data_get($drill, 'times.time_to_first_proven_real_seconds'),
                self::FIELD_HOURS_OF_INTEGRATION => AiValueNormalizer::finiteFloatOrNull(data_get($drill, 'times.hours_of_integration')) ?? 0.0,
            ],
            self::FIELD_DENOMINATORS => [
                'routed_tasks_observed' => (int) data_get($drill, 'denominators.routed_tasks_observed', 0),
                self::FIELD_PROVEN_REAL_OUTCOMES_OBSERVED => (int) data_get($drill, 'denominators.proven_real_outcomes_observed', 0),
            ],
            self::FIELD_ADMISSION => [
                self::FIELD_ADMITTED => (AiValueNormalizer::boolOrNull(data_get($drill, 'admission.admitted')) ?? false),
                self::FIELD_COLD_START_VIA => data_get($drill, 'admission.cold_start_via'),
                self::FIELD_BYPASS => (AiValueNormalizer::boolOrNull(data_get($drill, 'admission.bypass')) ?? false),
                self::FIELD_REASON => data_get($drill, 'admission.reason'),
            ],
            self::FIELD_TRIGGER => (AiValueNormalizer::trimmedStringOrNull($drill[self::FIELD_TRIGGER] ?? null) ?? self::TRIGGER_UNKNOWN),
            self::FIELD_RECORDED_AT => now('UTC')->toIso8601String(),
        ];

        $this->appendReceipt($receipt);

        return $receipt;
    }

    /**
     * Read the drill ledger and report window-scoped aggregates.
     *
     * @return array<string,mixed>
     */
    public function report(?int $days = null): array
    {
        $freeze = self::freezePayload();
        $windowDays = ($days !== null && $days > 0) ? $days : (int) (AiValueNormalizer::finiteFloatOrNull(data_get($freeze, 'thresholds.days_between_drills_max')) ?? self::DEFAULT_DAYS_BETWEEN_DRILLS_MAX);

        $receipts = $this->readReceipts();
        $since = now('UTC')->subDays($windowDays);
        $inWindow = array_values(array_filter(
            $receipts,
            static function (array $r) use ($since): bool {
                $ts = (AiValueNormalizer::trimmedStringOrNull($r[self::FIELD_RECORDED_AT] ?? null) ?? '');
                if ($ts === '') {
                    return false;
                }

                try {
                    return \Carbon\CarbonImmutable::parse($ts)->greaterThanOrEqualTo($since);
                } catch (\Throwable) {
                    return false;
                }
            }
        ));

        $admitted = array_values(array_filter(
            $inWindow,
            static fn (array $r): bool => (AiValueNormalizer::boolOrNull(data_get($r, 'admission.admitted')) ?? false) === true
        ));

        $refused = array_values(array_filter(
            $inWindow,
            static fn (array $r): bool => (AiValueNormalizer::boolOrNull(data_get($r, 'admission.admitted')) ?? false) === false
        ));

        $status = $inWindow === [] ? self::STATUS_INSUFFICIENT_SIGNAL : self::STATUS_OK;
        $reason = $inWindow === [] ? 'no_drill_in_window' : null;

        $engines = [];
        foreach ($inWindow as $r) {
            $engineId = (AiValueNormalizer::trimmedStringOrNull($r[self::FIELD_ENGINE_ID] ?? null) ?? '');
            if ($engineId === '') {
                continue;
            }
            $engines[$engineId] = ($engines[$engineId] ?? 0) + 1;
        }

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_MEASURE_ID => self::MEASURE_ID,
            self::FIELD_FORMULA_VERSION => self::FORMULA_VERSION,
            self::FIELD_GENERATED_AT => now('UTC')->toIso8601String(),
            self::FIELD_FREEZE => $freeze,
            'window_days' => $windowDays,
            self::FIELD_STATUS => $status,
            self::FIELD_REASON => $reason,
            self::FIELD_DENOMINATOR_MIN => (int) (AiValueNormalizer::finiteFloatOrNull($freeze[self::FIELD_DENOMINATOR_MIN] ?? null) ?? 0),
            self::FIELD_AGGREGATE => [
                self::FIELD_DRILLS_IN_WINDOW => count($inWindow),
                self::FIELD_ADMITTED_COUNT => count($admitted),
                self::FIELD_REFUSED_COUNT => count($refused),
                self::FIELD_ENGINES => $engines,
            ],
            self::FIELD_LATEST => $inWindow === [] ? null : end($inWindow),
            self::FIELD_DRILLS => $inWindow,
        ];
    }

    public function ledgerPath(): string
    {
        return $this->ledgerPath;
    }

    /**
     * Validate a drill payload; returns list of violations (empty ⇒ accepted).
     *
     * @param  array<string,mixed>  $drill
     * @return list<array{field:string,reason:string}>
     */
    public function validate(array $drill): array
    {
        $violations = [];

        $engineId = AiValueNormalizer::trimmedStringOrNull($drill[self::FIELD_ENGINE_ID] ?? null) ?? '';
        if ($engineId === '') {
            $violations[] = [self::FIELD_FIELD => 'engine_id', self::FIELD_REASON => self::REASON_DRILL_RECEIPT_INCOMPLETE];
        }

        foreach ([
            'capability_spec.verified',
            'yardstick.golden_v2_passed',
            'times.time_to_first_routed_task_seconds',
            'times.time_to_first_proven_real_seconds',
            'times.hours_of_integration',
            'admission.admitted',
            'admission.cold_start_via',
            'admission.bypass',
        ] as $requiredField) {
            if (data_get($drill, $requiredField, '__missing__') === '__missing__') {
                $violations[] = [self::FIELD_FIELD => $requiredField, self::FIELD_REASON => self::REASON_DRILL_RECEIPT_INCOMPLETE];
            }
        }

        if ($violations !== []) {
            return $violations;
        }

        $admitted = (AiValueNormalizer::boolOrNull(data_get($drill, 'admission.admitted')) ?? false);
        $bypass = (AiValueNormalizer::boolOrNull(data_get($drill, 'admission.bypass')) ?? false);
        $coldStartVia = (string) (data_get($drill, 'admission.cold_start_via') ?? '');
        $capabilityVerified = (AiValueNormalizer::boolOrNull(data_get($drill, 'capability_spec.verified')) ?? false);
        $yardstickPassed = (AiValueNormalizer::boolOrNull(data_get($drill, 'yardstick.golden_v2_passed')) ?? false);

        if ($admitted && $bypass) {
            $violations[] = [self::FIELD_FIELD => 'admission.bypass', self::FIELD_REASON => self::REASON_ADMISSION_VIA_BYPASS_FORBIDDEN];
        }
        if ($admitted && $coldStartVia !== self::COLD_START_CHANNEL_MAXK02) {
            $violations[] = [self::FIELD_FIELD => 'admission.cold_start_via', self::FIELD_REASON => self::REASON_COLD_START_CHANNEL_INVALID];
        }
        if ($admitted && ! $capabilityVerified) {
            $violations[] = [self::FIELD_FIELD => 'capability_spec.verified', self::FIELD_REASON => self::REASON_CAPABILITY_SPEC_VIOLATION];
        }
        if ($admitted && ! $yardstickPassed) {
            $violations[] = [self::FIELD_FIELD => 'yardstick.golden_v2_passed', self::FIELD_REASON => self::REASON_YARDSTICK_FAILED_BUT_ADMITTED];
        }

        return $violations;
    }

    /** @param  array<string,mixed>  $receipt */
    private function appendReceipt(array $receipt): void
    {
        $dir = dirname($this->ledgerPath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $encoded = json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if ($encoded === false) {
            throw new RuntimeException('teto01_receipt_encode_failed');
        }
        file_put_contents($this->ledgerPath, $encoded.PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /** @return list<array<string,mixed>> */
    private function readReceipts(): array
    {
        if (! is_file($this->ledgerPath)) {
            return [];
        }
        $handle = @fopen($this->ledgerPath, 'r');
        if ($handle === false) {
            return [];
        }
        $rows = [];
        try {
            while (($line = fgets($handle)) !== false) {
                $line = AiValueNormalizer::trimmedStringOrNull($line) ?? '';
                if ($line === '') {
                    continue;
                }
                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
                    $rows[] = $decoded;
                }
            }
        } finally {
            fclose($handle);
        }

        return $rows;
    }
}
