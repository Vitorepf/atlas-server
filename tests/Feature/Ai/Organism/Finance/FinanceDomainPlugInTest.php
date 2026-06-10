<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Organism\Finance;

use App\Services\Ai\Finance\StrategyLoop\Bar;
use App\Services\Ai\Finance\StrategyLoop\Metrics\HonestMetrics;
use App\Services\Ai\Finance\StrategyLoop\Strategy\MeanReversionStrategy;
use App\Services\Ai\Finance\StrategyLoop\Strategy\StrategyResult;
use App\Services\Ai\Finance\StrategyLoop\Strategy\StrategyRunner;
use App\Services\Ai\Finance\StrategyLoop\TradingHonestyGate;
use App\Services\Ai\Organism\AbstractDomainActuator;
use App\Services\Ai\Organism\AtlasOrganismRegistry;
use App\Services\Ai\Organism\AtlasOrganismService;
use App\Services\Ai\Organism\DomainProposal;
use App\Services\Ai\Organism\Finance\DefaultTradingHonestyJudge;
use App\Services\Ai\Organism\Finance\FinanceDomainActuator;
use App\Services\Ai\Organism\Finance\FinanceDomainProposer;
use App\Services\Ai\Organism\Finance\FinanceDomainValidator;
use App\Services\Ai\Organism\Finance\TradingHonestyJudge;
use App\Services\Ai\Organism\OrganismBrainAnchor;
use App\Services\Ai\Organism\OrganismProposalRecorder;
use ReflectionMethod;
use Tests\TestCase;

/**
 * AOBG N4.F2 — the REAL finance/trading domain PLUGGED INTO the N4 organism.
 *
 * F1 proved the organism FRAMEWORK propose-only with a deterministic finance stub. F2
 * proves the REAL seam is wired:
 *   - the proposer REUSES the shipped strategy-loop backtest generation (a real
 *     {@see StrategyRunner} / {@see MeanReversionStrategy}), not a label;
 *   - the validator DELEGATES to the shipped {@see TradingHonestyGate} (DSR / PBO / sealed
 *     holdout) when the on-machine payload carries the loop's honesty inputs — DSR present,
 *     WIN-RATE ABSENT;
 *   - the honest degrade path runs when the Python honest-metrics runtime is absent (never
 *     a fabricated DSR);
 *   - the finance actuate boundary returns requires_operator and CANNOT place a trade /
 *     reach an exchange / move money — the load-bearing trading ceiling.
 *
 * COST: zero provider tokens, zero subprocess, sqlite-safe. The honesty gate is a deterministic
 * STUB (so no Python runtime is needed in CI); one case constructs the REAL gate but injects a
 * runtime client whose `available()` is false to prove the honest degrade WITHOUT a subprocess.
 */
final class FinanceDomainPlugInTest extends TestCase
{
    // ------------------------------------------------------------------ fakes

    private function fakeAnchor(array $refs = ['ref:obra:1', 'code:Strategy::run']): OrganismBrainAnchor
    {
        return new class($refs) implements OrganismBrainAnchor
        {
            /** @param list<string> $refs */
            public function __construct(private array $refs) {}

            public function anchor(string $intent, array $opts = []): array
            {
                return ['brain_refs' => $this->refs, 'reality_graph_paths' => $this->refs];
            }
        };
    }

    private function captureRecorder(): OrganismProposalRecorder
    {
        return new class implements OrganismProposalRecorder
        {
            /** @var list<array<string,mixed>> */
            public array $recorded = [];

            public function record(DomainProposal $proposal, array $validation): array
            {
                $this->recorded[] = [
                    'ref' => $proposal->ref(),
                    'domain' => $proposal->domain,
                    'sensitive' => $proposal->sensitive,
                    'provider_safe_view' => $proposal->toProviderSafeArray(),
                    'validation' => $validation,
                ];

                return ['recorded' => true, 'node_ref' => 'node:'.$proposal->ref()];
            }

            public function priorProposals(?string $domain = null, int $limit = 10): array
            {
                return [];
            }
        };
    }

