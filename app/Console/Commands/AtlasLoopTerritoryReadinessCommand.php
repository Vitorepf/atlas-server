<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopTerritoryLadder;
use App\Services\Ai\AutonomousEvolution\V2\AtlasLoopV2AuditJournal;
use App\Services\Ai\AutonomousEvolution\V2\AtlasLoopV2ScopeManifestRegistry;
use App\Services\Ai\AutonomousEvolution\V2\AtlasLoopV2TerritoryPromotionOrchestrator;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Arms the dormant {@see AtlasLoopV2TerritoryPromotionOrchestrator::evaluate()} at the operator surface: emits
 * the deterministic scope-promotion readiness facts (promotable, invariant_holds, promotion_rule_met,
 * violations) for a scope given its certified-leap / robustness-case counts.
 *
 * ADVISORY only: it reports readiness and NEVER promotes a scope, advances a trust ladder, or mutates the queue/
 * git — the only write is evaluate()'s own audit-journal append. The readiness projection assumes a clean main
 * (red_main_in_window=0) and an upward compounding trend, so it answers "do your counts clear the manifest bar?".
 */
final class AtlasLoopTerritoryReadinessCommand extends Command
{
    protected $signature = 'atlas:loop:territory-readiness {--scope=} {--robustness-cases=0} {--certified-leaps=0} {--json}';

    protected $description = 'Read-only scope-promotion readiness check (promotable? invariant + promotion rule).';

    public function handle(): int
    {
        $scope = trim((string) $this->option('scope'));
        if ($scope === '') {
            $this->line((string) json_encode([
                'outcome' => 'refused',
                'reason' => 'usage_error',
                'message' => 'territory-readiness requires --scope=<id>',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $territoryState = [
            'robustness_cases' => max(0, (int) $this->option('robustness-cases')),
            'certified_leaps' => max(0, (int) $this->option('certified-leaps')),
            'red_main_in_window' => 0,
            'compounding_trend_up' => true,
        ];

        try {
            $facts = $this->orchestrator()->evaluate($scope, $territoryState);
        } catch (DomainException) {
            // A missing manifest is journaled then thrown by evaluate(); surface the advisory missing-manifest verdict.
            $facts = [
                'schema_version' => AtlasLoopV2TerritoryPromotionOrchestrator::SCHEMA_VERSION,
                'scope_id' => $scope,
                'promotable' => false,
                'invariant_holds' => false,
                'promotion_rule_met' => false,
                'manifest_threshold' => 0,
                'violations' => ['scope_manifest_missing:'.$scope],
            ];
        }

        $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    private function orchestrator(): AtlasLoopV2TerritoryPromotionOrchestrator
    {
        $app = $this->getLaravel();
        if ($app->bound(AtlasLoopV2TerritoryPromotionOrchestrator::class)) {
            return $app->make(AtlasLoopV2TerritoryPromotionOrchestrator::class);
        }

        $path = (string) config('atlas.loop.v2.territory_journal_path', storage_path('atlas/loop/v2/territory-promotion.jsonl'));
        File::ensureDirectoryExists(dirname($path));

        return new AtlasLoopV2TerritoryPromotionOrchestrator(
            $app->make(AtlasLoopV2ScopeManifestRegistry::class),
            $app->make(AtlasLoopTerritoryLadder::class),
            new AtlasLoopV2AuditJournal($path),
        );
    }
}
