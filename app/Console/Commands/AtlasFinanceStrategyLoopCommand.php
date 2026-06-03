<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * THE TRADING LOOP, RUNNING. Drives `atlas:finance:strategy-evolve` round after round —
 * each round explores N scenarios through the provider, applies the honesty gate, and
 * persists a propose-only proposal (or an honest null). It keeps searching for the best
 * strategy until a kill-switch file appears or a round/time budget is hit. Every round is
 * appended to a durable JSONL ledger so the search is fully auditable after the fact.
 *
 * Propose-only by construction: it only ever invokes the evolve command, which never
 * merges and never trades. An honest null every round is the EXPECTED state — a surviving
 * strategy is rare and earned.
 */
final class AtlasFinanceStrategyLoopCommand extends Command
{
    protected $signature = 'atlas:finance:strategy-loop
        {--symbol=BTCUSDT : Market symbol}
        {--interval=1d : Bar interval}
        {--scenarios=6 : Scenarios explored per round}
        {--provider= : Provider override (default: loop default)}
        {--max-rounds=0 : Stop after this many rounds (0 = unbounded)}
        {--max-seconds=0 : Stop after this many seconds (0 = unbounded)}
        {--sleep=5 : Seconds to pause between rounds}
        {--kill-switch= : File whose existence stops the loop (default storage/atlas/finance/STOP)}';

    protected $description = 'Run the trading strategy-evolution loop continuously (propose-only, no live trading) until a kill-switch or budget.';

    public function handle(): int
    {
        $symbol = (string) $this->option('symbol');
        $interval = (string) $this->option('interval');
        $kill = trim((string) $this->option('kill-switch')) ?: storage_path('atlas/finance/STOP');
        $maxRounds = max(0, (int) $this->option('max-rounds'));
        $maxSeconds = max(0, (int) $this->option('max-seconds'));
        $sleep = max(0, (int) $this->option('sleep'));
        $ledger = storage_path('atlas/finance/loop-ledger.jsonl');
        @mkdir(dirname($ledger), 0o755, true);

        $this->info("Atlas trading strategy-evolution loop — {$symbol}-{$interval}, {$this->option('scenarios')} scenarios/round.");
        $this->line('Propose-only · never-merge · no real money. Kill-switch: '.$kill);
        $this->newLine();

        $start = time();
        $round = 0;
        $certified = 0;
        $bestDsr = null;

        while (true) {
            if (is_file($kill)) {
                $this->warn('kill-switch present — stopping.');
                break;
            }
            if ($maxRounds > 0 && $round >= $maxRounds) {
                $this->line('max-rounds reached — stopping.');
                break;
            }
            if ($maxSeconds > 0 && (time() - $start) >= $maxSeconds) {
                $this->line('max-seconds reached — stopping.');
                break;
            }

            $round++;
            $res = $this->runRound($symbol, $interval);
            $dsr = $res['report']['deflated_sharpe'] ?? null;
            if (is_numeric($dsr) && ($bestDsr === null || $dsr > $bestDsr)) {
                $bestDsr = (float) $dsr;
            }
            if ($res['certified'] ?? false) {
                $certified++;
            }

            $this->appendLedger($ledger, $round, $res);
            $this->line(sprintf(
                '[round %d] %s · N=%s · DSR=%s · %s',
                $round,
                ($res['certified'] ?? false) ? '<info>CERTIFIED</info>' : 'null',
                (string) ($res['scenarios_explored'] ?? '?'),
                $dsr !== null ? (string) $dsr : 'n/a',
                ($res['certified'] ?? false) ? 'proposal: '.($res['proposal_path'] ?? '') : implode('; ', array_slice($res['reasons'] ?? ['?'], 0, 2)),
            ));

            if ($sleep > 0 && ! is_file($kill)) {
                sleep($sleep);
            }
        }

        $this->newLine();
        $this->components->twoColumnDetail('Rounds run', (string) $round);
        $this->components->twoColumnDetail('Certified proposals', (string) $certified);
        $this->components->twoColumnDetail('Best deflated Sharpe seen', $bestDsr !== null ? (string) $bestDsr : 'none');
        $this->components->twoColumnDetail('Ledger', $ledger);
        $this->components->twoColumnDetail('Proposals dir', storage_path('atlas/finance/proposals'));

        return self::SUCCESS;
    }

    /** @return array<string,mixed> */
    private function runRound(string $symbol, string $interval): array
    {
        $args = [
            'php', base_path('artisan'), 'atlas:finance:strategy-evolve',
            '--symbol='.$symbol, '--interval='.$interval,
            '--scenarios='.(int) $this->option('scenarios'), '--json',
        ];
        $provider = trim((string) $this->option('provider'));
        if ($provider !== '') {
            $args[] = '--provider='.$provider;
        }

        $proc = new Process($args, base_path(), null, null, 3600.0);
        $proc->run();
        $out = $proc->getOutput();
        if (preg_match('/ATLAS_EVOLVE_RESULT=(\{.*\})/', $out, $m) === 1) {
            $decoded = json_decode($m[1], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return ['certified' => false, 'reasons' => ['round_failed'], 'report' => [], 'raw_tail' => mb_substr(trim($proc->getErrorOutput() ?: $out), -300)];
    }

    /** @param array<string,mixed> $res */
    private function appendLedger(string $ledger, int $round, array $res): void
    {
        $line = json_encode([
            'round' => $round,
            'at' => date('c'),
            'certified' => (bool) ($res['certified'] ?? false),
            'scenarios_explored' => $res['scenarios_explored'] ?? null,
            'deflated_sharpe' => $res['report']['deflated_sharpe'] ?? null,
            'pbo' => $res['report']['pbo'] ?? null,
            'holdout_sharpe' => $res['report']['holdout_sharpe'] ?? null,
            'reasons' => $res['reasons'] ?? [],
            'proposal_path' => $res['proposal_path'] ?? null,
            'merged_to_main' => false,
        ], JSON_UNESCAPED_SLASHES);
        file_put_contents($ledger, $line."\n", FILE_APPEND | LOCK_EX);
    }
}
