<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Services\Ai\Support\AiValueNormalizer;

final class PredictedImpactBand
{
    public const SCHEMA_VERSION = 'atlas.originator.predicted_impact_band.v1';

    /** @var array<string,int> */
    public const RUNG_WEIGHT = ['task' => 0, 'slice' => 1, 'obra' => 2, 'salto' => 3];

    /** @var list<string> */
    public const BANDS = ['low', 'sweet', 'high'];

    /**
     * @param  array<string,mixed>  $candidate
     * @return array<string,mixed>
     */
    public static function classify(array $candidate): array
    {
        $rung = AiValueNormalizer::lowerTrimmedString($candidate['rung'] ?? 'task');
        $rank = max(1, (int) ($candidate['rank'] ?? 99));
        $yield = AiValueNormalizer::clampUnit(AiValueNormalizer::finiteFloatOrNull($candidate['path_yield'] ?? null) ?? 0.0);
        $score = (self::RUNG_WEIGHT[$rung] ?? 0) + ($rank <= 3 ? 1 : 0) + ($yield >= 0.5 ? 1 : 0);
        $band = match (true) {
            $score >= 4 => 'high',
            $score >= 2 => 'sweet',
            default => 'low',
        };

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'band' => $band,
            'components' => ['rung' => $rung, 'rank' => $rank, 'path_yield' => $yield],
            'source' => [
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
            $band = AiValueNormalizer::trimmedStringOrNull($row['band'] ?? null) ?? 'low';
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
            'schema_version' => self::SCHEMA_VERSION,
            'bands' => $bands,
            'source' => [
                'unresolved_counts_as_success' => false,
                'report_only' => true,
            ],
        ];
    }
}
