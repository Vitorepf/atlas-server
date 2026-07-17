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
    public const SCHEMA_VERSION = 'atlas.acos_max.n_capture_drill.v1';

    public const MEASURE_ID = 'atlas.n_capture_drill.v1';

    public const FORMULA_VERSION = 'n_capture_drill.v1';

    public const RELATIVE_LEDGER_PATH = 'app/atlas/evidence/acos-max-teto-01-n-capture-drill.jsonl';

    private readonly string $ledgerPath;

    public function __construct(?string $ledgerPath = null)
    {
        $this->ledgerPath = $ledgerPath ?? storage_path(self::RELATIVE_LEDGER_PATH);
    }

    /** @return array<string,mixed> */
    public static function freezePayload(): array
    {
        return [
            'kind' => 'measure_freeze',
            'measure_id' => self::MEASURE_ID,
            'formula' => 'N-Capture Drill: for each installed-but-not-routed engine, publish {time_to_first_routed_task_seconds, time_to_first_proven_real_seconds, hours_of_integration} with denominators; admission only via MAXK-02 cold-start; capability spec ELEV-29s must verify; yardstick = golden v2 + MAXK-01 regret in peek mode.',
            'formula_version' => self::FORMULA_VERSION,
            'thresholds' => [
                'days_between_drills_max' => 180,
                'cold_start_channels_allowed' => ['maxk02'],
                'bypass_forbidden' => true,
                'yardstick_required_series' => [
                    'golden_v2',
                    'atlas.decide.route_regret.v2',
                ],
                'peek_only' => true,
                'required_fields' => [
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
            'denominator_min' => 1,
            'ttl_days' => 365,
            'author_engine_id' => 'cursor-acos-max-teto01',
            'judge_engine_id' => 'codex-independent-teto01-judge',
            'dual_read_required' => false,
            'series_registry' => [
                'series' => self::MEASURE_ID,
                'path' => 'atlas:teto:n-capture-drill --json',
                'source_type' => 'jsonl',
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
            throw new RuntimeException('teto01_drill_refused:'.$violations[0]['reason']);
        }

        $receipt = [
            'schema_version' => self::SCHEMA_VERSION,
            'measure_id' => self::MEASURE_ID,
            'formula_version' => self::FORMULA_VERSION,
            'drill_id' => AiValueNormalizer::trimmedStringOrNull($drill['drill_id'] ?? null) ?? (string) Str::uuid(),
            'engine_id' => AiValueNormalizer::trimmedScalarStringOrNull($drill['engine_id'] ?? null) ?? '',
            'capability_spec' => [
                'function' => AiValueNormalizer::trimmedStringOrNull(data_get($drill, 'capability_spec.function')) ?? 'engine',
                'verified' => (bool) data_get($drill, 'capability_spec.verified'),
                'violations' => array_values(AiValueNormalizer::arrayOrEmpty(data_get($drill, 'capability_spec.violations', []))),
            ],
            'yardstick' => [
                'golden_v2_passed' => (bool) data_get($drill, 'yardstick.golden_v2_passed'),
                'golden_v2_score' => data_get($drill, 'yardstick.golden_v2_score'),
                'regret_measure_id' => AiValueNormalizer::trimmedStringOrNull(data_get($drill, 'yardstick.regret_measure_id')) ?? 'atlas.decide.route_regret.v2',
                'peek_mode' => true,
            ],
            'times' => [
                'time_to_first_routed_task_seconds' => (int) data_get($drill, 'times.time_to_first_routed_task_seconds'),
                'time_to_first_proven_real_seconds' => (int) data_get($drill, 'times.time_to_first_proven_real_seconds'),
                'hours_of_integration' => AiValueNormalizer::finiteFloatOrNull(data_get($drill, 'times.hours_of_integration')) ?? 0.0,
            ],
            'denominators' => [
                'routed_tasks_observed' => (int) data_get($drill, 'denominators.routed_tasks_observed', 0),
                'proven_real_outcomes_observed' => (int) data_get($drill, 'denominators.proven_real_outcomes_observed', 0),
            ],
            'admission' => [
                'admitted' => (bool) data_get($drill, 'admission.admitted'),
                'cold_start_via' => data_get($drill, 'admission.cold_start_via'),
                'bypass' => (bool) data_get($drill, 'admission.bypass'),
                'reason' => data_get($drill, 'admission.reason'),
            ],
            'trigger' => (AiValueNormalizer::trimmedStringOrNull($drill['trigger'] ?? null) ?? 'unknown'),
            'recorded_at' => now('UTC')->toIso8601String(),
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
        $windowDays = ($days !== null && $days > 0) ? $days : (int) $freeze['thresholds']['days_between_drills_max'];

        $receipts = $this->readReceipts();
        $since = now('UTC')->subDays($windowDays);
        $inWindow = array_values(array_filter(
            $receipts,
            static function (array $r) use ($since): bool {
                $ts = (AiValueNormalizer::trimmedStringOrNull($r['recorded_at'] ?? null) ?? '');
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
            static fn (array $r): bool => (bool) data_get($r, 'admission.admitted') === true
        ));

        $refused = array_values(array_filter(
            $inWindow,
            static fn (array $r): bool => (bool) data_get($r, 'admission.admitted') === false
        ));

        $status = $inWindow === [] ? 'insufficient_signal' : 'ok';
        $reason = $inWindow === [] ? 'no_drill_in_window' : null;

        $engines = [];
        foreach ($inWindow as $r) {
            $engineId = (AiValueNormalizer::trimmedStringOrNull($r['engine_id'] ?? null) ?? '');
            if ($engineId === '') {
                continue;
            }
            $engines[$engineId] = ($engines[$engineId] ?? 0) + 1;
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'measure_id' => self::MEASURE_ID,
            'formula_version' => self::FORMULA_VERSION,
            'generated_at' => now('UTC')->toIso8601String(),
            'freeze' => $freeze,
            'window_days' => $windowDays,
            'status' => $status,
            'reason' => $reason,
            'denominator_min' => (int) (AiValueNormalizer::finiteFloatOrNull($freeze['denominator_min'] ?? null) ?? 0),
            'aggregate' => [
                'drills_in_window' => count($inWindow),
                'admitted_count' => count($admitted),
                'refused_count' => count($refused),
                'engines' => $engines,
            ],
            'latest' => $inWindow === [] ? null : end($inWindow),
            'drills' => $inWindow,
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

        $engineId = AiValueNormalizer::trimmedStringOrNull($drill['engine_id'] ?? null) ?? '';
        if ($engineId === '') {
            $violations[] = ['field' => 'engine_id', 'reason' => 'drill_receipt_incomplete'];
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
                $violations[] = ['field' => $requiredField, 'reason' => 'drill_receipt_incomplete'];
            }
        }

        if ($violations !== []) {
            return $violations;
        }

        $admitted = (bool) data_get($drill, 'admission.admitted');
        $bypass = (bool) data_get($drill, 'admission.bypass');
        $coldStartVia = (string) (data_get($drill, 'admission.cold_start_via') ?? '');
        $capabilityVerified = (bool) data_get($drill, 'capability_spec.verified');
        $yardstickPassed = (bool) data_get($drill, 'yardstick.golden_v2_passed');

        if ($admitted && $bypass) {
            $violations[] = ['field' => 'admission.bypass', 'reason' => 'admission_via_bypass_forbidden'];
        }
        if ($admitted && $coldStartVia !== 'maxk02') {
            $violations[] = ['field' => 'admission.cold_start_via', 'reason' => 'cold_start_channel_invalid'];
        }
        if ($admitted && ! $capabilityVerified) {
            $violations[] = ['field' => 'capability_spec.verified', 'reason' => 'capability_spec_violation'];
        }
        if ($admitted && ! $yardstickPassed) {
            $violations[] = ['field' => 'yardstick.golden_v2_passed', 'reason' => 'yardstick_failed_but_admitted'];
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
