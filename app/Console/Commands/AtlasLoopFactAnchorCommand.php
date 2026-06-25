<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\SymbolicAnchoring\AtlasLoopFactAnchorAuditor;
use App\Services\Ai\AutonomousEvolution\SymbolicAnchoring\AtlasLoopFactAnchorCoverageReporter;
use App\Services\Ai\AutonomousEvolution\SymbolicAnchoring\AtlasLoopFactAnchorExtractor;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator-facing symbolic-anchoring CLI:
 *   atlas:loop:facts:anchor coverage --window=200 [--since=ISO] [--channel=] [--json]
 *   atlas:loop:facts:anchor audit    --channel=... [--fact=PATH|--fact-text=...]
 *   atlas:loop:facts:anchor history  [--window=20]
 *
 * Resolves a FACT-source from the container under the key `atlas.loop.fact_source`. Production
 * wires a real source; tests bind a fake one.
 */
final class AtlasLoopFactAnchorCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:loop:facts:anchor
        {action : coverage|audit|history}
        {--window=200}
        {--since=}
        {--channel=}
        {--fact=}
        {--fact-text=}
        {--json}';

    /** @var string */
    protected $description = 'Symbolic anchoring CLI: coverage | audit | history.';

    public function handle(): int
    {
        $action = (string) $this->argument('action');

        return match ($action) {
            'coverage' => $this->coverage(),
            'audit' => $this->audit(),
            'history' => $this->history(),
            default => $this->emit(['error' => 'unknown_action:'.$action], self::FAILURE),
        };
    }

    private function coverage(): int
    {
        $window = max(1, (int) $this->option('window'));
        $source = $this->factSource();
        $facts = is_object($source) && method_exists($source, 'recent') ? (array) $source->recent($window) : [];
        $reporter = new AtlasLoopFactAnchorCoverageReporter($this->extractor());
        $report = $reporter->report($facts);
        $this->appendHistorySnapshot($report);

        return $this->emit($report);
    }

    private function audit(): int
    {
        $channel = (string) $this->option('channel');
        if ($channel === '') {
            return $this->emit(['error' => 'channel_required'], self::FAILURE);
        }
        $factText = $this->resolveFactText();
        if ($factText === null) {
            return $this->emit(['error' => 'fact_text_required'], self::FAILURE);
        }
        $criticalChannels = $this->criticalChannels();
        $auditor = new AtlasLoopFactAnchorAuditor($this->extractor(), $criticalChannels);
        $verdict = $auditor->audit($channel, $factText);
        $payload = [
            'accepted' => $verdict->accepted,
            'reason' => $verdict->reason,
            'resolved_count' => $verdict->resolvedCount,
            'unresolved_count' => $verdict->unresolvedCount,
        ];

        return $this->emit($payload, $verdict->accepted ? self::SUCCESS : self::FAILURE);
    }

    private function history(): int
    {
        $window = max(1, (int) $this->option('window'));
        $path = $this->historyPath();
        $rows = [];
        if (is_file($path)) {
            $fh = @fopen($path, 'rb');
            if ($fh !== false) {
                try {
                    while (($line = fgets($fh)) !== false) {
                        $line = rtrim($line, "\n");
                        if ($line === '') {
                            continue;
                        }
                        $decoded = json_decode($line, true);
                        if (is_array($decoded)) {
                            $rows[] = $decoded;
                        }
                    }
                } finally {
                    fclose($fh);
                }
            }
        }
        $rows = array_slice($rows, -$window);

        return $this->emit(['snapshots' => $rows, 'count' => count($rows)]);
    }

    private function resolveFactText(): ?string
    {
        $factPath = (string) $this->option('fact');
        if ($factPath !== '' && is_file($factPath)) {
            $contents = @file_get_contents($factPath);

            return is_string($contents) ? $contents : null;
        }
        $direct = (string) $this->option('fact-text');
        if ($direct !== '') {
            return $direct;
        }
        // Read from stdin when available.
        if (defined('STDIN') && is_resource(STDIN)) {
            $stdin = @stream_get_contents(STDIN);
            if (is_string($stdin) && $stdin !== '') {
                return $stdin;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function criticalChannels(): array
    {
        if (function_exists('config')) {
            $raw = config('atlas.loop.symbolic_anchoring.critical_channels');
            if (is_array($raw) && $raw !== []) {
                return array_values(array_map('strval', $raw));
            }
        }

        return ['comprehension', 'decision', 'grading'];
    }

    private function extractor(): AtlasLoopFactAnchorExtractor
    {
        return new AtlasLoopFactAnchorExtractor(base_path());
    }

    private function factSource(): ?object
    {
        $app = $this->getLaravel();
        if ($app->bound('atlas.loop.fact_source')) {
            $source = $app->make('atlas.loop.fact_source');

            return is_object($source) ? $source : null;
        }

        return null;
    }

    private function historyPath(): string
    {
        return storage_path('atlas/loop/anchor_coverage_history.jsonl');
    }

    /**
     * @param  array<string,mixed>  $report
     */
    private function appendHistorySnapshot(array $report): void
    {
        $path = $this->historyPath();
        $dir = \dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            return;
        }
        $line = (string) json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        @file_put_contents($path, $line."\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, int $exit = self::SUCCESS): int
    {
        try {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (Throwable) {
            $this->line(json_encode(['error' => 'json_encode_failed']));
        }

        return $exit;
    }
}
