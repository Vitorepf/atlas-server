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
     * A MESMA régua, com o denominador que a torna verdadeira.
     *
     * `bandFor()` decide pela estimativa pontual, e sem o n isso é um dado. Um
     * instrumento cuja dificuldade REAL é 30% — exatamente a banda que o
     * operador quer — cai, com n=9 e só por sorte, em CINCO bandas: too_easy
     * 27%, elite_valid 27%, borderline 27%, hard 16%, frontier 4%.
     *
     * A prova viva está no próprio relatório: `gpqa_diamond:Physics` (3/3) sai
     * `too_easy` com Wilson95 [43,9%, 100%]; `gpqa_diamond:Chemistry` (0/3) sai
     * `frontier` com [0%, 56,1%]. MESMO instrumento, MESMO modelo, vereditos
     * opostos — e os intervalos se sobrepõem em 12 pontos.
     *
     * Aposentar uma suíte é decisão cara e quase irreversível. Tomá-la no cara
     * ou coroa é pior que não tomá-la: por isso `unknown` quando o intervalo
     * cruza a fronteira da banda. `unknown` não é omissão — é o único veredito
     * honesto sobre uma amostra que não decide, e ele diz o que fazer: dar n.
     *
     * @param  int  $successes  acertos do braço bare
     * @param  int  $n  tentativas do braço bare
     */
    public function bandForCounts(int $successes, int $n): string
    {
        if ($n <= 0) {
            return 'unknown';
        }
        $interval = StatisticalPolicy::wilson($successes, $n);
        $low = $this->bandFor($interval['low']);
        $high = $this->bandFor($interval['high']);

        // Intervalo inteiro dentro de uma banda = a amostra decide. Cruzou a
        // fronteira = ela não decide, e fingir que decide é o defeito.
        return $low === $high ? $low : 'unknown';
    }

    /**
     * Quanto n falta para a suíte poder ser ACUSADA de fácil demais.
     *
     * Lei de ingresso: para PROVAR bare abaixo do teto t, é preciso
     * `n >= z²(1-t)/t`. Sem isso o degrau não pode ser declarado — o n é o
     * bilhete de entrada, não um detalhe. Devolve o n mínimo para que uma
     * observação de ZERO acerto prove que a suíte está abaixo de `$ceiling`.
     */
    public function entryN(float $ceiling): int
    {
        if ($ceiling <= 0.0 || $ceiling >= 1.0) {
            return 0;
        }
        $z2 = 1.959963984540054 ** 2;

        return (int) ceil($z2 * (1.0 - $ceiling) / $ceiling);
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
