<?php

declare(strict_types=1);

namespace App\Services\Ai\Vox\Dogfood;

use App\Services\Ai\Vox\Gate\VoxV7UnlockGateService;
use App\Services\Ai\Vox\Metrics\VoxMetricsService;
use Carbon\CarbonImmutable;

/**
 * Atlas Vox V6 · dogfood summary humano (PT-BR).
 *
 * Lê {@see VoxMetricsService::snapshot()} + {@see VoxDogfoodService::report()}
 * + {@see VoxV7UnlockGateService::evaluate()} e devolve um envelope curto que
 * responde, em uma linha cada:
 *
 *   - "Você usou X vezes."
 *   - "Ditado funcionou Y%."
 *   - "Você regravou Z vezes."
 *   - "Principais problemas: ..."
 *   - "Ainda não há dados suficientes para V7."
 *
 * Serviço **read-only**. Não escreve no ledger. Não chama provider. Não
 * destrava nada.
 *
 * Output canônico: `atlas.vox.dogfood_summary.v1`.
 */
final class VoxDogfoodSummaryService
{
    public const SCHEMA = 'atlas.vox.dogfood_summary.v1';
    public const VERSION = '0.1.0';

    public function __construct(
        private readonly VoxMetricsService $metrics,
        private readonly VoxDogfoodService $dogfood,
        private readonly VoxV7UnlockGateService $v7Gate,
    ) {}

