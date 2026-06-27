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
    protected $signature = 'atlas:brain:history {--scope= : scope slug} {--tail=10 : how many recent rows to render} {--json} {--raw}';

    /** @var string */
    protected $description = 'Tail of the brain reflection stream for a scope — compact operator-readable rows.';

    public function handle(): int
    {
        $scopeOpt = trim((string) ($this->option('scope') ?? ''));
        $scope = (string) app(AtlasBrainScopeRegistry::class)->resolve($scopeOpt)['slug'];
        $tail = max(1, (int) ($this->option('tail') ?? 10));

        $rows = array_slice(app(AtlasBrainReflectionStream::class)->forScope($scope), -$tail);

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