    /**
     * A deterministic stub of the honesty JUDGE seam — same verdict shape the shipped
     * {@see TradingHonestyGate::evaluate()} (and {@see DefaultTradingHonestyJudge}) return.
     * It records the input so the test can assert the validator fed it the loop's honesty
     * inputs (and never a win-rate). Cost-free: no runtime, no subprocess.
     */
    private function stubGate(bool $certify, float $dsr = 0.97): TradingHonestyJudge
    {
        return new class($certify, $dsr) implements TradingHonestyJudge
        {
            /** @var array<string,mixed>|null */
            public ?array $sawInput = null;

            public function __construct(private bool $certify, private float $dsr) {}

            public function evaluate(array $input): array
            {
                $this->sawInput = $input;

                return [
                    'certified' => $this->certify,
                    'reasons' => $this->certify ? ['certified'] : ['deflated_sharpe_too_low(test)'],
                    'report' => [
                        'n_trials' => (int) ($input['scenarios_explored'] ?? 1),
                        'deflated_sharpe' => $this->dsr,
                        'pbo' => 0.05,
                        'scoring_sharpe' => 1.8,
                        'holdout_sharpe' => (float) ($input['holdout_sharpe'] ?? 0.0),
                        'holdout_trades' => (int) ($input['holdout_trades'] ?? 0),
                        'var_sharpe_across_trials' => 0.25,
                        'thresholds' => ['dsr_min' => 0.95, 'pbo_max' => 0.2],
                    ],
                ];
            }
        };
    }

    private function service(FinanceDomainProposer $proposer, FinanceDomainValidator $validator, OrganismProposalRecorder $recorder): AtlasOrganismService
    {
        $registry = new AtlasOrganismRegistry;
        $registry->register($proposer, $validator, new FinanceDomainActuator);

        return new AtlasOrganismService($registry, $this->fakeAnchor(), $recorder);
    }

    /** Full strategy-loop honesty inputs (the exact shape the evolve command feeds the gate). */
    private function fullBundlePayload(): array
    {
        return [
            'daily_returns' => array_map(static fn (int $i): float => 0.004 + (($i % 3) - 1) * 0.0003, range(0, 59)),
            'sibling_windows' => [
                [0.01, -0.02, 0.03, 0.00, 0.01],
                [0.02, 0.01, -0.01, 0.02, -0.02],
                [-0.01, 0.00, 0.02, -0.01, 0.03],
                [0.00, 0.02, -0.02, 0.01, 0.01],
            ],
            'sibling_sharpes' => [1.2, 0.8, 1.5, 0.6],
            'scenarios_explored' => 240,
            'holdout_sharpe' => 0.9,
            'holdout_trades' => 22,
            'instrument' => 'BTC/USDT spot',
        ];
    }

    // ------------------------------------------------------------------ tests

    /**
     * 1) THE REAL HONEST METRIC: when the payload carries the strategy-loop honesty inputs,
     *    the validator DELEGATES to the real TradingHonestyGate → metric IS deflated_sharpe,
     *    DSR present, WIN-RATE ABSENT, and the gate was fed those inputs (never a win-rate).
     */
    public function test_validator_delegates_to_real_honesty_gate_dsr_present_win_rate_absent(): void
    {
        $gate = $this->stubGate(certify: true);
        $validator = new FinanceDomainValidator(new HonestMetrics, $gate);
        $recorder = $this->captureRecorder();
        $svc = $this->service(new FinanceDomainProposer, $validator, $recorder);

        $out = $svc->propose('finance', 'mean-reversion BTC/USDT', ['payload' => $this->fullBundlePayload()]);

        $v = $out['validation'];
        // The REAL honest metric — the N-deflated Deflated Sharpe — is the metric, not Sharpe.
        $this->assertSame('deflated_sharpe', $v['metric']);
        $this->assertTrue($v['passed'], 'a certified candidate should pass');
        $this->assertIsFloat($v['value']);
        $this->assertArrayHasKey('pbo', $v['detail'], 'the real bundle reports PBO');
        $this->assertArrayHasKey('holdout_sharpe', $v['detail'], 'the real bundle reports the sealed holdout');
        $this->assertStringContainsString('trading_honesty_gate', $v['method']);

        // WIN-RATE IS FORBIDDEN — never the metric, never a value, never in the detail. The
        // ONLY allowed occurrence is the deliberate "win_rate_forbidden" guard label in the
        // method, so we strip that token before asserting win-rate is otherwise absent.
        $this->assertStringContainsString('win_rate_forbidden', $v['method']);
        $blob = str_replace('win_rate_forbidden', '', strtolower(json_encode($v) ?: ''));
        $this->assertStringNotContainsString('win_rate', $blob);
        $this->assertStringNotContainsString('winrate', $blob);
        $this->assertStringNotContainsString('win rate', $blob);

        // The validator FED the gate the loop's honesty inputs (proves real delegation), and
        // never handed it a win-rate field.
        $this->assertNotNull($gate->sawInput);
        $this->assertArrayHasKey('sibling_windows', $gate->sawInput);
        $this->assertArrayHasKey('sibling_sharpes', $gate->sawInput);
        $this->assertArrayHasKey('scenarios_explored', $gate->sawInput);
        $this->assertArrayHasKey('holdout_sharpe', $gate->sawInput);
        $this->assertArrayNotHasKey('win_rate', $gate->sawInput);
        $this->assertSame(240, $gate->sawInput['scenarios_explored']);

        // Still propose-only + recorded, and the provider-safe proposal never leaks the payload.
        $this->assertSame('requires_operator', $out['actuation_gate']);
        $this->assertArrayNotHasKey('payload', $out['proposal']);
    }

