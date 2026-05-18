<?php

namespace App\Console\Commands;

use App\Services\Ai\Finance\Kernel\FinanceControlPlaneProjection;
use App\Services\Ai\Finance\Kernel\FinanceDomainException;
use App\Services\Ai\Finance\Kernel\FinanceDomainReadinessService;
use App\Services\Ai\Finance\Kernel\FinanceDomainSmokeService;
use Illuminate\Console\Command;
use Throwable;

class AtlasAiFinanceDomainCommand extends Command
{
    protected $signature = 'atlas:ai:finance-domain
        {positional? : Optional positional action (alternative to --action)}
        {--action=readiness : readiness, smoke, control-plane}
        {--prompt= : Optional prompt for smoke}
        {--asset=AAPL : Asset to use for smoke (default AAPL)}
        {--json : Machine-readable JSON output}';

    protected $description = 'Atlas Finance / Investment Company Runtime: research/valuation/portfolio/risk/compliance/reporting/paper-trading. Live trading is hard-blocked.';

    public function handle(
        FinanceDomainReadinessService $readiness,
        FinanceDomainSmokeService $smoke,
        FinanceControlPlaneProjection $controlPlane,
    ): int {
        $positional = $this->argument('positional');
        $action = is_string($positional) && trim($positional) !== ''
            ? trim($positional)
            : (string) $this->option('action');

        try {
            return match ($action) {
                'readiness' => $this->renderReadiness($readiness),
                'smoke' => $this->renderSmoke($smoke),
                'control-plane' => $this->renderControlPlane($controlPlane),
                default => $this->invalidAction($action),
            };
        } catch (FinanceDomainException $e) {
            return $this->renderError('finance_domain_exception', $e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->renderError('invalid_argument', $e->getMessage());
        } catch (Throwable $e) {
            return $this->renderError('exception', $e->getMessage(), $e::class);
        }
    }

    private function renderReadiness(FinanceDomainReadinessService $readiness): int
    {
        $payload = $readiness->report();
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('ok', $payload['ok'] ? 'true' : 'false');
            $this->components->twoColumnDetail('passed', (string) $payload['summary']['passed']);
            $this->components->twoColumnDetail('failed', (string) $payload['summary']['failed']);
            $this->components->twoColumnDetail('live_trading_blocked_default', $payload['invariants']['live_trading_blocked_default'] ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderSmoke(FinanceDomainSmokeService $smoke): int
    {
        $payload = $smoke->run(
            $this->stringOption('prompt'),
            (string) ($this->option('asset') ?? 'AAPL'),
        );
        $payload['schema'] = 'atlas.ai.finance.smoke.v1';
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('manifest', (string) $payload['manifest']['domain_id']);
            $this->components->twoColumnDetail('mission_status', (string) $payload['mission']['status']);
            $this->components->twoColumnDetail('compliance_decision', (string) $payload['sections']['compliance_decision']);
            $this->components->twoColumnDetail('paper_trade_pnl', (string) $payload['sections']['paper_trade_pnl_cash_only']);
            $this->components->twoColumnDetail('certification_status', (string) $payload['certification']['status']);
            $this->components->twoColumnDetail('live_trading_blocked_default', $payload['invariants']['live_trading_blocked_default'] ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderControlPlane(FinanceControlPlaneProjection $controlPlane): int
    {
        $payload = $controlPlane->snapshot();
        $payload['ok'] = true;
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            foreach ($payload['finance'] as $k => $v) {
                $this->components->twoColumnDetail($k, is_array($v) ? json_encode($v) : (string) $v);
            }
            foreach ($payload['invariants'] as $k => $v) {
                $this->components->twoColumnDetail('invariant:'.$k, is_array($v) ? json_encode($v) : (string) (is_bool($v) ? ($v ? 'true' : 'false') : $v));
            }
        });

        return self::SUCCESS;
    }

    private function renderError(string $error, string $message, ?string $type = null): int
    {
        $payload = ['ok' => false, 'error' => $error, 'message' => $message];
        if ($type !== null) {
            $payload['type'] = $type;
        }
        $this->line($this->encode($payload));

        return self::FAILURE;
    }

    private function invalidAction(string $action): int
    {
        return $this->renderError('invalid_arguments', "invalid action [{$action}] for atlas:ai:finance-domain");
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function json(): bool
    {
        return (bool) $this->option('json');
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, callable $human): void
    {
        if ($this->json()) {
            $this->line($this->encode($payload));

            return;
        }
        $human();
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
