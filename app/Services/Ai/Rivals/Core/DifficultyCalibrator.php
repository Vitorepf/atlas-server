<?php

namespace App\Services\Ai\Rivals\Core;

/**
 * Difficulty Calibrator (decisão do operador 02/07): o baseline forte em braço
 * bare calibra a SUITE, não o modelo. success_rate alto = suite fácil/contaminada,
 * nunca motivo de celebração. Bandas: >35% too_easy, 30-35% borderline,
 * 20-30% elite_valid, 5-20% hard, <5% frontier (válida, não inválida).
 * Braços harness_* nunca calibram (golden é 100% por construção).
 */
class DifficultyCalibrator
{
    public function bandFor(float $rate): string
    {
        $bands = config('atlas_rivals.difficulty.bands', []);
        $tooEasy = (float) ($bands['too_easy'] ?? 0.35);
        $borderline = (float) ($bands['borderline'] ?? 0.30);
        $eliteValid = (float) ($bands['elite_valid'] ?? 0.20);
        $hard = (float) ($bands['hard'] ?? 0.05);

        return match (true) {
            $rate > $tooEasy => 'too_easy',
            $rate > $borderline => 'borderline',
            $rate >= $eliteValid => 'elite_valid',
            $rate >= $hard => 'hard',
            default => 'frontier',
        };
    }

    /**
     * @param  array<int, array>  $rows  linhas do report (task_type, arm_id, success_rate)
     * @return array{suite_band: ?string, baseline: ?array, row_bands: array<string,string>, flags: list<string>}
     */
    public function calibrate(array $rows): array
    {
        $rowBands = [];
        $flags = [];
        $baseline = null;

        foreach ($rows as $row) {
            if (! $this->isBareModelArm($row['arm_id'])) {
                continue;
            }
            $band = $this->bandFor((float) $row['success_rate']);
            $rowBands["{$row['task_type']}|{$row['arm_id']}"] = $band;
            if ($band === 'too_easy') {
                $max = config('atlas_rivals.difficulty.bands.too_easy', 0.35);
                $flags[] = "suite_too_easy_for:{$row['arm_id']}:{$row['task_type']}:{$row['success_rate']}>{$max}";
            }
            // a suite é calibrada pelo braço bare MAIS FORTE presente
            if ($baseline === null || $row['success_rate'] > $baseline['success_rate']) {
                $baseline = [
                    'arm_id' => $row['arm_id'],
                    'task_type' => $row['task_type'],
                    'success_rate' => $row['success_rate'],
                ];
            }
        }

        return [
            'suite_band' => $baseline === null ? null : $this->bandFor((float) $baseline['success_rate']),
            'baseline' => $baseline,
            'row_bands' => $rowBands,
            'flags' => $flags,
        ];
    }

    private function isBareModelArm(string $armId): bool
    {
        return str_ends_with($armId, '@bare') && ! str_starts_with($armId, 'harness_');
    }
}
