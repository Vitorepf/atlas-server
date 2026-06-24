<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

use App\Models\AiKeywordDecisionLedger;
use Illuminate\Support\Collection;

/**
 * KeywordDecisionLedgerRepository (L12 — governança/escala) — persiste o Decision-Receipt por keyword de forma
 * DURÁVEL e idempotente (chave = receipt_hash). É o que dá "proveniência por decisão" consultável no tempo +
 * escala industrial: um run de pipeline grava todos os recibos da seleção; depois dá pra auditar QUALQUER
 * keyword (por que entrou, que leis sustentam, que veredito) sem re-rodar nada.
 *
 * A persistência é SIDE-EFFECT de governança, fora do caminho de decisão (que continua puro/determinístico).
 */
class KeywordDecisionLedgerRepository
{
    /** Grava um Decision-Receipt. Idempotente: mesmo receipt_hash → atualiza a mesma linha, nunca duplica. */
    public function record(array $receipt, ?string $runHash = null): AiKeywordDecisionLedger
    {
        $d = (array) ($receipt['decision'] ?? []);

        return AiKeywordDecisionLedger::updateOrCreate(
            ['receipt_hash' => (string) ($receipt['receipt_hash'] ?? '')],
            [
                'run_hash' => $runHash,
                'keyword' => (string) ($receipt['keyword'] ?? ($d['keyword'] ?? '')),
                'score' => $d['score'] ?? null,
                'band' => $d['band'] ?? null,
                'family' => $d['family'] ?? null,
                'intent_tier' => $d['intent_tier'] ?? null,
                'investment_verdict' => $d['investment'] ?? null,
                'investment_basis' => $d['investment_basis'] ?? null,
                'account_risk' => $d['account_risk'] ?? 'none',
                'core_version' => (string) ($receipt['core_version'] ?? ''),
                'receipt' => $receipt,
            ],
        );
    }

    /**
     * Persiste TODOS os recibos de um run do KeywordIntelligencePipeline (recommended + high_risk), amarrados
     * ao run_hash. Retorna quantos recibos foram gravados.
     *
     * @param  array<string,mixed>  $run  KeywordIntelligencePipeline::run() output
     */
    public function recordRun(array $run): int
    {
        $runHash = (string) ($run['run_hash'] ?? '');
        $selection = (array) ($run['launch_selection'] ?? []);
        $count = 0;
        foreach (['recommended', 'high_risk'] as $bucket) {
            foreach ((array) ($selection[$bucket] ?? []) as $row) {
                $receipt = (array) ($row['receipt'] ?? []);
                if (($receipt['receipt_hash'] ?? '') === '') {
                    continue;
                }
                $this->record($receipt, $runHash);
                $count++;
            }
        }

        return $count;
    }

    /** @return Collection<int,AiKeywordDecisionLedger> */
    public function forRun(string $runHash): Collection
    {
        return AiKeywordDecisionLedger::query()->where('run_hash', $runHash)->orderByDesc('score')->get();
    }

    public function find(string $receiptHash): ?AiKeywordDecisionLedger
    {
        return AiKeywordDecisionLedger::query()->where('receipt_hash', $receiptHash)->first();
    }
}
