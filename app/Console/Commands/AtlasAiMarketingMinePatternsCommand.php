<?php

namespace App\Console\Commands;

use App\Services\Ai\MarketingDomain\Patterns\NivorWinningPatternMiner;
use Illuminate\Console\Command;
use Throwable;

class AtlasAiMarketingMinePatternsCommand extends Command
{
    protected $signature = 'atlas:ai:marketing:mine-patterns
        {--niche= : Mine a single niche (default: all)}
        {--json : Machine-readable JSON output}';

    protected $description = 'Mine the Nivor/Blackink tracker (read-only) for winning patterns per niche: real CVR, converting keywords, winning pages.';

    public function handle(NivorWinningPatternMiner $miner): int
    {
        try {
            $patterns = ($niche = trim((string) $this->option('niche'))) !== ''
                ? array_filter([$miner->mineNiche($niche)])
                : $miner->mineAll();

            if ($patterns === []) {
                return $this->respondError('Nenhum padrão minerado (nicho sem campanhas com venda, ou Nivor indisponível).');
            }

            if ((bool) $this->option('json')) {
                $this->line(json_encode([
                    'ok' => true,
                    'patterns' => array_map(static fn ($p) => $p->toArray(), $patterns),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

                return self::SUCCESS;
            }

            foreach ($patterns as $p) {
                $cvr = $p->cvr_stats['robust_cvr_pct'] ?? $p->cvr_stats['blended_cvr_pct'] ?? '?';
                $this->components->twoColumnDetail(
                    $p->niche." ({$p->campaigns_count} camp · {$p->sales_total} vendas)",
                    "CVR {$cvr}% · ".count((array) $p->converting_keywords).' keywords · '.count((array) $p->winning_funnels).' funis',
                );
            }
            $this->components->info(count($patterns).' nicho(s) minerado(s) do Nivor (read-only).');

            return self::SUCCESS;
        } catch (Throwable $e) {
            return $this->respondError($e->getMessage(), $e::class);
        }
    }

    private function respondError(string $message, ?string $type = null): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode(array_filter(['ok' => false, 'error' => $message, 'type' => $type])) ?: '{}');
        } else {
            $this->components->error($message);
        }

        return self::FAILURE;
    }
}
