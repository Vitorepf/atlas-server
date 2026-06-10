<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Finance\SpotExec;

use App\Services\Ai\Finance\SpotExec\MarketSource;
use App\Services\Ai\Finance\SpotExec\PaperTradeRuntime;
use App\Services\Ai\Finance\StrategyLoop\Bar;
use App\Services\Ai\Finance\StrategyLoop\Strategy\StrategyResult;
use App\Services\Ai\Finance\StrategyLoop\Strategy\StrategyRunner;
use PHPUnit\Framework\TestCase;

/**
 * Pina o contrato do paper runtime: compra quando o engine sinaliza enter, vende
 * com PnL quando sinaliza exit, é IDEMPOTENTE por barra (rodar 2x = no-op),
 * sobrevive a restart (estado em disco) e reconcilia posição perdida.
 */
final class PaperTradeRuntimeTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/paper-test-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            foreach ((array) glob($this->dir.'/*/*') as $f) {
                @unlink((string) $f);
            }
            foreach ((array) glob($this->dir.'/*') as $d) {
                @rmdir((string) $d);
            }
            @rmdir($this->dir);
        }
    }

    public function test_enter_signal_buys_and_same_bar_is_idempotent(): void
    {
        $market = new FakeMarket($this->bars(60), mid: 100.0);
        $runtime = new PaperTradeRuntime($market, $this->dir);
        $engine = new ScriptedEngine(pendingAction: 'enter');

        $first = $runtime->tick($this->config(), $engine);
        $second = $runtime->tick($this->config(), $engine);

        $this->assertSame('ticked', $first['status']);
        $this->assertSame('buy', $first['action']);
        $this->assertTrue($first['paper_long']);
        // fee 10bps + slip 5bps cobrados: equity logo após a compra < 1.0
        $this->assertLessThan(1.0, $first['equity']);
        $this->assertGreaterThan(0.99, $first['equity']);
        $this->assertSame('no_new_bar', $second['status'], 'mesmo bar processado 2x tem que ser no-op');
    }

    public function test_exit_signal_sells_and_records_pnl(): void
    {
        $market = new FakeMarket($this->bars(60), mid: 100.0);
        $runtime = new PaperTradeRuntime($market, $this->dir);

        $runtime->tick($this->config(), new ScriptedEngine(pendingAction: 'enter'));

        // novo bar fecha + preço subiu 10%: engine manda sair.
        $market->bars = $this->bars(61);
        $market->mid = 110.0;
        $res = $runtime->tick($this->config(), new ScriptedEngine(pendingAction: 'exit'));

        $this->assertSame('sell', $res['action']);
        $this->assertFalse($res['paper_long']);
        $this->assertGreaterThan(1.05, $res['equity'], 'a alta de 10% menos custos tem que aparecer na banca paper');

        $fills = file($this->dir.'/btc-test/fills.jsonl', FILE_IGNORE_NEW_LINES) ?: [];
        $this->assertCount(2, $fills);
        $sell = json_decode($fills[1], true);
        $this->assertSame('sell', $sell['side']);
        $this->assertGreaterThan(0.08, (float) $sell['return']);
    }

    public function test_restart_from_disk_keeps_position_and_reconciles(): void
    {
        $market = new FakeMarket($this->bars(60), mid: 100.0);
        (new PaperTradeRuntime($market, $this->dir))->tick($this->config(), new ScriptedEngine(pendingAction: 'enter'));

        // processo novo (instância nova), bar novo, engine sem sinal novo mas com
        // posição aberta — paper já está long: tem que SEGURAR, não recomprar.
        $market->bars = $this->bars(61);
        $fresh = new PaperTradeRuntime($market, $this->dir);
        $res = $fresh->tick($this->config(), new ScriptedEngine(openPosition: ['entry_idx' => 50, 'entry_price' => 100.0]));

        $this->assertSame('hold', $res['action']);
        $this->assertTrue($res['paper_long']);

        // engine quer flat (sem posição, sem pending) → reconcilia vendendo.
        $market->bars = $this->bars(62);
        $res2 = $fresh->tick($this->config(), new ScriptedEngine);
        $this->assertSame('sell', $res2['action']);
        $this->assertFalse($res2['paper_long']);
    }

    public function test_insufficient_bars_is_fail_closed(): void
    {
        $runtime = new PaperTradeRuntime(new FakeMarket($this->bars(10), mid: 100.0), $this->dir);

        $res = $runtime->tick($this->config(), new ScriptedEngine(pendingAction: 'enter'));

        $this->assertSame('insufficient_bars', $res['status']);
    }

    /** @return array{id:string,symbol:string,interval:string,family:string,params:array<string,mixed>} */
    private function config(): array
    {
        return [
            'id' => 'btc-test',
            'symbol' => 'BTCUSDT',
            'interval' => '4h',
            'family' => 'scripted',
            'params' => ['fee_bps' => 10.0, 'slippage_bps' => 5.0],
        ];
    }

    /** @return list<Bar> */
    private function bars(int $n): array
    {
        $bars = [];
        $h4 = 14_400_000;
        for ($i = 0; $i < $n; $i++) {
            $bars[] = new Bar($i * $h4, 100.0, 100.5, 99.5, 100.0, 1000.0, ($i + 1) * $h4 - 1);
        }

        return $bars;
    }
}

final class FakeMarket implements MarketSource
{
    /** @param list<Bar> $bars */
    public function __construct(public array $bars, public float $mid) {}

    public function closedBars(string $symbol, string $interval, int $limit): array
    {
        return $this->bars;
    }

    public function mid(string $symbol): ?float
    {
        return $this->mid;
    }
}

final class ScriptedEngine implements StrategyRunner
{
    /** @param array{entry_idx:int,entry_price:float}|null $openPosition */
    public function __construct(
        private readonly ?string $pendingAction = null,
        private readonly ?array $openPosition = null,
    ) {}

    public function run(array $bars, array $params): StrategyResult
    {
        return new StrategyResult([1.0], [], [], 0, $this->openPosition, $this->pendingAction);
    }
}
