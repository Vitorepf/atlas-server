<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Finance\SpotExec\BinanceLiveMarket;
use App\Services\Ai\Finance\SpotExec\PaperTradeRuntime;
use App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyScenarioRegistry;
use App\Services\Ai\Finance\StrategyLoop\Strategy\MeanReversionStrategy;
use App\Services\Ai\Finance\StrategyLoop\Strategy\MomentumStrategy;
use App\Services\Ai\Finance\StrategyLoop\Strategy\RegimeAdaptiveStrategy;
use App\Services\Ai\Finance\StrategyLoop\Strategy\StrategyRunner;
use App\Services\Ai\Finance\StrategyLoop\Strategy\TrendBreakoutStrategy;
use App\Services\Ai\Finance\StrategyLoop\Strategy\TrendPullbackStrategy;
use App\Services\Ai\Finance\StrategyLoop\Strategy\VolumeBreakoutStrategy;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * PAPER TRADING ao vivo (Binance spot, BTC/ETH) — a ponte pesquisa → dinheiro.
 * ZERO ordens reais: fills simulados localmente com custos reais, em barra
 * fechada, idempotente (cron-friendly: cada invocação processa no máximo um
 * bar novo por config). O live executor (Fase B) é outro comando, outro gate.
 */
final class AtlasFinancePaperTradeCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:finance:paper-trade
        {--symbol=BTCUSDT : BTCUSDT|ETHUSDT|...}
        {--interval=4h : 4h|1d}
        {--family=trend-breakout-v1 : família de estratégia do loop}
        {--params= : JSON dos params da estratégia (obrigatório sem --elite)}
        {--elite : usa o melhor candidato já encontrado pelo loop para este cenário (research-only, NÃO certificado)}
        {--id= : id da run paper (default: derivado de symbol/interval/family)}
        {--status : só mostra o estado atual, sem tick}
        {--watch=0 : roda contínuo com este intervalo em segundos (0 = um tick e sai)}
        {--json : saída JSON}';

    protected $description = 'Paper-trading ao vivo na Binance spot: valida o cano de execução de ponta a ponta sem dinheiro.';

    public function handle(): int
    {
        $symbol = strtoupper((string) $this->option('symbol'));
        $interval = (string) $this->option('interval');
        $family = (string) $this->option('family');
        $id = trim((string) $this->option('id')) !== ''
            ? (string) $this->option('id')
            : strtolower($symbol.'-'.$interval.'-'.$family);

        $runtime = new PaperTradeRuntime(new BinanceLiveMarket, storage_path('atlas/finance/paper'));

        if ((bool) $this->option('status')) {
            $state = $runtime->readState($id);

            return $this->emit($state ?? ['status' => 'no_state_yet', 'id' => $id], self::SUCCESS);
        }

        $params = $this->resolveParams($symbol, $interval, $family);
        if ($params === null) {
            return self::FAILURE;
        }
        $engine = $this->engineFor($family);
        if ($engine === null) {
            return self::FAILURE;
        }

        $config = ['id' => $id, 'symbol' => $symbol, 'interval' => $interval, 'family' => $family, 'params' => $params];
        $watch = max(0, (int) $this->option('watch'));

        do {
            $res = $runtime->tick($config, $engine);
            $this->emit(['id' => $id] + $res, self::SUCCESS);
            if ($watch > 0) {
                sleep($watch);
            }
        } while ($watch > 0 && ! $this->killSwitchEngaged());

        return self::SUCCESS;
    }

    /** @return array<string,mixed>|null */
    private function resolveParams(string $symbol, string $interval, string $family): ?array
    {
        $raw = trim((string) $this->option('params'));
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                $this->error('--params não é JSON válido');

                return null;
            }

            return $decoded;
        }
        if ((bool) $this->option('elite')) {
            $seeds = StrategyScenarioRegistry::default(false)->eliteSeeds($symbol, $interval, $family);
            $best = is_array($seeds[0] ?? null) ? $seeds[0] : null;
            if ($best === null) {
                $this->error("nenhum elite seed para {$symbol}-{$interval}-{$family} — o loop ainda não encontrou candidato neste cenário; passe --params");

                return null;
            }
            $this->warn('usando elite seed do loop: candidato RESEARCH-ONLY, não certificado — paper serve exatamente para validá-lo forward.');

            return $best;
        }
        $this->error('passe --params (JSON) ou --elite');

        return null;
    }

    private function engineFor(string $family): ?StrategyRunner
    {
        // funding-extreme fica de fora do paper por honestidade: a fita congelada
        // termina antes das barras ao vivo — sinal ficaria stale sem avisar.
        if ($family === 'funding-extreme-v1') {
            $this->error('funding-extreme-v1 ainda não é suportada em paper (fita congelada termina antes do agora; precisa de feed de funding ao vivo)');

            return null;
        }

        return match ($family) {
            'trend-breakout-v1' => new TrendBreakoutStrategy,
            'mean-reversion-v1' => new MeanReversionStrategy,
            'momentum-v1' => new MomentumStrategy,
            'volume-breakout-v1' => new VolumeBreakoutStrategy,
            'pullback-trend-v1' => new TrendPullbackStrategy,
            'regime-adaptive-v1' => new RegimeAdaptiveStrategy,
            default => null,
        } ?? $this->unknownFamily($family);
    }

    private function unknownFamily(string $family): ?StrategyRunner
    {
        $this->error('família desconhecida: '.$family);

        return null;
    }

    private function killSwitchEngaged(): bool
    {
        return is_file(storage_path('atlas/finance/paper/STOP'));
    }

    /** @param array<string,mixed> $payload */
    private function emit(array $payload, int $exit): int
    {
        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return $exit;
        }
        foreach ($payload as $k => $v) {
            $this->components->twoColumnDetail((string) $k, is_scalar($v) || $v === null ? var_export($v, true) : (string) json_encode($v));
        }

        return $exit;
    }
}
