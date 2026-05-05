<?php

namespace Tests\Unit\Ai\Finance;

use App\Services\Ai\Finance\AtlasFinanceSafetyPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AtlasFinanceSafetyPolicyTest extends TestCase
{
    /**
     * @return array<string,array{0:string,1:array<int,string>}>
     */
    public static function blockedActionExamples(): array
    {
        return [
            'canonical place order' => ['place_order', ['place_order']],
            'english buy request' => ['buy 100 shares of AAPL', ['place_order']],
            'portuguese sell request' => ['vender 10 cotas de BOVA11', ['place_order']],
            'rebalance request' => ['rebalancear minha carteira', ['rebalance_account']],
            'broker connection request' => ['conectar api da corretora para executar', ['connect_broker_for_execution']],
            'cash transfer request' => ['transferir dinheiro para a conta', ['transfer_cash']],
        ];
    }

    /**
     * @param  array<int,string>  $expected
     */
    #[DataProvider('blockedActionExamples')]
    public function test_it_detects_forbidden_market_execution_intent(string $request, array $expected): void
    {
        $this->assertSame($expected, app(AtlasFinanceSafetyPolicy::class)->blockedActions($request));
    }

    public function test_it_allows_analysis_only_language(): void
    {
        $this->assertSame([], app(AtlasFinanceSafetyPolicy::class)->blockedActions('prepare a trade thesis review with risks and countercase'));
    }
}
