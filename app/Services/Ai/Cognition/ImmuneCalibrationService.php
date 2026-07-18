<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition;

use App\Services\Ai\Cognitive\PredictiveFailure\CalibrationBandClassifier;
use App\Services\Ai\Support\AiValueNormalizer;

final class ImmuneCalibrationService
{
    public const FIELD_CLAIM_TYPE = 'claim_type';
    public const FIELD_CLASSIFIER_BAND = 'classifier_band';
    public const SCHEMA_VERSION = 'atlas.cognition.immune_calibration.v1';

    public const MEASURE_ID = 'atlas.immune.calibration.v1';

    public const FORMULA_VERSION = 'immune_calibration_fp_fn_bands.v1';

    public const DENOMINATOR_MIN = 10;

    public const TTL_DAYS = 90;

    /** @var list<string> */
    public const GATE_IDS = ['G0', 'G1', 'G2', 'G3', 'G4', 'G5', 'G6', 'G7', 'G8'];

    public const MODE_READ_ONLY = 'read_only';

    public const KIND_MEASURE_FREEZE = 'measure_freeze';

    public const BAND_INSUFFICIENT_SAMPLE = 'insufficient_sample';

    public const STATUS_INSUFFICIENT_SAMPLE = 'insufficient_sample';

    public const STATUS_CALIBRATED = 'calibrated';

    public const STATUS_OK = 'ok';

    public const REASON_KNOWN_MISS_DENOMINATOR_ZERO = 'known_miss_denominator_zero';

    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_STATUS = 'status';
    public const FIELD_MODE = 'mode';
    public const FIELD_MEASURE_ID = 'measure_id';
    public const FIELD_FORMULA_VERSION = 'formula_version';
    public const FIELD_BAND = 'band';
    public const FIELD_REASON = 'reason';
    public const FIELD_METRIC = 'metric';
    public const FIELD_DENOMINATOR = 'denominator';
    public const FIELD_VALUE = 'value';
    public const FIELD_MISSED_POISON_RATE = 'missed_poison_rate';
    public const FIELD_FALSE_BLOCK_RATE = 'false_block_rate';
    public const FIELD_CALIBRATION_STATUS = 'calibration_status';
    public const FIELD_BLOCKS = 'blocks';
    public const FIELD_GROUPS = 'groups';
    public const FIELD_DENOMINATOR_MIN = 'denominator_min';
    public const FIELD_REGISTRY_STATUS = 'registry_status';
    public const FIELD_GENERATED_AT = 'generated_at';
    public const FIELD_FREEZE = 'freeze';
    public const FIELD_SAMPLES = 'samples';
    public const FIELD_LEDGER = 'ledger';
    public const FIELD_KNOWN_MISS_SEED = 'known_miss_seed';
    public const FIELD_TOTAL = 'total';
    public const FIELD_CAVEATS = 'caveats';
    public const FIELD_FALSE_BLOCKS = 'false_blocks';
    public const FIELD_MISSED_POISON = 'missed_poison';
    public const FIELD_KNOWN_MISS_DENOMINATOR = 'known_miss_denominator';
    public const FIELD_EXPECTED_BLOCK_GATE_IDS = 'expected_block_gate_ids';
    public const FIELD_TRUE_BLOCKS = 'true_blocks';
    public const FIELD_WRITER = 'writer';
    public const FIELD_ATOMIC_CLAIM_PRESENT = 'atomic_claim_present';
    public const FIELD_AUTHOR_ENGINE_ID = 'author_engine_id';
    public const FIELD_BLOCKING_GATE_IDS = 'blocking_gate_ids';
    public const FIELD_BLOCKS_DENOMINATOR = 'blocks_denominator';
    public const FIELD_BOUND = 'bound';
    public const FIELD_CLAIM_SOURCE_PRESENT = 'claim_source_present';
    public const FIELD_DENOMINATOR_MIN_SAMPLES = 'denominator_min_samples';
    public const FIELD_KNOWN_MISS_DENOMINATOR_MUST_BE_NON_ZERO = 'known_miss_denominator_must_be_non_zero';
    public const FIELD_CONTROL = 'control';
    public const FIELD_FORMULA = 'formula';
    public const FIELD_CLASSIFIER_SCHEMA_VERSION = 'classifier_schema_version';
    public const FIELD_CONSENT_GRANTED = 'consent_granted';
    public const FIELD_CONTAINS_SECRET = 'contains_secret';
    public const FIELD_CONTAINS_SENSITIVE_UNNECESSARY = 'contains_sensitive_unnecessary';
    public const FIELD_CONTENT_HASH = 'content_hash';
    public const FIELD_CONTRADICTS_NEWER = 'contradicts_newer';
    public const FIELD_DUAL_READ_REQUIRED = 'dual_read_required';
    public const FIELD_FUTURE_UTILITY = 'future_utility';
    public const FIELD_GATE = 'gate';
    public const FIELD_GATE_STATUSES = 'gate_statuses';
    public const FIELD_ID = 'id';

