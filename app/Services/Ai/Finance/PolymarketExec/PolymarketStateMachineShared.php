<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\PolymarketExec;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Miolo comum das state machines de execucao Polymarket (Basket + MintSell): eventos,
 * status, halts, contadores diarios, gate-blocks e finalizacao. Era um clone de 65 linhas
 * identicas nos dois arquivos (medicao jscpd 05/07) — extraido na limpeza para que correcao
 * em caminho de dinheiro aconteca UMA vez.
 */
trait PolymarketStateMachineShared
{

    private function bumpDaily(string $mode, int $attempted = 0, int $filled = 0, int $aborted = 0, float $deployed = 0.0, float $realizedPnl = 0.0): void
    {
        $date = Carbon::now()->toDateString();
        $row = DB::table('atlas_poly_exec_daily')->where('trade_date', $date)->where('mode', $mode)->first();
        if ($row === null) {
            DB::table('atlas_poly_exec_daily')->insert([
                'trade_date' => $date,
                'mode' => $mode,
                'deployed_usd' => round($deployed, 4),
                'realized_pnl_usd' => round($realizedPnl, 4),
                'baskets_attempted' => $attempted,
                'baskets_filled' => $filled,
                'baskets_aborted' => $aborted,
                'halted' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        DB::table('atlas_poly_exec_daily')->where('id', $row->id)->update([
            'deployed_usd' => round((float) $row->deployed_usd + $deployed, 4),
            'realized_pnl_usd' => round((float) $row->realized_pnl_usd + $realizedPnl, 4),
            'baskets_attempted' => (int) $row->baskets_attempted + $attempted,
            'baskets_filled' => (int) $row->baskets_filled + $filled,
            'baskets_aborted' => (int) $row->baskets_aborted + $aborted,
            'updated_at' => now(),
        ]);
    }


    /** DB::raw-safe decimal literal. */
    private function dec(float $v): string
    {
        return number_format($v, 6, '.', '');
    }


    /**
     * @param  array<string, mixed>  $detail
     */
    private function event(string $basketId, string $kind, array $detail): void
    {
        $seq = (int) DB::table('atlas_poly_exec_events')->where('basket_id', $basketId)->max('seq') + 1;
        DB::table('atlas_poly_exec_events')->insert([
            'basket_id' => $basketId,
            'seq' => $seq,
            'kind' => $kind,
            'detail' => json_encode($detail),
            'created_at' => now(),
        ]);
    }


    private function finalize(string $basketId, string $status): void
    {
        DB::table('atlas_poly_exec_baskets')->where('basket_id', $basketId)
            ->update(['status' => $status, 'finalized_at' => now(), 'updated_at' => now()]);
    }


    private function maybeHalt(string $mode): void
    {
        if ($this->gate->deployedToday($mode) >= $this->cfg->dailyCapUsd) {
            DB::table('atlas_poly_exec_daily')
                ->where('trade_date', Carbon::now()->toDateString())->where('mode', $mode)
                ->update(['halted' => true, 'updated_at' => now()]);
        }
    }


    private function recordGateBlock(string $basketId, string $layer, GateDecision $decision): void
    {
        $this->event($basketId, 'gate_block', [
            'layer' => $layer,
            'failed' => $decision->failedNames(),
            'checks' => $decision->checks,
        ]);
    }


    private function setStatus(string $basketId, string $status): void
    {
        DB::table('atlas_poly_exec_baskets')->where('basket_id', $basketId)
            ->update(['status' => $status, 'updated_at' => now()]);
        $this->event($basketId, 'state_change', ['to' => $status]);
    }
}
