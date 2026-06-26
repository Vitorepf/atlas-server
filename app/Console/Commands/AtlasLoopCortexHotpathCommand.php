<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Hotpath\AtlasCortexHotpathFrequencyReporter;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Hotpath\AtlasCortexHotpathThresholdEmitter;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/**
 * Operator observability for Cortex Hotpath FACTs.
 *
 *   atlas:loop:cortex:hotpath report  --window=N [--limit=L] [--json]
 *   atlas:loop:cortex:hotpath emit    --window=N --threshold=T [--limit=L] [--json]
 *
 * Surfaces FACTs only. No score, no verdict, no mutation.
 *
 * Anti-Goodhart guard: refuses to emit any row whose key set contains an
 * aggregate scalar (score / rank / hot / composite / total). If detected
 * the command exits 1 with the canonical violation message.
 *
 * Master-switch awareness: when ATLAS_LOOP_MASTER_ENABLED=false AND
 * --strict-master is passed, the command exits SUCCESS with status=disabled
 * (read-only + provider-free, so without --strict-master it still runs).
 */
final class AtlasLoopCortexHotpathCommand extends Command
{
    public const CYCLE_FACTS_SOURCE_KEY = 'atlas.cortex.hotpath.cycle_facts_source';

    protected $signature = 'atlas:loop:cortex:hotpath {action : report|emit}
        {--window=20 : N-cycle window}
        {--threshold=0.30 : emit threshold 0.0-1.0}
        {--limit=50 : truncate displayed rows}
        {--json : machine-readable output}
        {--strict-master : exit SUCCESS/disabled when MASTER=false}';

    protected $description = 'Cortex Hotpath FACT observability (report | emit).';

    /**
     * Keys whose presence in a reporter row is treated as a Goodhart
     * aggregate scalar (synthesized hotness, rank, score, total).
     */
    private const GOODHART_FORBIDDEN_KEYS = ['score', 'rank', 'hot', 'composite', 'total'];

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        $window = max(1, (int) ($this->option('window') ?? 20));
        $strictMaster = (bool) $this->option('strict-master');

        // Master-switch awareness: read-only + provider-free, so we run
        // unless --strict-master is explicitly passed AND master is off.
        if ($strictMaster && ! $this->masterEnabled()) {
            $this->emitStatus('disabled', 'master_switch_off');

            return self::SUCCESS;
        }

        // Argument validation upfront — exit 2 on bad args.
        if ($action === 'emit') {
            $threshold = (float) ($this->option('threshold') ?? 0.30);
            if (is_nan($threshold) || $threshold < 0.0 || $threshold > 1.0) {
                $this->getOutput()->writeln('threshold_out_of_range: must be 0.0..1.0');

                return 2;
            }
        }

        try {
            return match ($action) {
                'report' => $this->report($window),
                'emit' => $this->emit($window),
                default => $this->failWith('unknown_action:'.$action),
            };
        } catch (InvalidArgumentException $e) {
            // Goodhart violation is signalled via InvalidArgumentException
            // from guardGoodhart(); that is exit code 1, not 2.
            if ($e->getMessage() === 'goodhart_violation') {
                return 1;
            }
            $this->getOutput()->writeln($e->getMessage());

            return 2;
        } catch (Throwable $e) {
            $this->getOutput()->writeln('reporter_exception:'.$e->getMessage());

            return 3;
        }
    }

    private function report(int $window): int
    {
        $cycleFacts = $this->cycleFactsForWindow($window);

        /** @var AtlasCortexHotpathFrequencyReporter $reporter */
        $reporter = app(AtlasCortexHotpathFrequencyReporter::class);
        $rows = $reporter->report($cycleFacts);

        $this->guardGoodhart($rows);

        $rows = $this->truncate($rows);
        $this->printRows($rows, ['fqcn', 'cycle_count', 'window_size', 'frequency']);

        return self::SUCCESS;
    }

    private function emit(int $window): int
    {
        $threshold = (float) ($this->option('threshold') ?? 0.30);
        $cycleFacts = $this->cycleFactsForWindow($window);

        /** @var AtlasCortexHotpathFrequencyReporter $reporter */
        $reporter = app(AtlasCortexHotpathFrequencyReporter::class);
        /** @var AtlasCortexHotpathThresholdEmitter $emitter */
        $emitter = app(AtlasCortexHotpathThresholdEmitter::class);

        $rows = $reporter->report($cycleFacts);
        $rows = $emitter->emit($rows, $threshold);

        $this->guardGoodhart($rows);

        $rows = $this->truncate($rows);
        $this->printRows($rows, ['fqcn', 'cycle_count', 'window_size', 'frequency', 'threshold_used']);

        return self::SUCCESS;
    }

    /**
     * Anti-Goodhart guard: every row's key set must NOT contain any of the
     * canonical aggregate-scalar keys (score/rank/hot/composite/total).
     *
     * @param  array<int,array<string,mixed>>  $rows
     */
    private function guardGoodhart(array $rows): void
    {
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            foreach (self::GOODHART_FORBIDDEN_KEYS as $forbidden) {
                if (array_key_exists($forbidden, $row)) {
                    $output = $this->getOutput();
                    if ($output !== null) {
                        $output->writeln(
                            'Goodhart violation: aggregate scalar forbidden in cortex hotpath row'
                        );
                    }

                    throw new InvalidArgumentException('goodhart_violation');
                }
            }
        }
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     * @return array<int,array<string,mixed>>
     */
    private function truncate(array $rows): array
    {
        $limit = (int) ($this->option('limit') ?? 50);
        if ($limit <= 0) {
            return $rows;
        }

        return array_slice($rows, 0, $limit);
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     * @param  list<string>  $columns
     */
    private function printRows(array $rows, array $columns): void
    {
        if ($this->option('json')) {
            // --json: one JSON object per line (top-level array of row objects)
            $this->getOutput()->writeln(
                (string) json_encode(
                    array_values($rows),
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                ),
                0
            );

            return;
        }

        // Plain-ASCII non-json output, table format (columns as header).
        $this->getOutput()->writeln(implode('|', $columns), 0);
        foreach ($rows as $row) {
            $cells = [];
            foreach ($columns as $col) {
                $v = $row[$col] ?? '';
                $cells[] = is_scalar($v) ? (string) $v : (string) json_encode($v, JSON_UNESCAPED_SLASHES);
            }
            $this->getOutput()->writeln(implode('|', $cells), 0);
        }
    }

    private function emitStatus(string $status, string $reason): void
    {
        $payload = ['status' => $status, 'reason' => $reason];
        if ($this->option('json')) {
            $this->getOutput()->writeln(
                (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                0
            );

            return;
        }
        $this->getOutput()->writeln($status.': '.$reason, 0);
    }

    /**
     * @return list<array{cycle_id?:string,touched_fqcns?:list<string>}>
     */
    private function cycleFactsForWindow(int $window): array
    {
        if (! app()->bound(self::CYCLE_FACTS_SOURCE_KEY)) {
            return [];
        }
        $source = app(self::CYCLE_FACTS_SOURCE_KEY);
        if (! is_callable($source)) {
            return [];
        }
        $facts = $source($window);

        return is_array($facts) ? array_values($facts) : [];
    }

    private function masterEnabled(): bool
    {
        $env = getenv('ATLAS_LOOP_MASTER_ENABLED');

        return $env === false || $env === '' || strtolower((string) $env) === 'true';
    }

    private function failWith(string $reason): int
    {
        $this->getOutput()->writeln($reason);

        return 2;
    }
}