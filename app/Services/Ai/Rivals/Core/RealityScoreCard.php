<?php

namespace App\Services\Ai\Rivals\Core;

/**
 * Reality Score: vetor de dimensões por receipt — NUNCA colapsado em número
 * único (isso morreu com o Rivals 1.0). Só dimensões mecanicamente prováveis
 * recebem valor; o que exige juiz fica declarado em requires_judge como null,
 * jamais inventado. Penaliza sinal de overfit: patch inchado, hardcode do
 * sintoma, blast radius fora dos arquivos do golden.
 */
class RealityScoreCard
{
    public function evaluate(array $case, string $patch, string $status): array
    {
        $patchLines = count(preg_grep('/^[+-][^+-]/', explode("\n", $patch)));
        $goldenLines = $case['diff_lines'] ?? null;
        $bloat = ! empty($goldenLines) ? round($patchLines / $goldenLines, 3) : null;
        $maxBloat = (float) config('atlas_rivals.reality.max_bloat_ratio', 2.0);

        $touched = [];
        if (preg_match_all('/^\+\+\+ b\/(.+)$/m', $patch, $m)) {
            $touched = array_values(array_unique($m[1]));
        }
        $goldenCode = $case['changed_files']['code'] ?? [];
        $outsideGolden = array_values(array_diff($touched, $goldenCode));
        $hiddenTests = ($case['changed_files']['tests'] ?? []) !== [];

        return [
            'schema_version' => 'atlas.rivals2.reality_score.v1',
            'success' => $status === 'success',
            // no protocolo hidden-tests, sucesso == passar a regressão oculta
            'hidden_regression_pass' => $hiddenTests ? $status === 'success' : null,
            'patch_lines' => $patchLines,
            'golden_lines' => $goldenLines,
            'bloat_ratio' => $bloat,
            'minimal' => $bloat === null ? null : $bloat <= $maxBloat,
            'files_touched' => count($touched),
            'blast_radius_outside_golden' => count($outsideGolden),
            'hardcode_suspect' => $this->hardcodeSuspect($case, $patch),
            // dimensões que exigem juiz: declaradas, nunca fabricadas
            'requires_judge' => ['root_cause_correctness', 'maintainability', 'investigation_quality'],
        ];
    }

    /** Linha adicionada ecoando literal do sintoma = suspeita de hardcode do output esperado. */
    private function hardcodeSuspect(array $case, string $patch): ?bool
    {
        $symptom = (string) ($case['symptom_excerpt'] ?? '');
        if (trim($symptom) === '') {
            return null;
        }
        $added = implode("\n", preg_grep('/^\+[^+]/', explode("\n", $patch)));
        if (! preg_match_all('/[\'"]([^\'"]{8,})[\'"]/', $added, $m)) {
            return false;
        }
        foreach ($m[1] as $literal) {
            if (str_contains($symptom, $literal)) {
                return true;
            }
        }

        return false;
    }
}
