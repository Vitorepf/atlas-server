<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Services\Ai\Support\AiValueNormalizer;

final class PredictedImpactBand
{
    public const SCHEMA_VERSION = 'atlas.originator.predicted_impact_band.v1';

    /** @var array<string,int> */
    public const RUNG_WEIGHT = [self::FIELD_TASK => 0, self::FIELD_SLICE => 1, self::FIELD_OBRA => 2, self::FIELD_SALTO => 3];

    /** @var list<string> */
    public const BANDS = ['low', 'sweet', 'high'];

    public const DEFAULT_RANK_FALLBACK = 99;

    public const RANK_TOP_CUTOFF = 3;

    public const YIELD_SWEET_FLOOR = 0.5;

    public const HIGH_SCORE_FLOOR = 4;

    public const SWEET_SCORE_FLOOR = 2;
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_SOURCE = 'source';
    public const FIELD_TASK = 'task';
    public const FIELD_SLICE = 'slice';
    public const FIELD_OBRA = 'obra';
    public const FIELD_SALTO = 'salto';
    public const FIELD_BAND = 'band';
    public const FIELD_COMPONENTS = 'components';

    /**
     * @param  array<string,mixed>  $candidate
     * @return array<string,mixed>
     */
    public static function classify(array $candidate): array
    {
        $rung = AiValueNormalizer::lowerTrimmedString($candidate['rung'] ?? 'task');
        $rank = max(1, (int) (AiValueNormalizer::finiteFloatOrNull($candidate['rank'] ?? null) ?? self::DEFAULT_RANK_FALLBACK));
        $yield = AiValueNormalizer::clampUnit(AiValueNormalizer::finiteFloatOrNull($candidate['path_yield'] ?? null) ?? 0.0);
        $score = (self::RUNG_WEIGHT[$rung] ?? 0) + ($rank <= self::RANK_TOP_CUTOFF ? 1 : 0) + ($yield >= self::YIELD_SWEET_FLOOR ? 1 : 0);
        $band = match (true) {
            $score >= self::HIGH_SCORE_FLOOR => 'high',
            $score >= self::SWEET_SCORE_FLOOR => 'sweet',
            default => 'low',
        };

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_BAND => $band,
            self::FIELD_COMPONENTS => ['rung' => $rung, 'rank' => $rank, 'path_yield' => $yield],
            self::FIELD_SOURCE => [
                'caller_declared_band_ignored' => true,
                'influences_pick' => false,
                'single_scalar_score_emitted' => false,
            ],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,mixed>
     */
    public static function calibration(array $rows): array
    {
        $bands = [];
        foreach (self::BANDS as $band) {
            $bands[$band] = ['n_realized' => 0, 'realized_true' => 0, 'unresolved' => 0];
        }

        foreach ($rows as $row) {
            $band = AiValueNormalizer::trimmedStringOrNull($row[self::FIELD_BAND] ?? null) ?? 'low';
            if (! isset($bands[$band])) {
                continue;
            }
            if (AiValueNormalizer::trimmedStringOrNull($row['status'] ?? null) === 'unresolved') {
                $bands[$band]['unresolved']++;
                continue;
            }
            $bands[$band]['n_realized']++;
            if (($row['realized'] ?? false) === true) {
                $bands[$band]['realized_true']++;
            }
        }

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            'bands' => $bands,
            self::FIELD_SOURCE => [
                'unresolved_counts_as_success' => false,
                'report_only' => true,
            ],
        ];
    }
}
