<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Finance\SpotExec;

use App\Services\Ai\Finance\SpotExec\SpotExecGate;
use PHPUnit\Framework\TestCase;

/**
 * Pina o contrato fail-closed do gate de ordens reais: por DEFAULT tudo recusa;
 * cada camada (live/armed/confirm/kill-switch/allowlist/caps) bloqueia sozinha;
 * o caminho feliz só abre com TODAS as camadas satisfeitas; e o cap diário
 * acumula via ledger em disco.
 */
final class SpotExecGateTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/spot-gate-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0o755, true);
        putenv('SPOT_GATE_TEST_ARMED=true');
    }

    protected function tearDown(): void
    {
        putenv('SPOT_GATE_TEST_ARMED');
        foreach ((array) glob($this->dir.'/*') as $f) {
            @unlink((string) $f);
        }
        @rmdir($this->dir);
    }

    public function test_default_config_refuses_everything(): void
    {
        $gate = new SpotExecGate([]);

        $res = $gate->checkOrder('BTCUSDT', 5.0, confirmed: true);

        $this->assertFalse($res['allowed']);
        $this->assertNotEmpty($res['reasons']);
    }

    public function test_full_chain_allows_and_each_layer_blocks_alone(): void
    {
        $cfg = $this->armedConfig();

        $ok = (new SpotExecGate($cfg))->checkOrder('BTCUSDT', 5.0, confirmed: true);
        $this->assertTrue($ok['allowed'], implode(' | ', $ok['reasons']));

        $noLive = (new SpotExecGate(['live_enabled' => false] + $cfg))->checkOrder('BTCUSDT', 5.0, true);
        $this->assertFalse($noLive['allowed']);

        $noConfirm = (new SpotExecGate($cfg))->checkOrder('BTCUSDT', 5.0, confirmed: false);
        $this->assertFalse($noConfirm['allowed']);

        $badSymbol = (new SpotExecGate($cfg))->checkOrder('DOGEUSDT', 5.0, true);
        $this->assertFalse($badSymbol['allowed']);

        $tooBig = (new SpotExecGate($cfg))->checkOrder('BTCUSDT', 11.0, true);
        $this->assertFalse($tooBig['allowed']);

        touch($this->dir.'/STOP');
        $killed = (new SpotExecGate($cfg))->checkOrder('BTCUSDT', 5.0, true);
        $this->assertFalse($killed['allowed']);
        unlink($this->dir.'/STOP');
    }

    public function test_not_armed_blocks_even_with_everything_else(): void
    {
        $cfg = $this->armedConfig();
        $cfg['armed_env'] = 'SPOT_GATE_TEST_ARMED_MISSING'; // env inexistente

        $res = (new SpotExecGate($cfg))->checkOrder('BTCUSDT', 5.0, confirmed: true);

        $this->assertFalse($res['allowed']);
        $this->assertStringContainsString('not_armed', implode('|', $res['reasons']));
    }

    public function test_daily_cap_accumulates_via_disk_ledger(): void
    {
        $gate = new SpotExecGate($this->armedConfig());

        $this->assertTrue($gate->checkOrder('BTCUSDT', 8.0, true)['allowed']);
        $gate->recordSpend(8.0);
        $this->assertSame(8.0, $gate->spentTodayUsd());

        $second = $gate->checkOrder('BTCUSDT', 8.0, true);
        $this->assertTrue($second['allowed'], '8+8=16 <= cap 20');
        $gate->recordSpend(8.0);

        $third = $gate->checkOrder('BTCUSDT', 8.0, true);
        $this->assertFalse($third['allowed'], '16+8=24 > cap 20 tem que recusar');
        $this->assertStringContainsString('daily_cap_violation', implode('|', $third['reasons']));
    }

    /** @return array<string,mixed> */
    private function armedConfig(): array
    {
        return [
            'live_enabled' => true,
            'armed_env' => 'SPOT_GATE_TEST_ARMED',
            'allowed_symbols' => ['BTCUSDT', 'ETHUSDT'],
            'max_order_usd' => 10.0,
            'daily_cap_usd' => 20.0,
            'kill_switch_path' => $this->dir.'/STOP',
            'ledger_dir' => $this->dir,
        ];
    }
}
