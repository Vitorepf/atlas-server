<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Introspection\AtlasLoopSelfArchitectureScanner;
use App\Services\Ai\AutonomousEvolution\Introspection\AtlasLoopSelfCapabilityCoverageReporter;
use App\Services\Ai\AutonomousEvolution\Introspection\AtlasLoopSelfDependencyGraphReporter;
use App\Services\Ai\AutonomousEvolution\Introspection\AtlasLoopSelfIntrospectionReceiptLedger;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator-facing introspection CLI: 'how do you look right now?'
 *   atlas:loop:self architecture --json
 *   atlas:loop:self deps         --json
 *   atlas:loop:self coverage     --json
 *   atlas:loop:self history      --since=-1day [--json]
 *
 * Every action invocation records a receipt via {@see AtlasLoopSelfIntrospectionReceiptLedger}.
 * Read-only over the AutonomousEvolution tree, no provider calls. Allowed even when the master
 * switch is OFF — introspection is observability, not action.
 */
final class AtlasLoopSelfIntrospectionCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:loop:self {action : architecture|deps|coverage|history} {--json} {--since=}';

    /** @var string */
    protected $description = 'Loop introspection CLI: architecture | deps | coverage | history.';

    private const ALLOWED_ACTIONS = ['architecture', 'deps', 'coverage', 'history'];

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        if (! in_array($action, self::ALLOWED_ACTIONS, true)) {
            $this->error('unknown_action:'.$action);
            $this->error('allowed: '.implode(', ', self::ALLOWED_ACTIONS));

            return self::FAILURE;
        }

        $app = $this->getLaravel();
        $ledger = $app->bound(AtlasLoopSelfIntrospectionReceiptLedger::class)
            ? $app->make(AtlasLoopSelfIntrospectionReceiptLedger::class)
            : new AtlasLoopSelfIntrospectionReceiptLedger();

        if ($action === 'history') {
            return $this->history($ledger);
        }

        return $this->snapshot($action, $ledger);
    }

    private function snapshot(string $action, AtlasLoopSelfIntrospectionReceiptLedger $ledger): int
    {
        $payload = $this->buildPayload($action);
        $summary = ['action' => $action, 'cardinality' => count($payload['rows'] ?? [])];
        $atlasGitSha = (string) (getenv('ATLAS_GIT_SHA') ?: 'unknown');
        $receipt = $ledger->record('self_introspection.'.$action, $payload, $summary, $atlasGitSha);

        return $this->emit([
            'action' => $action,
            'payload' => $payload,
            'receipt' => [
                'payload_sha256' => $receipt['payload_sha256'] ?? '',
                'taken_at_utc' => $receipt['taken_at_utc'] ?? '',
            ],
        ]);
    }

    /**
     * REAL loop state — each action resolves its pure Introspection reporter from the container (mirroring the
     * ledger resolve pattern) and projects the reporter's deterministic scan() output into the backward-
     * compatible `rows` contract, carrying the full scan payload alongside. A reporter that cannot scan (e.g.
     * unreadable root) yields an empty rows list — NEVER the fabricated 'ok' stub this used to return.
     *
     * @return array<string,mixed>
     */
    private function buildPayload(string $action): array
    {
        try {
            return match ($action) {
                'architecture' => $this->architecturePayload(),
                'deps' => $this->depsPayload(),
                'coverage' => $this->coveragePayload(),
                default => ['rows' => []],
            };
        } catch (Throwable) {
            return ['rows' => []]; // honest emptiness over a fabricated 'ok'
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function architecturePayload(): array
    {
        /** @var AtlasLoopSelfArchitectureScanner $scanner */
        $scanner = $this->resolveReporter(AtlasLoopSelfArchitectureScanner::class);
        $scan = $scanner->scan();

        $methodsByClass = (array) ($scan['methods_by_class'] ?? []);
        $linesByClass = (array) ($scan['lines_by_class'] ?? []);
        $rows = [];
        foreach ($methodsByClass as $fqcn => $methods) {
            $rows[] = [
                'class' => (string) $fqcn,
                'public_methods' => is_array($methods) ? count($methods) : 0,
                'lines' => (int) ($linesByClass[$fqcn] ?? 0),
            ];
        }

        return ['rows' => $rows, 'scan' => $scan];
    }

    /**
     * @return array<string,mixed>
     */
    private function depsPayload(): array
    {
        /** @var AtlasLoopSelfDependencyGraphReporter $reporter */
        $reporter = $this->resolveReporter(AtlasLoopSelfDependencyGraphReporter::class);
        $scan = $reporter->scan();

        return ['rows' => array_values((array) ($scan['edges'] ?? [])), 'scan' => $scan];
    }

    /**
     * @return array<string,mixed>
     */
    private function coveragePayload(): array
    {
        /** @var AtlasLoopSelfCapabilityCoverageReporter $reporter */
        $reporter = $this->resolveReporter(AtlasLoopSelfCapabilityCoverageReporter::class);
        $scan = $reporter->scan();

        $rows = [];
        foreach ((array) ($scan['capabilities'] ?? []) as $capability => $detail) {
            $row = ['capability' => (string) $capability];
            $rows[] = is_array($detail) ? $row + $detail : $row + ['state' => $detail];
        }

        return ['rows' => $rows, 'scan' => $scan];
    }

    /**
     * Resolve a reporter through the container when bound (so tests can inject one), else construct it directly
     * — the same bound()?make:new seam the ledger uses.
     *
     * @template T of object
     * @param  class-string<T>  $class
     * @return T
     */
    private function resolveReporter(string $class): object
    {
        $app = $this->getLaravel();

        return $app->bound($class) ? $app->make($class) : new $class();
    }

    private function history(AtlasLoopSelfIntrospectionReceiptLedger $ledger): int
    {
        $since = $this->parseSince((string) ($this->option('since') ?? ''));
        $rows = $ledger->list($since);

        return $this->emit(['action' => 'history', 'count' => count($rows), 'receipts' => $rows]);
    }

    private function parseSince(string $raw): ?Carbon
    {
        if ($raw === '') {
            return null;
        }
        try {
            // Accept "-1day", "-1 day", or ISO timestamps. Carbon::parse handles relative strings.
            $normalized = preg_replace('/^-(\d+)(day|hour|minute|week)s?$/i', '-$1 $2', $raw) ?? $raw;

            return Carbon::parse($normalized, 'UTC');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload): int
    {
        $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
