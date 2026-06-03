<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\StrategyLoop\Campaign;

use App\Services\Ai\Finance\StrategyLoop\Bar;
use Symfony\Component\Process\Process;

/**
 * External process/language second engine for implemented strategy families.
 *
 * This is intentionally used only in champion quarantine. It gives an
 * independent Python replay without adding broker, order, or money paths.
 */
final class ExternalPythonTrendBreakoutReplay
{
    /**
     * @param  list<Bar>  $bars
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>
     */
    public function evaluate(array $bars, array $params, float $periodsPerYear, string $family = 'trend-breakout-v1', string $python = 'python3', ?string $scriptPath = null): array
    {
        $scriptPath ??= $this->defaultScriptPath();
        if (! is_file($scriptPath)) {
            return $this->unavailable('python_replay_script_missing');
        }

        $payload = json_encode([
            'bars' => array_map(static fn (Bar $bar): array => [
                'open_time' => $bar->openTime,
                'open' => $bar->open,
                'high' => $bar->high,
                'low' => $bar->low,
                'close' => $bar->close,
                'volume' => $bar->volume,
                'close_time' => $bar->closeTime,
            ], $bars),
            'params' => $params,
            'periods_per_year' => $periodsPerYear,
            'family' => $family,
        ], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if (! is_string($payload)) {
            return $this->unavailable('python_replay_payload_invalid');
        }

        $process = new Process([$python, $scriptPath], $this->repoRoot(), null, $payload, 20.0);
        $process->run();
        if (! $process->isSuccessful()) {
            return [
                ...$this->unavailable('python_replay_process_failed'),
                'exit_code' => $process->getExitCode(),
                'stderr' => trim($process->getErrorOutput()),
            ];
        }

        $decoded = json_decode(trim($process->getOutput()), true);
        if (! is_array($decoded)) {
            return $this->unavailable('python_replay_invalid_json');
        }

        foreach (['trade_count', 'ann_sharpe', 'max_dd'] as $metric) {
            if (! is_numeric($decoded[$metric] ?? null)) {
                return $this->unavailable('python_replay_metrics_incomplete');
            }
        }

        return [
            'engine' => 'atlas_python_replay',
            'status' => 'ready',
            'trade_count' => (int) $decoded['trade_count'],
            'ann_sharpe' => (float) $decoded['ann_sharpe'],
            'max_dd' => (float) $decoded['max_dd'],
            'total_return' => is_numeric($decoded['total_return'] ?? null) ? (float) $decoded['total_return'] : null,
            'exposure' => is_numeric($decoded['exposure'] ?? null) ? (float) $decoded['exposure'] : null,
            'equity_curve_sample' => is_array($decoded['equity_curve_sample'] ?? null) ? array_values($decoded['equity_curve_sample']) : [],
            'holdout_passed' => (bool) ($decoded['holdout_passed'] ?? true),
            'trades' => is_array($decoded['trades'] ?? null) ? $decoded['trades'] : [],
            'propose_only' => true,
            'live_trading' => 'forbidden',
        ];
    }

    /** @return array<string,mixed> */
    private function unavailable(string $reason): array
    {
        return [
            'status' => 'unavailable',
            'engine' => 'atlas_python_replay',
            'reason' => $reason,
            'propose_only' => true,
            'live_trading' => 'forbidden',
        ];
    }

    private function defaultScriptPath(): string
    {
        return $this->repoRoot().'/scripts/finance/strategy_second_engine_replay.py';
    }

    private function repoRoot(): string
    {
        $cwd = getcwd();
        if (is_string($cwd) && is_file($cwd.'/scripts/finance/strategy_second_engine_replay.py')) {
            return $cwd;
        }

        if (function_exists('base_path')) {
            try {
                $base = base_path();
                if (is_string($base) && is_file($base.'/scripts/finance/strategy_second_engine_replay.py')) {
                    return $base;
                }
            } catch (\Throwable) {
                // Fall through to the path relative to this service.
            }
        }

        return dirname(__DIR__, 6);
    }
}
