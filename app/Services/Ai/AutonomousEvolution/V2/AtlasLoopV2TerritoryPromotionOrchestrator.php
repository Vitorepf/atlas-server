<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\V2;

use App\Services\Ai\AutonomousEvolution\AtlasLoopTerritoryLadder;
use DomainException;

final class AtlasLoopV2TerritoryPromotionOrchestrator
{
    public const SCHEMA_VERSION = 'atlas.ai.loop_v2.territory_promotion.v1';

    public function __construct(
        private readonly AtlasLoopV2ScopeManifestRegistry $registry,
        private readonly AtlasLoopTerritoryLadder $ladder,
        private readonly AtlasLoopV2AuditJournal $journal,
    ) {}

    /**
     * @param  array<string,mixed>  $territoryState
     * @return array{
     *     invariant_holds:bool,
     *     manifest_threshold:int,
     *     promotable:bool,
     *     promotion_rule_met:bool,
     *     schema_version:string,
     *     scope_id:string,
     *     violations:list<string>
     * }
     */
    public function evaluate(string $scopeId, array $territoryState): array
    {
        $scopeId = trim($scopeId);
        $manifest = $this->registry->findById($scopeId);

        if ($manifest === null) {
            $output = $this->output($scopeId, [
                'promotable' => false,
                'invariant_holds' => false,
                'promotion_rule_met' => false,
                'violations' => ['scope_manifest_missing:'.$scopeId],
            ], 0);
            $this->append($output);

            throw new DomainException('Loop V2 territory promotion scope_id not found: '.$scopeId);
        }

        $threshold = (int) $manifest['promotion_required_certified_leaps'];
        $verdict = $this->ladder->canPromote([
            'name' => (string) $manifest['id'],
            'discovery_roots' => (array) $manifest['discovery_roots'],
            'frozen_safety_files' => (array) $manifest['frozen_safety_files'],
            'robustness_cases' => (int) ($territoryState['robustness_cases'] ?? 0),
            'certified_leaps' => (int) ($territoryState['certified_leaps'] ?? 0),
            'red_main_in_window' => (int) ($territoryState['red_main_in_window'] ?? 0),
            'compounding_trend_up' => (bool) ($territoryState['compounding_trend_up'] ?? false),
        ], $threshold);

        $output = $this->output($scopeId, $verdict, $threshold);
        $this->append($output);

        return $output;
    }

    /**
     * @param  array<string,mixed>  $verdict
     * @return array{
     *     invariant_holds:bool,
     *     manifest_threshold:int,
     *     promotable:bool,
     *     promotion_rule_met:bool,
     *     schema_version:string,
     *     scope_id:string,
     *     violations:list<string>
     * }
     */
    private function output(string $scopeId, array $verdict, int $threshold): array
    {
        return [
            'invariant_holds' => (bool) ($verdict['invariant_holds'] ?? false),
            'manifest_threshold' => max(0, $threshold),
            'promotable' => (bool) ($verdict['promotable'] ?? false),
            'promotion_rule_met' => (bool) ($verdict['promotion_rule_met'] ?? false),
            'schema_version' => self::SCHEMA_VERSION,
            'scope_id' => $scopeId,
            'violations' => array_values(array_map(
                static fn (mixed $violation): string => (string) $violation,
                (array) ($verdict['violations'] ?? []),
            )),
        ];
    }

    /**
     * @param  array<string,mixed>  $output
     */
    private function append(array $output): void
    {
        $this->journal->append('territory_promotion_decision', $output);
    }
}