    private readonly ImmuneVerdictLedger $ledger;

    private readonly CognitiveImmunePromotionGateEvaluator $evaluator;

    private readonly CalibrationBandClassifier $bandClassifier;

    public function __construct(
        ?ImmuneVerdictLedger $ledger = null,
        ?CognitiveImmunePromotionGateEvaluator $evaluator = null,
        ?CalibrationBandClassifier $bandClassifier = null,
    ) {
        $this->ledger = $ledger ?? new ImmuneVerdictLedger;
        $this->evaluator = $evaluator ?? new CognitiveImmunePromotionGateEvaluator;
        $this->bandClassifier = $bandClassifier ?? new CalibrationBandClassifier;
    }

    /** @return array<string,mixed> */
    public function report(int $days = self::TTL_DAYS): array
    {
        $ledgerRows = $this->ledger->rows($days);
        $seedRows = [$this->knownMissSeedSample()];
        $samples = [...$ledgerRows, ...$seedRows];
        $groups = $this->groups($samples);
        $okGroups = count(array_filter(
            $groups,
            static fn (array $group): bool => ($group[self::FIELD_CALIBRATION_STATUS] ?? null) === self::STATUS_CALIBRATED,
        ));

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_GENERATED_AT => now()->toIso8601String(),
            self::FIELD_MODE => self::MODE_READ_ONLY,
            self::FIELD_MEASURE_ID => self::MEASURE_ID,
            self::FIELD_FORMULA_VERSION => self::FORMULA_VERSION,
            self::FIELD_STATUS => $okGroups > 0 ? self::STATUS_OK : self::STATUS_INSUFFICIENT_SAMPLE,
            self::FIELD_DENOMINATOR_MIN => self::DENOMINATOR_MIN,
            self::FIELD_FREEZE => self::freezePayload(),
            self::FIELD_SAMPLES => [
                self::FIELD_LEDGER => count($ledgerRows),
                self::FIELD_KNOWN_MISS_SEED => count($seedRows),
                self::FIELD_TOTAL => count($samples),
            ],
            self::FIELD_CAVEATS => [
                self::FIELD_MISSED_POISON_RATE => 'lower_bound_known_miss: denominator is seeded known-should-catch plus labelled real known-miss samples only; never treated as calibrated when denominator is zero.',
                self::FIELD_CONTROL => 'informational_only_never_auto_adjusts_gate',
            ],
            self::FIELD_GROUPS => $groups,
        ];
    }

    /** @return array<string,mixed> */
    public static function freezePayload(): array
    {
        $payload = [
            'kind' => self::KIND_MEASURE_FREEZE,
            self::FIELD_MEASURE_ID => self::MEASURE_ID,
            self::FIELD_FORMULA_VERSION => self::FORMULA_VERSION,
            self::FIELD_FORMULA => 'Per gate+writer: false_block_rate=false_block/blocks; missed_poison_rate=missed_poison/known_should_catch_denominator (lower_bound_known_miss); band is pure CalibrationBandClassifier over the rate, but status is insufficient_sample until denominator_min is met.',
            'thresholds' => [
                self::FIELD_DENOMINATOR_MIN_SAMPLES => self::DENOMINATOR_MIN,
                self::FIELD_KNOWN_MISS_DENOMINATOR_MUST_BE_NON_ZERO => true,
                'missed_poison_rate_bound' => 'lower_bound_known_miss',
            ],
            self::FIELD_DENOMINATOR_MIN => self::DENOMINATOR_MIN,
            'ttl_days' => self::TTL_DAYS,
            self::FIELD_AUTHOR_ENGINE_ID => 'cursor-acos-max-maxi-03',
            'judge_engine_id' => 'codex-independent-immune-calibration-judge',
            'series' => [
                self::FIELD_ID => self::MEASURE_ID,
                'reader_command' => 'atlas:immune:calibration --json',
                'table' => ImmuneVerdictLedger::TABLE,
                self::FIELD_REGISTRY_STATUS => 'registered_elev_20s',
            ],
            self::FIELD_REGISTRY_STATUS => 'registered_elev_20s',
            self::FIELD_DUAL_READ_REQUIRED => false,
        ];
        $payload[self::FIELD_CONTENT_HASH] = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $payload;
    }

    /** @return array<string,mixed> */
    public function bandForRate(float $rate, int $denominator, int $minimum, string $metric): array
    {
        $denominator = max(0, $denominator);
        $rate = round(AiValueNormalizer::clampUnit($rate), 6);

        if ($metric === 'missed_poison_rate' && $denominator === 0) {
            return [
                self::FIELD_METRIC => $metric,
                self::FIELD_VALUE => $rate,
                self::FIELD_DENOMINATOR => 0,
                self::FIELD_BAND => self::BAND_INSUFFICIENT_SAMPLE,
                self::FIELD_STATUS => self::STATUS_INSUFFICIENT_SAMPLE,
                self::FIELD_REASON => self::REASON_KNOWN_MISS_DENOMINATOR_ZERO,
            ];
        }

        $classified = $this->bandClassifier->classify($rate);
        $status = $denominator >= $minimum ? self::STATUS_CALIBRATED : self::STATUS_INSUFFICIENT_SAMPLE;

        return [
            self::FIELD_METRIC => $metric,
            self::FIELD_VALUE => $rate,
            self::FIELD_DENOMINATOR => $denominator,
            self::FIELD_BAND => $status === self::STATUS_CALIBRATED ? $classified[self::FIELD_BAND] : self::BAND_INSUFFICIENT_SAMPLE,
            self::FIELD_CLASSIFIER_BAND => $classified[self::FIELD_BAND],
            self::FIELD_CLASSIFIER_SCHEMA_VERSION => $classified[self::FIELD_SCHEMA_VERSION],
            self::FIELD_STATUS => $status,
            self::FIELD_REASON => $status === self::STATUS_CALIBRATED ? 'denominator_met' : 'denominator_below_min',
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $samples
     * @return list<array<string,mixed>>
     */
    private function groups(array $samples): array
    {
        $groups = [];
        foreach ($samples as $sample) {
            $writer = AiValueNormalizer::trimmedStringOrNull($sample[self::FIELD_WRITER] ?? null) ?? ImmuneVerdictLedger::WRITER_UNKNOWN;
            $gateStatuses = AiValueNormalizer::arrayOrEmpty($sample[self::FIELD_GATE_STATUSES] ?? null);
            foreach ($this->sampleGateIds($sample) as $gateId) {
                $key = $writer.'::'.$gateId;
                $groups[$key] ??= $this->emptyGroup($writer, $gateId);
                $status = (AiValueNormalizer::trimmedStringOrNull($gateStatuses[$gateId] ?? null) ?? ImmuneVerdictLedger::GATE_STATUS_PENDING);
                $label = (AiValueNormalizer::trimmedStringOrNull($sample['sample_label'] ?? null) ?? '');
                $expectedGateIds = array_fill_keys(AiValueNormalizer::arrayOrEmpty($sample[self::FIELD_EXPECTED_BLOCK_GATE_IDS] ?? null), true);

                $groups[$key]['n']++;
                if ($status === ImmuneVerdictLedger::GATE_STATUS_BLOCK) {
                    $groups[$key][self::FIELD_BLOCKS]++;
                }
                if ($label === ImmuneVerdictLedger::LABEL_FALSE_BLOCK && $status === ImmuneVerdictLedger::GATE_STATUS_BLOCK) {
                    $groups[$key][self::FIELD_FALSE_BLOCKS]++;
                }
                if (isset($expectedGateIds[$gateId])) {
                    $groups[$key][self::FIELD_KNOWN_MISS_DENOMINATOR]++;
                    if ($status !== ImmuneVerdictLedger::GATE_STATUS_BLOCK) {
                        $groups[$key][self::FIELD_MISSED_POISON]++;
                    } else {
                        $groups[$key][self::FIELD_TRUE_BLOCKS]++;
                    }
                }
            }
        }

        ksort($groups);

        return array_values(array_map(fn (array $group): array => $this->finalizeGroup($group), $groups));
    }

    /** @param array<string,mixed> $sample @return list<string> */
    private function sampleGateIds(array $sample): array
    {
        $gateIds = [];
        foreach ([
            ...AiValueNormalizer::arrayOrEmpty($sample[self::FIELD_EXPECTED_BLOCK_GATE_IDS] ?? null),
            ...AiValueNormalizer::arrayOrEmpty($sample[self::FIELD_BLOCKING_GATE_IDS] ?? null),
        ] as $gateId) {
            $gateId = AiValueNormalizer::upperTrimmedString($gateId);
            if (in_array($gateId, self::GATE_IDS, true)) {
                $gateIds[$gateId] = true;
            }
        }

        return array_keys($gateIds);
    }

    /** @return array<string,mixed> */
    private function emptyGroup(string $writer, string $gateId): array
    {
        return [
            self::FIELD_GATE => $gateId,
            self::FIELD_WRITER => $writer,
            'n' => 0,
            self::FIELD_BLOCKS => 0,
            self::FIELD_TRUE_BLOCKS => 0,
            self::FIELD_FALSE_BLOCKS => 0,
            self::FIELD_MISSED_POISON => 0,
            self::FIELD_KNOWN_MISS_DENOMINATOR => 0,
        ];
    }

    /** @param array<string,mixed> $group @return array<string,mixed> */
    private function finalizeGroup(array $group): array
    {
        $blocks = (int) (AiValueNormalizer::finiteFloatOrNull($group[self::FIELD_BLOCKS] ?? null) ?? 0);
        $knownMissDenominator = (int) (AiValueNormalizer::finiteFloatOrNull($group[self::FIELD_KNOWN_MISS_DENOMINATOR] ?? null) ?? 0);
        $falseBlockRate = $blocks > 0 ? (int) (AiValueNormalizer::finiteFloatOrNull($group[self::FIELD_FALSE_BLOCKS] ?? null) ?? 0) / $blocks : 0.0;
        $missedPoisonRate = $knownMissDenominator > 0
            ? (int) (AiValueNormalizer::finiteFloatOrNull($group[self::FIELD_MISSED_POISON] ?? null) ?? 0) / $knownMissDenominator
            : 0.0;

        $group[self::FIELD_FALSE_BLOCK_RATE] = $this->bandForRate(
            $falseBlockRate,
            (int) (AiValueNormalizer::finiteFloatOrNull($group['n'] ?? null) ?? 0),
            self::DENOMINATOR_MIN,
            'false_block_rate',
        );
        $group[self::FIELD_FALSE_BLOCK_RATE][self::FIELD_BLOCKS_DENOMINATOR] = $blocks;
        $group[self::FIELD_MISSED_POISON_RATE] = $this->bandForRate(
            $missedPoisonRate,
            $knownMissDenominator,
            self::DENOMINATOR_MIN,
            'missed_poison_rate',
        );
        $group[self::FIELD_MISSED_POISON_RATE][self::FIELD_BOUND] = 'lower_bound_known_miss';
        $group[self::FIELD_CALIBRATION_STATUS] = $group[self::FIELD_FALSE_BLOCK_RATE][self::FIELD_STATUS] === self::STATUS_CALIBRATED
            && $group[self::FIELD_MISSED_POISON_RATE][self::FIELD_STATUS] === self::STATUS_CALIBRATED
                ? self::STATUS_CALIBRATED
                : self::STATUS_INSUFFICIENT_SAMPLE;

        return $group;
    }

    /** @return array<string,mixed> */
    private function knownMissSeedSample(): array
    {
        $signals = [
            self::FIELD_CONSENT_GRANTED => true,
            'privacy_class' => 'normal',
            'retention_ok' => true,
            self::FIELD_ATOMIC_CLAIM_PRESENT => true,
            self::FIELD_CLAIM_TYPE => 'technical_learning_candidate',
            self::FIELD_CLAIM_SOURCE_PRESENT => true,
            self::FIELD_FUTURE_UTILITY => true,
            'novelty' => true,
            'recurrence_count' => 1,
            'provider_safe' => true,
            self::FIELD_CONTAINS_SECRET => false,
            self::FIELD_CONTAINS_SENSITIVE_UNNECESSARY => false,
            self::FIELD_CONTRADICTS_NEWER => false,
            'outcome_validated' => false,
            'scope' => 'domain',
            'promotion_mode_hint' => 'proposal',
            'on_probation' => true,
        ];

        return $this->ledger->sampleFromVerdict(
            hash('sha256', 'maxi-03-known-miss-g3-seed-v1'),
            'known_miss_seed',
            $this->evaluator->evaluate($signals),
            [
                self::FIELD_EXPECTED_BLOCK_GATE_IDS => ['G3'],
                'metadata' => [
                    'seed' => 'maxi-03-known-should-catch-g3',
                    'pipeline' => 'CognitiveImmunePromotionGateEvaluator',
                    'raw_content_exposed' => false,
                ],
            ],
        );
    }
}