    /**
     * @return array{
     *   schema: string,
     *   version: string,
     *   sentences_pt_br: list<string>,
     *   headline_pt_br: string,
     *   v7_status_pt_br: string,
     *   ready_for_daily_use: bool,
     *   numbers: array<string,mixed>,
     *   top_issues_pt_br: list<string>,
     *   v7_blockers_pt_br: list<string>,
     *   generated_at: string
     * }
     */
    public function build(): array
    {
        $metricsSnap = $this->metrics->snapshot();
        $report = $this->dogfood->report();
        $v7 = $this->v7Gate->evaluate();

        $dogfoodTotal = (int) ($report['sessions_total'] ?? 0);
        $ledgerTotal = (int) ($metricsSnap['summary']['total_sessions'] ?? 0);
        // Vitor enxerga o número que estiver maior — ele só quer saber "usei
        // quantas vezes". Se não há dogfood ainda, mostramos o ledger; se há
        // dogfood, o dogfood é mais preciso porque captura o que ele realmente
        // tratou como sessão útil.
        $totalDisplay = max($dogfoodTotal, $ledgerTotal);

        $successRate = (float) ($report['success_rate'] ?? 0.0);
        $regretRate = (float) ($report['regret_rate'] ?? 0.0);

        $sentences = [];
        $sentences[] = sprintf(
            'Você usou o Atlas Vox %d %s.',
            $totalDisplay,
            $totalDisplay === 1 ? 'vez' : 'vezes',
        );

        if ($dogfoodTotal > 0) {
            $sentences[] = sprintf(
                'Ditado e prompts funcionaram em %d%% das sessões.',
                (int) round($successRate * 100),
            );
            // "Regravou" = sessões marcadas como `failed` ou `cancelled`,
            // somadas a regret_flag. Mostramos um número humano único.
            $regrettedOrCancelled = (int) round(
                ($report['outcomes']['failed'] ?? 0)
                + ($report['outcomes']['cancelled'] ?? 0),
            );
            $sentences[] = sprintf(
                'Você regravou ou cancelou %d %s.',
                $regrettedOrCancelled,
                $regrettedOrCancelled === 1 ? 'vez' : 'vezes',
            );
            if ($regretRate > 0) {
                $sentences[] = sprintf(
                    'Marcou %d%% das sessões como "voltei atrás" (arrependimento).',
                    (int) round($regretRate * 100),
                );
            }
        } else {
            // V6-DOGFOOD-FINAL · sem uso real ainda NÃO é erro — só "ainda
            // coletando". Sem termos técnicos, sem endpoint cru pro Vitor.
            $sentences[] = 'Ainda coletando uso real — abra o Atlas Vox, use no dia a dia e marque cada sessão como "Funcionou" ou "Ruim".';
        }

        $topIssues = $this->topIssuesPtBr($metricsSnap, $report);
        if ($topIssues !== []) {
            $sentences[] = 'Principais problemas: '.implode(' · ', $topIssues).'.';
        }

        $v7BlockersPtBr = $v7['blockers_pt_br'] ?? [];
        // V6-OBSERVABILITY-FINAL · V7 (memória entre dias) é bloqueada POR
        // DESIGN até haver uso real suficiente. Não é erro, não é falha — é
        // a forma como o produto foi pensado pra evitar memória sem base.
        $v7StatusPtBr = $v7BlockersPtBr === []
            ? 'Memória entre dias (V7) bloqueada por design — só destrava com nova ADR.'
            : 'Memória entre dias (V7) bloqueada por design até haver uso real suficiente. '
                .((string) ($v7['summary_pt_br'] ?? ''));

        $sentences[] = $v7StatusPtBr;

        $readyForDaily = $this->isReadyForDailyUse($metricsSnap, $report);

        $headline = $this->headlinePtBr($totalDisplay, $successRate, $readyForDaily);

        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'sentences_pt_br' => $sentences,
            'headline_pt_br' => $headline,
            'v7_status_pt_br' => $v7StatusPtBr,
            'ready_for_daily_use' => $readyForDaily,
            'numbers' => [
                'total_sessions' => $totalDisplay,
                'dogfood_sessions' => $dogfoodTotal,
                'ledger_sessions' => $ledgerTotal,
                'success_rate' => $successRate,
                'regret_rate' => $regretRate,
                'real_usage_days' => (int) ($metricsSnap['summary']['real_usage_days'] ?? 0),
                'sessions_last_7_days' => (int) ($report['sessions_last_7_days'] ?? 0),
                'dictionary_corrections' => (int) ($metricsSnap['summary']['dictionary_correction_count'] ?? 0),
                'raw_audio_persisted_count' => (int) ($metricsSnap['hard_gates']['raw_audio_persisted_count'] ?? 0),
                'destructive_action_without_receipt' => (int) ($metricsSnap['hard_gates']['destructive_action_without_receipt'] ?? 0),
            ],
            'top_issues_pt_br' => $topIssues,
            'v7_blockers_pt_br' => $v7BlockersPtBr,
            'generated_at' => CarbonImmutable::now('UTC')->toIso8601String(),
        ];
    }

    /**
     * @param  array<string,mixed>  $metricsSnap
     * @param  array<string,mixed>  $report
     * @return list<string>
     */
    private function topIssuesPtBr(array $metricsSnap, array $report): array
    {
        $issues = [];
        $rawAudio = (int) ($metricsSnap['hard_gates']['raw_audio_persisted_count'] ?? 0);
        if ($rawAudio > 0) {
            $issues[] = sprintf('áudio bruto persistido em %d evento(s) (Lei 0.5 quebrada)', $rawAudio);
        }
        $destructive = (int) ($metricsSnap['hard_gates']['destructive_action_without_receipt'] ?? 0);
        if ($destructive > 0) {
            $issues[] = sprintf('ação destrutiva sem receipt registrada %d vez(es)', $destructive);
        }
        $bypass = (int) ($metricsSnap['hard_gates']['confirmation_bypass_count'] ?? 0);
        if ($bypass > 0) {
            $issues[] = sprintf('tentativa de bypass de confirmação %d vez(es)', $bypass);
        }
        $sttFailedRate = (float) ($report['stt_failed_rate'] ?? 0.0);
        if ($sttFailedRate >= 0.20) {
            $issues[] = sprintf('STT falhou em %d%% das sessões automáticas', (int) round($sttFailedRate * 100));
        }
        $emptyTranscript = (float) ($report['empty_transcript_rate'] ?? 0.0);
        if ($emptyTranscript >= 0.20) {
            $issues[] = sprintf('transcript vazio em %d%% das sessões automáticas', (int) round($emptyTranscript * 100));
        }
        $regretRate = (float) ($report['regret_rate'] ?? 0.0);
        $total = (int) ($report['sessions_total'] ?? 0);
        if ($total > 0 && $regretRate >= 0.20) {
            $issues[] = sprintf(
                'arrependimento em %d%% das sessões (você voltou atrás)',
                (int) round($regretRate * 100),
            );
        }
        $manualOverride = (float) ($report['manual_override_rate'] ?? 0.0);
        if ($manualOverride >= 0.40) {
            $issues[] = sprintf('Auto Mode sobrescrito manualmente em %d%% das sessões (calibrar router?)', (int) round($manualOverride * 100));
        }
        // V6-K · sinais novos: regravação e edição manual de transcript.
        $reRecorded = (float) ($report['re_recorded_rate'] ?? 0.0);
        if ($reRecorded >= 0.30) {
            $issues[] = sprintf(
                'regravou em %d%% das sessões (revise microfone/STT)',
                (int) round($reRecorded * 100),
            );
        }
        $editedTranscript = (float) ($report['edited_transcript_rate'] ?? 0.0);
        if ($editedTranscript >= 0.40) {
            $issues[] = sprintf(
                'editou transcript em %d%% das sessões (adicione termos ao dicionário pessoal)',
                (int) round($editedTranscript * 100),
            );
        }

        return $issues;
    }

    /**
     * "Pronto para uso diário" = checks duros zerados, Vitor já usou pelo
     * menos um pouco, e não há sinal grave (regret/empty/STT-fail).
     *
     * Distinto de V7: aqui é o sinal "vai em frente e usa", não "destrava
     * nova fase".
     *
     * @param  array<string,mixed>  $metricsSnap
     * @param  array<string,mixed>  $report
     */
    private function isReadyForDailyUse(array $metricsSnap, array $report): bool
    {
        if ((int) ($metricsSnap['hard_gates']['raw_audio_persisted_count'] ?? 0) > 0) {
            return false;
        }
        if ((int) ($metricsSnap['hard_gates']['destructive_action_without_receipt'] ?? 0) > 0) {
            return false;
        }
        if ((int) ($metricsSnap['hard_gates']['confirmation_bypass_count'] ?? 0) > 0) {
            return false;
        }
        $dogfoodTotal = (int) ($report['sessions_total'] ?? 0);
        $ledgerTotal = (int) ($metricsSnap['summary']['total_sessions'] ?? 0);
        if ($dogfoodTotal === 0 && $ledgerTotal === 0) {
            // Sem uso ainda. "Não está bloqueado", mas também não dá pra dizer
            // que está pronto. Devolvemos false até existir ao menos UMA
            // evidência real.
            return false;
        }
        if ($dogfoodTotal > 0) {
            $regretRate = (float) ($report['regret_rate'] ?? 0.0);
            if ($regretRate >= 0.20) {
                return false;
            }
        }

        return true;
    }

    private function headlinePtBr(int $totalDisplay, float $successRate, bool $ready): string
    {
        if ($totalDisplay === 0) {
            return 'Atlas Vox ainda sem uso real — abra o overlay e fale para começar a medir.';
        }
        if (! $ready) {
            return 'Atlas Vox em rodagem — revise os problemas listados antes de uso pesado.';
        }
        if ($successRate >= 0.80) {
            return 'Atlas Vox parece sólido para uso diário — continue medindo antes de pensar em V7.';
        }

        return 'Atlas Vox utilizável; siga registrando sessões para refinar.';
    }
}
