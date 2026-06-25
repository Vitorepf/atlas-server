<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Anomaly\AtlasLoopAnomalyBaselineReporter;
use App\Services\Ai\AutonomousEvolution\Anomaly\AtlasLoopAnomalyDeviationDetector;
use App\Services\Ai\AutonomousEvolution\Anomaly\AtlasLoopAnomalyReceiptLedger;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator-facing anomaly observability CLI:
 *   atlas:loop:anomaly baseline --facts=PATH --json
 *   atlas:loop:anomaly inspect  --facts=PATH --json
 *   atlas:loop:anomaly history  --signal=... [--since=ISO] [--until=ISO] --json
 *
 * Prints FACTs only — never adjectives. inspect appends matching deviation FACTs to the ledger.
 */
final class AtlasLoopAnomalyCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:loop:anomaly
        {action : baseline|inspect|history}
        {--facts=}
        {--signal=}
        {--since=}
        {--until=}
        {--json}';

    /** @var string */
    protected $description = 'Anomaly observability CLI: baseline | inspect | history.';

    public function handle(): int
    {
        $action = (string) $this->argument('action');

        return match ($action) {
            'baseline' => $this->baseline(),
            'inspect' => $this->inspect(),
            'history' => $this->history(),
            default => $this->emit(['error' => 'unknown_action:'.$action], self::FAILURE),
        };
    }

    private function baseline(): int
    {
        $facts = $this->readFacts();
        $window = is_array($facts['window'] ?? null) ? $facts['window'] : ['start' => '', 'end' => ''];
        $receipts = is_array($facts['receipts'] ?? null) ? array_values($facts['receipts']) : [];
        $reporter = $this->getLaravel()->make(AtlasLoopAnomalyBaselineReporter::class);

        return $this->emit($reporter->baseline($window, $receipts));
    }

    private function inspect(): int
    {
        $facts = $this->readFacts();
        $baseline = is_array($facts['baseline'] ?? null) ? $facts['baseline'] : [];
        $current = is_array($facts['current_window'] ?? null) ? $facts['current_window'] : [];
        $detector = $this->getLaravel()->make(AtlasLoopAnomalyDeviationDetector::class);
        $rows = $detector->detect($baseline, $current);

        $ledger = $this->getLaravel()->make(AtlasLoopAnomalyReceiptLedger::class);
        $observedAt = (string) ($facts['observed_at'] ?? gmdate('Y-m-d\TH:i:s\Z'));
        $appended = [];
        foreach ($rows as $row) {
            try {
                $appended[] = $ledger->append([
                    'signal' => (string) $row['signal'],
                    'baseline_rate' => (float) $row['baseline_rate'],
                    'current_rate' => (float) $row['current_rate'],
                    'delta_in_sigmas' => (float) $row['delta_in_sigmas'],
                    'window_start' => (string) $row['window_start'],
                    'window_end' => (string) $row['window_end'],
                    'observed_at' => $observedAt,
                ]);
            } catch (Throwable) {
                continue;
            }
        }

        return $this->emit(['detected' => $rows, 'appended' => $appended]);
    }

    private function history(): int
    {
        $ledger = $this->getLaravel()->make(AtlasLoopAnomalyReceiptLedger::class);
        $signal = (string) $this->option('signal');
        if ($signal === '') {
            return $this->emit(['error' => 'signal_required'], self::FAILURE);
        }
        $since = (string) ($this->option('since') ?? '');
        $until = (string) ($this->option('until') ?? '');
        $rows = $ledger->history($signal, $since !== '' ? $since : null, $until !== '' ? $until : null);

        return $this->emit(['signal' => $signal, 'rows' => $rows]);
    }

    /**
     * @return array<string,mixed>
     */
    private function readFacts(): array
    {
        $path = (string) ($this->option('facts') ?? '');
        if ($path === '' || ! is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, int $exit = self::SUCCESS): int
    {
        $stripped = $this->stripAdjectives($payload);
        $this->line((string) json_encode($stripped, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $exit;
    }

    /**
     * Strip any field whose string value contains a severity adjective. The CLI is FACT-only.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function stripAdjectives(array $payload): array
    {
        $forbidden = ['anomaly', 'alert', 'critical', 'warning', 'suspicious'];
        array_walk_recursive($payload, static function (&$value) use ($forbidden): void {
            if (! is_string($value)) {
                return;
            }
            foreach ($forbidden as $word) {
                if (stripos($value, $word) !== false) {
                    $value = '[redacted]';

                    return;
                }
            }
        });

        return $payload;
    }
}
