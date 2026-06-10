<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\SpotExec;

/**
 * O GATE da ordem real — fail-closed em TODAS as camadas. Uma ordem só passa se
 * TODAS as condições valem simultaneamente; qualquer dúvida = recusa com razão.
 *
 *   1. live_enabled (config/env ATLAS_SPOT_EXEC_LIVE_ENABLED=true)
 *   2. ARMED (env ATLAS_SPOT_EXEC_ARMED literalmente "true")
 *   3. confirm explícito do comando (--confirm) — passado pelo chamador
 *   4. kill-switch AUSENTE (storage/atlas/finance/spot-exec/STOP)
 *   5. símbolo na allowlist (BTCUSDT/ETHUSDT — o foco do operador)
 *   6. valor <= max_order_usd
 *   7. gasto do dia + valor <= daily_cap_usd (ledger em disco, por dia UTC)
 *
 * O ledger diário é escrito APÓS a ordem aceita (recordSpend) — contagem
 * conservadora: falha entre ordem e registro conta contra o cap na releitura
 * via reconcile externo, nunca a favor.
 */
final class SpotExecGate
{
    /** @param array<string,mixed> $cfg bloco config('atlas.finance_spot_exec') */
    public function __construct(private readonly array $cfg) {}

    public static function fromConfig(): self
    {
        return new self((array) config('atlas.finance_spot_exec', []));
    }

    /**
     * @return array{allowed:bool,reasons:list<string>}
     */
    public function checkOrder(string $symbol, float $quoteUsd, bool $confirmed): array
    {
        $reasons = [];

        if (! (bool) ($this->cfg['live_enabled'] ?? false)) {
            $reasons[] = 'live_disabled (ATLAS_SPOT_EXEC_LIVE_ENABLED != true)';
        }
        $armedEnv = (string) ($this->cfg['armed_env'] ?? 'ATLAS_SPOT_EXEC_ARMED');
        if (strtolower(trim((string) env($armedEnv, ''))) !== 'true') {
            $reasons[] = 'not_armed ('.$armedEnv.' != true)';
        }
        if (! $confirmed) {
            $reasons[] = 'not_confirmed (--confirm ausente)';
        }
        if ($this->killSwitchEngaged()) {
            $reasons[] = 'kill_switch_engaged ('.$this->killSwitchPath().')';
        }
        $allowed = array_map('strtoupper', (array) ($this->cfg['allowed_symbols'] ?? []));
        if (! in_array(strtoupper($symbol), $allowed, true)) {
            $reasons[] = 'symbol_not_allowed ('.$symbol.' fora de '.implode(',', $allowed).')';
        }
        $maxOrder = (float) ($this->cfg['max_order_usd'] ?? 0.0);
        if ($quoteUsd <= 0.0 || $quoteUsd > $maxOrder) {
            $reasons[] = 'order_size_violation ('.$quoteUsd.' USD; max '.$maxOrder.')';
        }
        $dailyCap = (float) ($this->cfg['daily_cap_usd'] ?? 0.0);
        $spent = $this->spentTodayUsd();
        if ($spent + $quoteUsd > $dailyCap) {
            $reasons[] = 'daily_cap_violation (gasto hoje '.$spent.' + '.$quoteUsd.' > '.$dailyCap.')';
        }

        return ['allowed' => $reasons === [], 'reasons' => $reasons];
    }

    public function killSwitchEngaged(): bool
    {
        $path = $this->killSwitchPath();

        return $path !== '' && is_file($path);
    }

    public function killSwitchPath(): string
    {
        return (string) ($this->cfg['kill_switch_path'] ?? '');
    }

    public function spentTodayUsd(): float
    {
        $file = $this->dailyLedgerPath();
        if (! is_file($file)) {
            return 0.0;
        }
        $decoded = json_decode((string) file_get_contents($file), true);

        return is_array($decoded) ? (float) ($decoded['spent_usd'] ?? 0.0) : 0.0;
    }

    /** Registra gasto aceito (compra E venda contam — turnover total do dia). */
    public function recordSpend(float $quoteUsd): void
    {
        $dir = rtrim((string) ($this->cfg['ledger_dir'] ?? ''), '/');
        if ($dir === '') {
            return;
        }
        if (! is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }
        $file = $this->dailyLedgerPath();
        $current = $this->spentTodayUsd();
        file_put_contents($file, json_encode([
            'date_utc' => gmdate('Y-m-d'),
            'spent_usd' => $current + max(0.0, $quoteUsd),
            'updated_at' => gmdate('c'),
        ], JSON_PRETTY_PRINT));
    }

    private function dailyLedgerPath(): string
    {
        $dir = rtrim((string) ($this->cfg['ledger_dir'] ?? ''), '/');

        return $dir.'/daily-'.gmdate('Y-m-d').'.json';
    }
}
