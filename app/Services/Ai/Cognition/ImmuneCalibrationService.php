<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition;

use App\Services\Ai\Cognitive\PredictiveFailure\CalibrationBandClassifier;
use App\Services\Ai\Support\AiValueNormalizer;

final class ImmuneCalibrationService
{
    public const SCHEMA_VERSION = 'atlas.cognition.immune_calibration.v1';

    public const MEASURE_ID = 'atlas.immune.calibration.v1';

    public const FORMULA_VERSION = 'immune_calibration_fp_fn_bands.v1';

    public const DENOMINATOR_MIN = 10;

    public const TTL_DAYS = 90;

    /** @var list<string> */
    public const GATE_IDS = ['G0', 'G1', 'G2', 'G3', 'G4', 'G5', 'G6', 'G7', 'G8'];

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
            static fn (array $group): bool => ($group['calibration_status'] ?? null) === 'calibrated',
        ));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => now()->toIso8601String(),
            'mode' => 'read_only',
            'measure_id' => self::MEASURE_ID,
            'formula_version' => self::FORMULA_VERSION,
            'status' => $okGroups > 0 ? 'ok' : 'insufficient_sample',
            'denominator_min' => self::DENOMINATOR_MIN,
            'freeze' => self::freezePayload(),
            'samples' => [
                'ledger' => count($ledgerRows),
                'known_miss_seed' => count($seedRows),
                'total' => count($samples),
            ],
            'caveats' => [
                'missed_poison_rate' => 'lower_bound_known_miss: denominator is seeded known-should-catch plus labelled real known-miss samples only; never treated as calibrated when denominator is zero.',
                'control' => 'informational_only_never_auto_adjusts_gate',
            ],
            'groups' => $groups,
        ];
    }

    /** @return array<string,mixed> */
    public static function freezePayload(): array
    {
        $payload = [
            'kind' => 'measure_freeze',
            'measure_id' => self::MEASURE_ID,
            'formula_version' => self::FORMULA_VERSION,
            'formula' => 'Per gate+writer: false_block_rate=false_block/blocks; missed_poison_rate=missed_poison/known_should_catch_denominator (lower_bound_known_miss); band is pure CalibrationBandClassifier over the rate, but status is insufficient_sample until denominator_min is met.',
            'thresholds' => [
                'denominator_min_samples' => self::DENOMINATOR_MIN,
                'known_miss_denominator_must_be_non_zero' => true,
                'missed_poison_rate_bound' => 'lower_bound_known_miss',
            ],
            'denominator_min' => self::DENOMINATOR_MIN,
            'ttl_days' => self::TTL_DAYS,
            'author_engine_id' => 'cursor-acos-max-maxi-03',
            'judge_engine_id' => 'codex-independent-immune-calibration-judge',
            'series' => [
                'id' => self::MEASURE_ID,
                'reader_command' => 'atlas:immune:calibration --json',
                'table' => ImmuneVerdictLedger::TABLE,
                'registry_status' => 'registered_elev_20s',
            ],
            'registry_status' => 'registered_elev_20s',
            'dual_read_required' => false,
        ];
        $payload['content_hash'] = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $payload;
    }

    /** @return array<string,mixed> */
    public function bandForRate(float $rate, int $denominator, int $minimum, string $metric): array
    {
        $denominator = max(0, $denominator);
        $rate = round(AiValueNormalizer::clampUnit($rate), 6);

        if ($metric === 'missed_poison_rate' && $denominator === 0) {
            return [
                'metric' => $metric,
                'value' => $rate,
                'denominator' => 0,
                'band' => 'insufficient_sample',
                'status' => 'insufficient_sample',
                'reason' => 'known_miss_denominator_zero',
            ];
        }

        $classified = $this->bandClassifier->classify($rate);
        $status = $denominator >= $minimum ? 'calibrated' : 'insufficient_sample';

        return [
            'metric' => $metric,
            'value' => $rate,
            'denominator' => $denominator,
            'band' => $status === 'calibrated' ? $classified['band'] : 'insufficient_sample',
            'classifier_band' => $classified['band'],
            'classifier_schema_version' => $classified['schema_version'],
            'status' => $status,
            'reason' => $status === 'calibrated' ? 'denominator_met' : 'denominator_below_min',
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
            $writer = AiValueNormalizer::trimmedString($sample['writer'] ?? 'unknown') ?: 'unknown';
            $gateStatuses = (array) ($sample['gate_statuses'] ?? []);
            foreach ($this->sampleGateIds($sample) as $gateId) {
                $key = $writer.'::'.$gateId;
                $groups[$key] ??= $this->emptyGroup($writer, $gateId);
                $status = (string) ($gateStatuses[$gateId] ?? 'pending');
                $label = (string) ($sample['sample_label'] ?? '');
                $expectedGateIds = array_fill_keys((array) ($sample['expected_block_gate_ids'] ?? []), true);

                $groups[$key]['n']++;
                if ($status === 'block') {
                    $groups[$key]['blocks']++;
                }
                if ($label === ImmuneVerdictLedger::LABEL_FALSE_BLOCK && $status === 'block') {
                    $groups[$key]['false_blocks']++;
                }
                if (isset($expectedGateIds[$gateId])) {
                    $groups[$key]['known_miss_denominator']++;
                    if ($status !== 'block') {
                        $groups[$key]['missed_poison']++;
                    } else {
                        $groups[$key]['true_blocks']++;
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
            ...(array) ($sample['expected_block_gate_ids'] ?? []),
            ...(array) ($sample['blocking_gate_ids'] ?? []),
        ] as $gateId) {
            $gateId = strtoupper(trim((string) $gateId));
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
            'gate' => $gateId,
            'writer' => $writer,
            'n' => 0,
            'blocks' => 0,
            'true_blocks' => 0,
            'false_blocks' => 0,
            'missed_poison' => 0,
            'known_miss_denominator' => 0,
        ];
    }

    /** @param array<string,mixed> $group @return array<string,mixed> */
    private function finalizeGroup(array $group): array
    {
        $blocks = (int) $group['blocks'];
        $knownMissDenominator = (int) $group['known_miss_denominator'];
        $falseBlockRate = $blocks > 0 ? (int) $group['false_blocks'] / $blocks : 0.0;
        $missedPoisonRate = $knownMissDenominator > 0
            ? (int) $group['missed_poison'] / $knownMissDenominator
            : 0.0;

        $group['false_block_rate'] = $this->bandForRate(
            $falseBlockRate,
            (int) $group['n'],
            self::DENOMINATOR_MIN,
            'false_block_rate',
        );
        $group['false_block_rate']['blocks_denominator'] = $blocks;
        $group['missed_poison_rate'] = $this->bandForRate(
            $missedPoisonRate,
            $knownMissDenominator,
            self::DENOMINATOR_MIN,
            'missed_poison_rate',
        );
        $group['missed_poison_rate']['bound'] = 'lower_bound_known_miss';
        $group['calibration_status'] = $group['false_block_rate']['status'] === 'calibrated'
            && $group['missed_poison_rate']['status'] === 'calibrated'
                ? 'calibrated'
                : 'insufficient_sample';

        return $group;
    }

    /** @return array<string,mixed> */
    private function knownMissSeedSample(): array
    {
        $signals = [
            'consent_granted' => true,
            'privacy_class' => 'normal',
            'retention_ok' => true,
            'atomic_claim_present' => true,
            'claim_type' => 'technical_learning_candidate',
            'claim_source_present' => true,
            'future_utility' => true,
            'novelty' => true,
            'recurrence_count' => 1,
            'provider_safe' => true,
            'contains_secret' => false,
            'contains_sensitive_unnecessary' => false,
            'contradicts_newer' => false,
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
                'expected_block_gate_ids' => ['G3'],
                'metadata' => [
                    'seed' => 'maxi-03-known-should-catch-g3',
                    'pipeline' => 'CognitiveImmunePromotionGateEvaluator',
                    'raw_content_exposed' => false,
                ],
            ],
        );
    }
}
