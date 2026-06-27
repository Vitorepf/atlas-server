<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainProvenanceAttributionAnalyzer;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainProvenanceLedger;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainScopeRegistry;
use Illuminate\Console\Command;

/**
 * BRAIN PROVENANCE — tails the L112 lineage ledger for operator audit. Read-only. Pétreo.
 */
final class AtlasBrainProvenanceCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:brain:provenance {--scope= : scope slug} {--tail=10 : recent rows to render} {--source-finding= : filter to one finding code} {--attribution : also emit per-finding seed counts (L139)} {--json} {--raw}';

    /** @var string */
    protected $description = 'Tail the per-seed provenance ledger (cycle_id + lineage signals).';

    public function handle(): int
    {
        $scopeOpt = trim((string) ($this->option('scope') ?? ''));
        $scope = (string) app(AtlasBrainScopeRegistry::class)->resolve($scopeOpt)['slug'];
        $tail = max(1, (int) ($this->option('tail') ?? 10));

        $filter = trim((string) ($this->option('source-finding') ?? ''));
        $rows = app(AtlasBrainProvenanceLedger::class)->tail($scope, max($tail, 200));
        if ($filter !== '') {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => (string) ($r['source_finding'] ?? '') === $filter));
        }
        $rows = array_slice($rows, -$tail);

        $payload = ['scope' => $scope, 'count' => count($rows), 'source_finding_filter' => $filter !== '' ? $filter : null, 'rows' => $rows];

        if ($this->option('attribution')) {
            // Attribution uses the FULL recent window (not the filtered set).
            $payload['attribution'] = app(AtlasBrainProvenanceAttributionAnalyzer::class)->analyze(
                app(AtlasBrainProvenanceLedger::class)->tail($scope, max($tail, 200))
            );
        }

        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if (! $this->option('raw')) {
            $flags |= JSON_PRETTY_PRINT;
        }
        $this->line((string) json_encode($payload, $flags));

        return self::SUCCESS;
    }
}
