<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AtlasAurgEdge;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * SIS4 (Obra #20) — SQL as-of over the AURG edge graph. `AtlasAurgEdge::current($at)`
 * returns the relations that held at instant T (valid_from <= T, not yet expired
 * or superseded) — the SQL promotion of the bi-temporal model, degrade-safe.
 */
class AtlasAurgEdgesAsOfCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:aurg:edges-asof
        {--at= : ISO-8601 instant (default: now)}
        {--json : machine-readable output}';

    protected $description = 'AURG edges as-of a valid-time instant (SIS4 SQL as-of).';

    public function handle(): int
    {
        if (! Schema::hasTable('atlas_aurg_edges') || ! Schema::hasColumn('atlas_aurg_edges', 'valid_from')) {
            $payload = ['ok' => false, 'reason' => 'atlas_aurg_edges sem colunas temporal-truth (rode a migration)'];
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $at = $this->option('at') ? CarbonImmutable::parse((string) $this->option('at')) : CarbonImmutable::now();

        try {
            $asOf = AtlasAurgEdge::query()->current($at)->count();
            $total = AtlasAurgEdge::query()->count();
        } catch (Throwable $e) {
            $asOf = 0;
            $total = 0;
        }

        $payload = ['ok' => true, 'at' => $at->toIso8601String(), 'edges_as_of' => $asOf, 'edges_total' => $total];

        if ($this->option('json')) {
            $this->line($this->encode($payload));

            return self::SUCCESS;
        }

        $this->info(sprintf('[aurg:edges-asof] at=%s → %d/%d edges válidos', $payload['at'], $asOf, $total));

        return self::SUCCESS;
    }
}
