<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\AutonomousEvolution\ConflictResolution\AtlasLoopCycleConflictDetector;
use App\Services\Ai\AutonomousEvolution\ConflictResolution\AtlasLoopCycleConflictPolicy;
use App\Services\Ai\AutonomousEvolution\ConflictResolution\AtlasLoopCycleConflictResolver;
use Illuminate\Console\Command;

/**
 * Operator CLI for the cross-cycle conflict resolution loop. FACTS only — no score, no winner.
 *
 * Subcommands:
 *   detect  — runs {@see AtlasLoopCycleConflictDetector} over the two named cycles' write-sets and
 *             prints the per-file overlap-mode ConflictReport (identical-bytes | disjoint-hunks | divergent-bytes).
 *   resolve — runs {@see AtlasLoopCycleConflictResolver}, prints the policy clause + receipt ids.
 *             divergent-bytes ⇒ R-DIV ⇒ exit non-zero (REFUSE); identical-bytes ⇒ R-IDENT ⇒ exit 0.
 *   history — streams prior decisions from the operator-pointed receipt store, filtered by clause.
 *
 * Master-switch contract: when ATLAS_LOOP_MASTER_ENABLED is false, the command short-circuits as a
 * byte-identical no-op (exit 0, NEVER calls the detector or the resolver, NEVER touches the receipt
 * store).
 */
final class AtlasLoopConflictCommand extends Command
{
    public const CYCLE_SOURCE_BINDING = 'atlas.loop.conflict.cycle_source';

    public const RECEIPT_STORE_PATH_BINDING = 'atlas.loop.conflict.receipt_store_path';

    private const VALID_ACTIONS = ['detect', 'resolve', 'history'];

    protected $signature = 'atlas:loop:conflict {action : detect|resolve|history} {--cycle-a=} {--cycle-b=} {--clause=} {--json}';

    protected $description = 'Cross-cycle conflict resolution surface (detect | resolve | history).';

    public function __construct(
        private readonly AtlasLoopCycleConflictDetector $detector,
        private readonly AtlasLoopCycleConflictResolver $resolver,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        if (! in_array($action, self::VALID_ACTIONS, true)) {
            return $this->emit(['error' => 'unknown_action', 'action' => $action, 'valid_actions' => self::VALID_ACTIONS], 1);
        }

        if (! AtlasLoopMasterSwitch::enabled()) {
            return $this->emit([
                'action' => $action,
                'status' => 'no_op',
                'reason' => 'master_switch_off',
            ], 0);
        }

        return match ($action) {
            'detect' => $this->doDetect(),
            'resolve' => $this->doResolve(),
            'history' => $this->doHistory(),
        };
    }

    private function doDetect(): int
    {
        $pair = $this->loadPair();
        if ($pair === null) {
            return $this->emit(['error' => 'cycle_pair_unresolvable'], 1);
        }
        [$left, $right] = $pair;
        $report = $this->detector->detect($left, $right);

        return $this->emit($report->toArray(), 0);
    }

    private function doResolve(): int
    {
        $pair = $this->loadPair();
        if ($pair === null) {
            return $this->emit(['error' => 'cycle_pair_unresolvable'], 1);
        }
        [$left, $right] = $pair;
        $report = $this->detector->detect($left, $right);
        $decision = $this->resolver->resolve($report);

        $clause = $this->clauseFor($decision->decision);
        $exit = $clause === AtlasLoopCycleConflictPolicy::CLAUSE_DIVERGENT ? 2 : 0;

        return $this->emit([
            'cycle_a' => (string) $this->option('cycle-a'),
            'cycle_b' => (string) $this->option('cycle-b'),
            'clause' => $clause,
            'decision' => $decision->decision,
            'blocked_cycles' => $decision->blockedCycles,
            'receipt_ids' => array_map(
                static fn (array $r): string => hash('sha256', (string) json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
                $decision->receipts,
            ),
            'receipts' => $decision->receipts,
        ], $exit);
    }

    private function doHistory(): int
    {
        $clauseFilter = (string) $this->option('clause');
        $path = $this->receiptStorePath();
        if (! is_file($path)) {
            return $this->emit(['action' => 'history', 'rows' => []], 0);
        }
        $rows = [];
        foreach ((array) file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $decoded = json_decode((string) $line, true);
            if (! is_array($decoded)) {
                continue;
            }
            $clause = (string) ($decoded['policy_clause'] ?? '');
            if (! in_array($clause, [
                AtlasLoopCycleConflictPolicy::CLAUSE_DIVERGENT,
                AtlasLoopCycleConflictPolicy::CLAUSE_IDENTICAL,
                AtlasLoopCycleConflictPolicy::CLAUSE_STITCH,
            ], true)) {
                continue;
            }
            if ($clauseFilter !== '' && $clause !== $clauseFilter) {
                continue;
            }
            $rows[] = $decoded;
        }

        return $this->emit(['action' => 'history', 'rows' => $rows], 0);
    }

    private function clauseFor(string $decision): string
    {
        return match ($decision) {
            AtlasLoopCycleConflictPolicy::DECISION_REFUSE => AtlasLoopCycleConflictPolicy::CLAUSE_DIVERGENT,
            AtlasLoopCycleConflictPolicy::DECISION_AUTO_MERGE_IDENTICAL => AtlasLoopCycleConflictPolicy::CLAUSE_IDENTICAL,
            AtlasLoopCycleConflictPolicy::DECISION_STITCH_PENDING_OPERATOR => AtlasLoopCycleConflictPolicy::CLAUSE_STITCH,
            default => '',
        };
    }

    /**
     * @return array{0:array<string,mixed>,1:array<string,mixed>}|null
     */
    private function loadPair(): ?array
    {
        $a = (string) $this->option('cycle-a');
        $b = (string) $this->option('cycle-b');
        if ($a === '' || $b === '') {
            return null;
        }
        if (! $this->getLaravel()->bound(self::CYCLE_SOURCE_BINDING)) {
            return null;
        }
        $source = $this->getLaravel()->make(self::CYCLE_SOURCE_BINDING);
        if (! is_callable($source)) {
            return null;
        }
        $left = $source($a);
        $right = $source($b);
        if (! is_array($left) || ! is_array($right)) {
            return null;
        }

        return [$left, $right];
    }

    private function receiptStorePath(): string
    {
        if ($this->getLaravel()->bound(self::RECEIPT_STORE_PATH_BINDING)) {
            return (string) $this->getLaravel()->make(self::RECEIPT_STORE_PATH_BINDING);
        }

        return storage_path('app/atlas/loop/conflict/decision-receipts.jsonl');
    }

    /**
     * @param  array<string|int,mixed>  $payload
     */
    private function emit(array $payload, int $exit): int
    {
        $sorted = $this->sortRecursive($payload);
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if (! $this->option('json')) {
            $flags |= JSON_PRETTY_PRINT;
        }
        $this->line((string) json_encode($sorted, $flags));

        return $exit;
    }

    private function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $k => $v) {
            $value[$k] = $this->sortRecursive($v);
        }

        return $value;
    }
}
