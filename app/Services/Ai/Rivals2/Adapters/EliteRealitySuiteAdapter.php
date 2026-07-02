<?php

namespace App\Services\Ai\Rivals2\Adapters;

/**
 * Elite Reality Suite: camada de dificuldade elite sobre o AtlasBench.
 * Calibração alvo: frontier bare 20-30%; acima de 35% a suite é acusada
 * (DifficultyCalibrator), nunca celebrada. Diferenças vs atlas_bench:
 * - pisos mais duros (diff/arquivos, config 'elite');
 * - ticket SEM o subject do commit (o título "fix: X" é receita) — quando não
 *   há corpo de ticket, vira "unknown unknown": só o sintoma, o solver precisa
 *   DESCOBRIR o problema antes de resolver;
 * - case sem corpo E sem sintoma é descartado (prompt vazio não é tarefa);
 * - task families sênior classificadas por conteúdo real do commit.
 * Contamination Guard e hidden tests vêm da base (fail-closed).
 */
class EliteRealitySuiteAdapter extends AtlasBenchSuiteAdapter
{
    public const SUITE_ID = 'elite_reality';

    public function suiteId(): string
    {
        return self::SUITE_ID;
    }

    protected function configBlock(): string
    {
        return 'elite';
    }

    protected function acceptCase(array $case): bool
    {
        // sem corpo e sem sintoma não existe tarefa under-specified — existe tarefa nenhuma
        return ! empty($case['ticket_body']) || ! empty($case['symptom_excerpt']);
    }

    protected function taskTypeFor(string $subject, string $body = '', array $codeFiles = []): string
    {
        $text = mb_strtolower($subject.' '.$body);
        $paths = mb_strtolower(implode(' ', $codeFiles));

        return match (true) {
            preg_match('/security|privacy|secret|auth|permission|leak/', $text.' '.$paths) === 1 => 'security_privacy_boundary',
            preg_match('/race|concurren|lock|deadlock|atomic|toctou/', $text) === 1 => 'concurrency_state_bug',
            preg_match('/flaky|intermittent|nondeterminis|sometimes/', $text) === 1 => 'flaky_behavior',
            preg_match('/perf|slow|latency|n\+1|memory|oom|timeout/', $text) === 1 => 'performance_regression',
            preg_match('/migrat|backward|compat|deprecat|upgrade/', $text) === 1 => 'migration_backward_compat',
            preg_match('/refactor|extract|restructur|arch|design|decoupl/', $text) === 1 => 'architecture_refactor',
            preg_match('/fix|bug|broken|regress|wrong|incorrect/', $text) === 1 => 'senior_bug_investigation',
            count($codeFiles) >= 5 => 'long_horizon_repair',
            empty(trim($body)) => 'unknown_unknown',
            default => 'feature_under_specified',
        };
    }

    protected function ticketFor(array $case): string
    {
        $ticket = ['# Engineering ticket', ''];
        if (! empty($case['ticket_body'])) {
            $ticket[] = $case['ticket_body'];
            $ticket[] = '';
        } else {
            // unknown unknown: nem sabemos nomear o problema — descubra-o
            $ticket[] = 'Something in this codebase is broken or missing. We only have the report';
            $ticket[] = 'below. Discover what the actual problem is before attempting any fix.';
            $ticket[] = '';
        }
        if (! empty($case['symptom_excerpt'])) {
            $ticket[] = '## Observed behavior (report from the team)';
            $ticket[] = '```';
            $ticket[] = $case['symptom_excerpt'];
            $ticket[] = '```';
            $ticket[] = '';
        }
        $ticket[] = 'You are a senior engineer on this codebase. The problem statement is';
        $ticket[] = 'intentionally incomplete, as in real work. Investigate the repository,';
        $ticket[] = 'identify the root cause or the missing capability, and implement a';
        $ticket[] = 'production-quality change. Acceptance is graded against hidden checks';
        $ticket[] = 'you will never see; behavior is what counts, not resemblance to a recipe.';
        $ticket[] = 'Prefer the minimal change that fixes the root cause. Do not hardcode';
        $ticket[] = 'observed values, do not fake behavior with mocks, keep the blast radius';
        $ticket[] = 'small, and preserve backward compatibility. Do not ask questions.';

        return implode("\n", $ticket);
    }
}
