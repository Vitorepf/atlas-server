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
 * Arms the dormant {@see AtlasLoopV2TerritoryPromotionOrchestrator::evaluate()} at the operator surface: reads a
 * scope's full territory state (robustness_cases, certified_leaps, red_main_in_window, compounding_trend_up)
 * from a JSON file and emits the deterministic promotion verdict (promotable, invariant_holds,
 * promotion_rule_met, manifest_threshold, violations).
 *
 * ADVISORY only: it reports the verdict and NEVER promotes a scope, advances a ladder, or mutates the queue/git
 * — the only write is evaluate()'s own audit-journal append. A missing scope manifest surfaces the advisory
 * scope_manifest_missing verdict rather than throwing.
 */
final class AtlasLoopV2TerritoryPromotionCommand extends Command
{
    protected $signature = 'atlas:loop:v2-territory-promotion {--scope=} {--input=} {--json}';

    protected $description = 'Read-only V2 territory-promotion verdict for a scope given its territory state.';

    public function handle(): int
    {
        $scope = trim((string) $this->option('scope'));
        if ($scope === '') {
            return $this->refuse('v2-territory-promotion requires --scope=<id>');
        }

        $input = trim((string) $this->option('input'));
        if ($input === '' || ! is_file($input) || ! is_readable($input)) {
            return $this->refuse('v2-territory-promotion requires --input=<path to a readable territory-state JSON>');
        }
        $state = json_decode((string) file_get_contents($input), true);
        if (! is_array($state)) {
            return $this->refuse('--input must be a JSON object');
        }

        $territoryState = [
            'robustness_cases' => max(0, (int) ($state['robustness_cases'] ?? 0)),
            'certified_leaps' => max(0, (int) ($state['certified_leaps'] ?? 0)),
            'red_main_in_window' => max(0, (int) ($state['red_main_in_window'] ?? 0)),
            'compounding_trend_up' => (bool) ($state['compounding_trend_up'] ?? false),
        ];

        try {
            $facts = $this->orchestrator()->evaluate($scope, $territoryState);
        } catch (DomainException) {
            // A missing manifest is journaled then thrown by evaluate(); surface the advisory verdict.
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

    private function refuse(string $message): int
    {
        $this->line((string) json_encode([
            'outcome' => 'refused',
            'reason' => 'usage_error',
            'message' => $message,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::FAILURE;
    }
}
