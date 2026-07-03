<?php

namespace App\Services\Ai\Rivals\Core;

/**
 * Contamination Guard: um case só é prova de capacidade se for fresh, pinado a
 * snapshot do repo, com prova oculta e prompt sem receita. Fail-closed — case
 * com violação não entra em corpus nem em plan; corpus envelhecido (long-lived)
 * é violação, não patrimônio. Auditoria é mecânica e provider-free.
 */
class ContaminationGuard
{
    /** @return array{schema_version: string, prompt_fingerprint: ?string, violations: list<string>} */
    public function audit(array $case): array
    {
        $violations = [];

        foreach (['base_sha', 'golden_sha'] as $field) {
            if (! preg_match('/^[0-9a-f]{40}$/', (string) ($case[$field] ?? ''))) {
                $violations[] = "repo_snapshot_missing:{$field}";
            }
        }

        if (($case['changed_files']['tests'] ?? []) === [] || trim((string) ($case['check_command'] ?? '')) === '') {
            $violations[] = 'no_hidden_check';
        }

        $minedAt = $case['mined_at'] ?? null;
        if ($minedAt === null) {
            $violations[] = 'mined_at_missing';
        } else {
            $maxAge = (int) config('atlas_rivals.contamination.max_case_age_days', 30);
            $ageDays = (int) floor((now()->getTimestamp() - strtotime((string) $minedAt)) / 86400);
            if ($ageDays > $maxAge) {
                $violations[] = "case_stale:{$ageDays}d>{$maxAge}d";
            }
        }

        // texto AUTORAL que chega ao solver — receita determinística é contaminação
        $authored = implode("\n", array_filter([$case['title'] ?? '', $case['ticket_body'] ?? '']));
        $symptom = (string) ($case['symptom_excerpt'] ?? '');
        if (preg_match('/tests\/|phpunit/i', $authored)) {
            $violations[] = 'recipe_test_path_leak';
        }
        if (preg_match_all('/^\s*\d+[\.\)]\s+\S/m', $authored) >= 3) {
            $violations[] = 'recipe_step_checklist';
        }
        // sintoma é capturado por máquina e já sanitizado; se ainda vazar prova ou
        // apontar o arquivo-alvo exato, o case não vale como medição elite
        if (str_contains($symptom, 'tests/')) {
            $violations[] = 'symptom_test_path_leak';
        }
        foreach ($case['changed_files']['code'] ?? [] as $codeFile) {
            if ($codeFile !== '' && str_contains($authored."\n".$symptom, $codeFile)) {
                $violations[] = "recipe_target_file_leak:{$codeFile}";
            }
        }
        $visible = $authored."\n".$symptom;

        return [
            'schema_version' => 'atlas.rivals2.contamination.v1',
            'prompt_fingerprint' => $visible === '' ? null : hash('sha256', $visible.'|'.($case['base_sha'] ?? '')),
            'violations' => array_values($violations),
        ];
    }
}