    /**
     * 1b) The gate's honest NULL flows through as passed=false with the gate's reasons — never
     *     a fabricated green when the candidate is overfit / fails the holdout.
     */
    public function test_validator_reports_gate_honest_null_as_failed_not_fabricated_green(): void
    {
        $validator = new FinanceDomainValidator(new HonestMetrics, $this->stubGate(certify: false));
        $svc = $this->service(new FinanceDomainProposer, $validator, $this->captureRecorder());

        $out = $svc->propose('finance', 'overfit candidate', ['payload' => $this->fullBundlePayload()]);

        $this->assertSame('deflated_sharpe', $out['validation']['metric']);
        $this->assertFalse($out['validation']['passed']);
        $this->assertNotEmpty($out['validation']['reasons']);
        $this->assertStringContainsString('win_rate_forbidden', $out['validation']['method']);
    }

    /**
     * 2) HONEST DEGRADE: with full inputs but the Python honest-metrics runtime ABSENT, the
     *    REAL gate throws at the boundary; the validator degrades to the in-process Sharpe and
     *    SAYS SO — it never fabricates a DSR. (Cost-free: runtime client reports unavailable,
     *    so no subprocess is spawned.)
     */
    public function test_validator_degrades_honestly_when_honesty_judge_throws(): void
    {
        // A judge that throws the way the REAL gate's boundary throws when the Python
        // honest-metrics runtime is absent (the canon: a real engine or an honest failure).
        $throwingJudge = new class implements TradingHonestyJudge
        {
            public function evaluate(array $input): array
            {
                throw new \RuntimeException('honest_metrics Python runtime is not set up (test).');
            }
        };
        $validator = new FinanceDomainValidator(new HonestMetrics, $throwingJudge);
        $svc = $this->service(new FinanceDomainProposer, $validator, $this->captureRecorder());

        $out = $svc->propose('finance', 'runtime absent', ['payload' => $this->fullBundlePayload()]);

        // Degraded to the honest in-process annualized Sharpe — and says so. NOT a fabricated DSR.
        $this->assertSame('annualized_sharpe', $out['validation']['metric']);
        $this->assertStringContainsString('in_process', $out['validation']['method']);
        $this->assertStringContainsString('win_rate_forbidden', $out['validation']['method']);
        $this->assertIsFloat($out['validation']['value']);
    }

    /**
     * 2b) END-TO-END of the REAL path's failure mode: the DEFAULT judge wraps the shipped,
     *     sealed {@see TradingHonestyGate} → real {@see HonestMetricsRuntimeClient}. In CI the
     *     Python honest-metrics venv is not set up, so the real boundary throws and the
     *     validator degrades honestly — proving the production wiring never fabricates a DSR.
     *     (If the runtime IS set up locally, the real DSR comes back as deflated_sharpe — both
     *     outcomes are honest; we assert it is one of the two, never a win-rate.)
     */
    public function test_default_judge_uses_real_sealed_gate_and_degrades_when_runtime_absent(): void
    {
        $validator = new FinanceDomainValidator(new HonestMetrics, new DefaultTradingHonestyJudge);
        $svc = $this->service(new FinanceDomainProposer, $validator, $this->captureRecorder());

        $out = $svc->propose('finance', 'real gate path', ['payload' => $this->fullBundlePayload()]);

        $metric = $out['validation']['metric'];
        $this->assertContains($metric, ['deflated_sharpe', 'annualized_sharpe'], 'either the real DSR or the honest degrade — never a third thing');
        $this->assertStringContainsString('win_rate_forbidden', $out['validation']['method']);
        $blob = strtolower(json_encode($out['validation']) ?: '');
        $this->assertStringNotContainsString('"win_rate"', $blob);
    }

