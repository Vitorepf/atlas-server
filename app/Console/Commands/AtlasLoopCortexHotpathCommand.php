<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Hotpath\AtlasCortexHotpathFrequencyReporter;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Hotpath\AtlasCortexHotpathHistoryReporter;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Hotpath\AtlasCortexHotpathThresholdEmitter;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Operator observability for Cortex Hotpath FACTs.
 *   atlas:loop:cortex:hotpath frequency --window=N [--json]
 *   atlas:loop:cortex:hotpath threshold --window=N --threshold=T [--json]
 *   atlas:loop:cortex:hotpath history   --window=N [--json]
 *
 * Surfaces FACTs only. No score, no verdict, no mutation.
 */
final class AtlasLoopCortexHotpathCommand extends Command
{
    public const CYCLE_FACTS_SOURCE_KEY = 'atlas.cortex.hotpath.cycle_facts_source';

    protected $signature = 'atlas:loop:cortex:hotpath {action : frequency|threshold|history}
        {--window=20}
        {--threshold=0.30}
        {--json}';

    protected $description = 'Cortex Hotpath FACT observability (frequency | threshold | history).';

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        $window = max(1, (int) ($this->option('window') ?? 20));

        $cycleFacts = $this->cycleFactsForWindow($window);

        try {
            return match ($action) {
                'frequency' => $this->frequency($cycleFacts),
                'threshold' => $this->threshold($cycleFacts),
                'history' => $this->history($cycleFacts),
                default => $this->failWith('unknown_action:'.$action),
            };
        } catch (InvalidArgumentException $e) {
            $this->getOutput()->writeln($e->getMessage());

            return 2;
        }
    }

    private function frequency(array $cycleFacts): int
    {
        /** @var AtlasCortexHotpathFrequencyReporter $reporter */
        $reporter = app(AtlasCortexHotpathFrequencyReporter::class);
        $this->emit($reporter->report($cycleFacts));

        return 0;
    }

    private function threshold(array $cycleFacts): int
    {
        $threshold = (float) ($this->option('threshold') ?? 0.30);
        /** @var AtlasCortexHotpathFrequencyReporter $reporter */
        $reporter = app(AtlasCortexHotpathFrequencyReporter::class);
        /** @var AtlasCortexHotpathThresholdEmitter $emitter */
        $emitter = app(AtlasCortexHotpathThresholdEmitter::class);
        $rows = $reporter->report($cycleFacts);
        $this->emit($emitter->emit($rows, $threshold));

        return 0;
    }

    private function history(array $cycleFacts): int
    {
        /** @var AtlasCortexHotpathHistoryReporter $reporter */
        $reporter = app(AtlasCortexHotpathHistoryReporter::class);
        $this->emit($reporter->report($cycleFacts));

        return 0;
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

    /**
     * @param  array<int,array<string,mixed>>  $rows
     */
    private function emit(array $rows): void
    {
        if ($this->option('json')) {
            $this->getOutput()->writeln((string) json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 0);

            return;
        }
        // Plain-ASCII non-json output, no color codes.
        foreach ($rows as $row) {
            $cells = [];
            foreach ($row as $k => $v) {
                $cells[] = $k.'='.(is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_SLASHES));
            }
            $this->getOutput()->writeln(implode(' ', $cells), 0);
        }
    }

    private function failWith(string $reason): int
    {
        $this->getOutput()->writeln($reason);

        return 2;
    }
}
