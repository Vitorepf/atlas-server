<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainReflectionStream;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainScopeRegistry;
use Illuminate\Console\Command;

/**
 * BRAIN HISTORY — operator-friendly tail of the reflection stream for a scope. The stream already
 * exists (L14) but is structured NDJSON; this command renders the last N rows as compact rows with
 * the operator-relevant fields (cycle_id, recorded_at, result_kind, action_hint, head-of-reflection).
 *
 * Read-only; no mutation. Pétreo (brain surface).
 */
final class AtlasBrainHistoryCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:brain:history {--scope= : scope slug} {--tail=10 : how many recent rows to render} {--kind= : filter to one result_kind (blocked|exhausted|stagnated|note|clean_no_op|success)} {--hint= : filter to one action_hint} {--json} {--raw}';

    /** @var string */
    protected $description = 'Tail of the brain reflection stream for a scope — compact operator-readable rows.';

    public function handle(): int
    {
        $scopeOpt = trim((string) ($this->option('scope') ?? ''));
        $scope = (string) app(AtlasBrainScopeRegistry::class)->resolve($scopeOpt)['slug'];
        $tail = max(1, (int) ($this->option('tail') ?? 10));

        $kindFilter = trim((string) ($this->option('kind') ?? ''));
        $hintFilter = trim((string) ($this->option('hint') ?? ''));
        $all = app(AtlasBrainReflectionStream::class)->forScope($scope);
        if ($kindFilter !== '') {
            $all = array_values(array_filter($all, static fn (array $r): bool => (string) ($r['result_kind'] ?? '') === $kindFilter));
        }
        if ($hintFilter !== '') {
            $all = array_values(array_filter($all, static fn (array $r): bool => (string) ($r['signals']['action_hint'] ?? '') === $hintFilter));
        }
        $rows = array_slice($all, -$tail);

        $compact = [];
        foreach ($rows as $row) {
            $text = (string) ($row['reflection'] ?? '');
            $compact[] = [
                'cycle_id' => (string) ($row['cycle_id'] ?? ''),
                'recorded_at' => (int) ($row['recorded_at'] ?? 0),
                'result_kind' => (string) ($row['result_kind'] ?? ''),
                'action_hint' => (string) ($row['signals']['action_hint'] ?? ''),
                'reflection_head' => mb_substr($text, 0, 120),
            ];
        }

        $payload = ['scope' => $scope, 'count' => count($compact), 'rows' => $compact];
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if (! $this->option('raw')) {
            $flags |= JSON_PRETTY_PRINT;
        }
        $this->line((string) json_encode($payload, $flags));

        return self::SUCCESS;
    }
}