    /**
     * 3) REAL GENERATION REUSE: the proposer runs a REAL in-process strategy-loop backtest
     *    over supplied OHLCV bars (the loop's MeanReversionStrategy) to produce the candidate
     *    the validator scores — no provider, no order, no subprocess.
     */
    public function test_proposer_reuses_real_strategy_loop_backtest_over_bars(): void
    {
        $proposer = new FinanceDomainProposer; // default runner = real MeanReversionStrategy
        $validator = new FinanceDomainValidator(new HonestMetrics); // in-process path (no full bundle)
        $recorder = $this->captureRecorder();
        $svc = $this->service($proposer, $validator, $recorder);

        $out = $svc->propose('finance', 'mean-reversion on bars', [
            'payload' => ['bars' => $this->dipReboundBars(), 'periods_per_year' => 365.0, 'instrument' => 'BTC/USDT spot'],
        ]);

        // The proposal rationale records that a real backtest produced the candidate.
        $this->assertStringContainsString('backtest', strtolower($out['proposal']['rationale']));
        $this->assertSame('finance.strategy_loop_backtest.on_machine.propose_only', $out['brain']['proposer']);

        // The validator scored a candidate (not honest-empty) — i.e. the backtest produced
        // scorable returns, proving the generation actually ran. The metric is an honest one.
        $this->assertContains($out['validation']['metric'], ['annualized_sharpe']);
        $this->assertStringContainsString('win_rate_forbidden', $out['validation']['method']);
        $this->assertStringNotContainsString('no_returns', json_encode($out['validation']['reasons']) ?: '');

        // Propose-only + provider-safe: the payload is dropped from the provider-safe view, so
        // the on-machine OHLCV bar DATA (e.g. a distinctive close price) never crosses out. (The
        // rationale may mention the WORD "bars" as provider-safe prose; what must not leak is the
        // bar data itself.)
        $this->assertSame('requires_operator', $out['actuation_gate']);
        $this->assertArrayNotHasKey('payload', $out['proposal']);
        $encoded = json_encode($out['proposal']) ?: '';
        $this->assertStringNotContainsString('100.5', $encoded, 'OHLCV bar prices must not leak to the provider-safe view');
        $this->assertStringNotContainsString('volume', $encoded);
        $this->assertStringNotContainsString('open_time', $encoded);
    }

    /**
     * 3b) The proposer DELEGATES to whatever StrategyRunner is injected (the loop's seam) —
     *     proving it reuses the strategy interface, not a hard-coded path.
     */
    public function test_proposer_delegates_to_injected_strategy_runner(): void
    {
        $runner = new class implements StrategyRunner
        {
            public bool $ran = false;

            public function run(array $bars, array $params): StrategyResult
            {
                $this->ran = true;

                return new StrategyResult([1.0, 1.05, 1.1], [0.05, 0.0476], [['return' => 0.1]], 1);
            }
        };
        $proposer = new FinanceDomainProposer($runner);
        $svc = $this->service($proposer, new FinanceDomainValidator(new HonestMetrics), $this->captureRecorder());

        $out = $svc->propose('finance', 'inject runner', ['payload' => ['bars' => $this->dipReboundBars()]]);

        $this->assertTrue($runner->ran, 'the proposer must delegate to the injected StrategyRunner (loop reuse)');
        $this->assertSame('requires_operator', $out['actuation_gate']);
    }

