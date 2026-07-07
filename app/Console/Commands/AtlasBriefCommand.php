<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Obra\AtlasDeterministicBriefService;
use Illuminate\Console\Command;

/**
 * WO-17-T2 — generate / show the DETERMINISTIC brief (v1, no LLM).
 *
 *   atlas:brief --generate            # rebuild the brief from git churn + registry
 *   atlas:brief                       # show the current brief + its freshness
 *
 * The pack reads the persisted brief and shows "BRIEF STALE desde X" the moment HEAD
 * moves past it — never a silent stale brief.
 */
final class AtlasBriefCommand extends Command
{
    protected $signature = 'atlas:brief
        {--generate : (re)build + persist the brief from git churn + the registry}
        {--workspace= : scope id (default: atlas-server)}
        {--json : machine-readable output}';

    protected $description = 'Generate or show the deterministic brief (churn + invariants + refutations + HEAD) for a scope.';

    public function handle(AtlasDeterministicBriefService $service): int
    {
        $scope = $this->scope();

        $brief = (bool) $this->option('generate')
            ? $service->generate($scope)
            : $service->read($scope);

        if ($brief === null) {
            $this->warn("Nenhum brief para '{$scope}'. Rode: php artisan atlas:brief --generate");

            return self::SUCCESS;
        }

        $staleness = $service->staleness($brief);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(['brief' => $brief, 'staleness' => $staleness], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info("Brief '{$scope}'  head={$brief['head']}  gerado={$brief['generated_at']}");
        if ($staleness['stale']) {
            $this->warn("BRIEF STALE desde {$staleness['generated_at']} (HEAD {$staleness['brief_head']} → {$staleness['current_head']}) — rode --generate");
        }
        $this->line('módulos quentes: '.implode(', ', array_map(static fn (array $m): string => $m['module'].'('.$m['changes'].')', array_slice((array) $brief['modules'], 0, 5))));
        $this->line('invariantes: '.count((array) $brief['invariants']).'  refutações: '.count((array) $brief['refutations']));

        return self::SUCCESS;
    }

    private function scope(): string
    {
        $ws = trim((string) $this->option('workspace'));
        if ($ws === '') {
            return 'atlas-server';
        }

        return str_contains($ws, '/') ? basename($ws) : $ws;
    }
}
