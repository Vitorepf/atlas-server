<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\FrozenContracts\AtlasLoopFrozenContractAutoSentinelGenerator;
use App\Services\Ai\AutonomousEvolution\FrozenContracts\AtlasLoopFrozenContractCoverageReporter;
use App\Services\Ai\AutonomousEvolution\FrozenContracts\AtlasLoopFrozenContractFactExtractor;
use App\Services\Ai\AutonomousEvolution\FrozenContracts\AtlasLoopFrozenContractRetirementGate;
use App\Services\Ai\AutonomousEvolution\FrozenContracts\AtlasLoopFrozenContractSentinelClobberException;
use Illuminate\Console\Command;

/**
 * Operator CLI for the frozen-contract surface.
 *
 *   inspect --class=FQCN [--json]   Print the extracted FACT array for one class.
 *   coverage [--json]               Print the coverage report (with/without contract + sentinel coverage).
 *   retire --class=FQCN [--receipt=TOKEN] [--json]
 *                                   Delegate to the retirement gate; non-zero on denial.
 */
final class AtlasLoopFrozenContractsCommand extends Command
{
    public const EXIT_OK = 0;

    public const EXIT_DENIED = 1;

    public const EXIT_USAGE = 2;

    public const EXIT_CLOBBER_REFUSED = 3;

    protected $signature = 'atlas:loop:frozen:contracts {action : inspect|coverage|retire|generate-sentinel} {--class= : FQCN for inspect/retire/generate-sentinel} {--receipt= : operator receipt token for retire/generate-sentinel} {--target= : target sentinel path for generate-sentinel} {--json}';

    protected $description = 'Operator surface for frozen contracts: inspect | coverage | retire | generate-sentinel.';

    public function handle(
        AtlasLoopFrozenContractFactExtractor $extractor,
        AtlasLoopFrozenContractCoverageReporter $coverage,
        AtlasLoopFrozenContractRetirementGate $gate,
    ): int {
        $action = (string) $this->argument('action');

        return match ($action) {
            'inspect' => $this->inspect($extractor),
            'coverage' => $this->coverage($coverage),
            'retire' => $this->retire($gate),
            'generate-sentinel' => $this->generateSentinel($extractor),
            default => $this->usage('unknown action: '.$action),
        };
    }

    private function generateSentinel(AtlasLoopFrozenContractFactExtractor $extractor): int
    {
        $class = trim((string) $this->option('class'));
        $target = trim((string) $this->option('target'));
        if ($class === '' || $target === '') {
            return $this->usage('--class=<FQCN> and --target=<path> are required for generate-sentinel');
        }
        $facts = $extractor->extract($class);
        $generator = app()->bound(AtlasLoopFrozenContractAutoSentinelGenerator::class)
            ? app(AtlasLoopFrozenContractAutoSentinelGenerator::class)
            : new AtlasLoopFrozenContractAutoSentinelGenerator();
        $receipt = (string) $this->option('receipt');
        try {
            $result = $generator->generate($facts, $target, $receipt !== '' ? $receipt : null);
        } catch (AtlasLoopFrozenContractSentinelClobberException $e) {
            $this->emit(
                ['ok' => false, 'reason' => 'sentinel_clobber_refused', 'message' => $e->getMessage(), 'target' => $target],
                'sentinel_clobber_refused target='.$target,
            );

            return self::EXIT_CLOBBER_REFUSED;
        }

        $this->emit(
            ['ok' => true, 'class_name' => $result['class_name'], 'target' => $target, 'bytes' => strlen($result['code'])],
            'generated class='.$result['class_name'].' target='.$target,
        );

        return self::EXIT_OK;
    }

    private function inspect(AtlasLoopFrozenContractFactExtractor $extractor): int
    {
        $class = trim((string) $this->option('class'));
        if ($class === '') {
            return $this->usage('--class=<FQCN> is required for inspect');
        }
        $facts = $extractor->extract($class);
        $this->emit($facts, 'class='.$class);

        return self::EXIT_OK;
    }

    private function coverage(AtlasLoopFrozenContractCoverageReporter $coverage): int
    {
        $report = $coverage->report();
        $this->emit($report, sprintf(
            'with=%d without=%d sentinel=%d orphan=%d',
            count((array) $report['with_contract']),
            count((array) $report['without_contract']),
            count((array) $report['with_sentinel']),
            count((array) $report['orphan_contracts_without_sentinel']),
        ));

        return self::EXIT_OK;
    }

    private function retire(AtlasLoopFrozenContractRetirementGate $gate): int
    {
        $class = trim((string) $this->option('class'));
        if ($class === '') {
            return $this->usage('--class=<FQCN> is required for retire');
        }
        $receipt = (string) $this->option('receipt');
        $verdict = $gate->decide($class, $receipt !== '' ? $receipt : null);

        $this->emit($verdict, 'allowed='.($verdict['allowed'] ? 'true' : 'false').' reason='.$verdict['reason']);

        return $verdict['allowed'] ? self::EXIT_OK : self::EXIT_DENIED;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, string $humanLine): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }
        $this->line($humanLine);
    }

    private function usage(string $message): int
    {
        $this->error($message);

        return self::EXIT_USAGE;
    }
}