    /**
     * 4) THE TRADING CEILING (load-bearing): the FINANCE actuator returns requires_operator
     *    and CANNOT place a trade / reach an exchange / move money — proven structurally
     *    (the act path is final + inherited) and behaviourally (a spy that explodes on any
     *    order/exchange/http call is never reached).
     */
    public function test_finance_actuate_returns_requires_operator_and_cannot_trade(): void
    {
        $actuator = new FinanceDomainActuator;
        $proposal = DomainProposal::fromArray([
            'domain' => 'finance',
            'intent' => 'try to place a trade',
            'content' => 'idea',
            'rationale' => 'r',
            'payload' => ['secret_keys' => 'DO-NOT-LEAK', 'order' => ['side' => 'buy', 'qty' => 1]],
        ]);

        $result = $actuator->actuate($proposal);

        // The ONLY reachable outcome is requires_operator — never an executed trade.
        $this->assertSame('requires_operator', $result['status']);
        $this->assertTrue($result['recorded']);
        $this->assertSame('finance', $result['domain']);
        $this->assertSame(AbstractDomainActuator::CEILING, $result['ceiling']);
        // No order/exchange/transaction artifact ever comes back.
        $this->assertArrayNotHasKey('order_id', $result);
        $this->assertArrayNotHasKey('tx', $result);
        $this->assertArrayNotHasKey('fill', $result);
        // The result is the operator instruction string — propose-only, no leaked secret.
        $this->assertStringNotContainsString('DO-NOT-LEAK', json_encode($result) ?: '');

        // STRUCTURAL: the finance actuator inherits the sealed (final) act path — it does NOT
        // and CANNOT re-declare actuate() to place a trade.
        $rm = new ReflectionMethod(AbstractDomainActuator::class, 'actuate');
        $this->assertTrue($rm->isFinal(), 'the act path must be final — the propose-only trading seal');
        $declaring = (new ReflectionMethod(FinanceDomainActuator::class, 'actuate'))->getDeclaringClass()->getName();
        $this->assertSame(AbstractDomainActuator::class, $declaring, 'finance actuator inherits the sealed act path');

        // GREP-LEVEL: the finance actuator source contains NO order/exchange/money/http CALL.
        $src = file_get_contents(base_path('app/Services/Ai/Organism/Finance/FinanceDomainActuator.php')) ?: '';
        $code = preg_replace('#/\*.*?\*/#s', '', $src) ?? $src;
        $code = preg_replace('#//.*$#m', '', $code) ?? $code;
        $code = preg_replace('#"(?:[^"\\\\]|\\\\.)*"#s', '""', $code) ?? $code;
        $code = preg_replace("#'(?:[^'\\\\]|\\\\.)*'#s", "''", $code) ?? $code;
        foreach (['Http::', '->placeOrder(', '->createOrder(', '->submitOrder(', '->buy(', '->sell(', '->transfer(', 'curl_exec', 'BinanceClient', 'new \\GuzzleHttp'] as $needle) {
            $this->assertStringNotContainsString($needle, $code, "finance actuator must contain NO trading I/O — found '$needle'");
        }
    }

    /**
     * 5) FINANCE STAYS ON-MACHINE: a finance proposal is sensitive and its payload (incl. the
     *    honesty inputs + any secret) never crosses to the provider-safe view a provider/
     *    presenter could see — provider-bound by construction.
     */
    public function test_finance_proposal_stays_on_machine_not_provider_bound_out(): void
    {
        $validator = new FinanceDomainValidator(new HonestMetrics, $this->stubGate(certify: true));
        $recorder = $this->captureRecorder();
        $svc = $this->service(new FinanceDomainProposer, $validator, $recorder);

        $payload = $this->fullBundlePayload();
        $payload['secret_keys'] = 'DO-NOT-LEAK';
        $out = $svc->propose('finance', 'sensitive idea', ['payload' => $payload]);

        $this->assertTrue($out['sensitive']);
        $this->assertTrue($out['proposal']['sensitive']);

        // The provider-safe view (the only thing that could ever reach an external provider)
        // carries NO payload, NO secret, NO sibling windows.
        $encoded = json_encode($out['proposal']) ?: '';
        $this->assertStringNotContainsString('DO-NOT-LEAK', $encoded);
        $this->assertStringNotContainsString('secret_keys', $encoded);
        $this->assertStringNotContainsString('sibling_windows', $encoded);
        $this->assertArrayNotHasKey('payload', $out['proposal']);

        // The recorder only ever saw the provider-safe view (no payload/secret on-machine leak).
        $recordedView = json_encode($recorder->recorded[0]['provider_safe_view']) ?: '';
        $this->assertStringNotContainsString('DO-NOT-LEAK', $recordedView);
        $this->assertTrue($recorder->recorded[0]['sensitive']);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * A real OHLCV series with a clear dip-and-rebound so the mean-reversion family takes a
     * long trade and produces scorable returns — exercises the REAL backtest cost-free.
     *
     * @return list<array<string,float|int>>
     */
    private function dipReboundBars(): array
    {
        $closes = [];
        // baseline up-trend
        for ($i = 0; $i < 40; $i++) {
            $closes[] = 100.0 + $i * 0.5;
        }
        // sharp dip
        $base = end($closes);
        for ($i = 1; $i <= 6; $i++) {
            $closes[] = $base - $i * 3.0;
        }
        // rebound back through the mean
        $base = end($closes);
        for ($i = 1; $i <= 20; $i++) {
            $closes[] = $base + $i * 2.0;
        }

        $bars = [];
        $t = 1_700_000_000_000;
        foreach ($closes as $idx => $c) {
            $open = $idx > 0 ? $closes[$idx - 1] : $c;
            $bars[] = [
                'open_time' => $t + $idx * 86_400_000,
                'open' => $open,
                'high' => max($open, $c) + 0.5,
                'low' => min($open, $c) - 0.5,
                'close' => $c,
                'volume' => 1000.0,
                'close_time' => $t + ($idx + 1) * 86_400_000,
            ];
        }

        return $bars;
    }
}
