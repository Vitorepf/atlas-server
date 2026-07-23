<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Repair planner for documentation drift: simplified code becomes confusing again — and gets
 * recomplicated by a future agent — if canonical docs still describe deleted structure or a
 * contract that no longer exists. This planner derives which doc repair actions a compression
 * wave requires — architecture docs, command docs, contract docs, knowledge map — and HOLDS
 * completion whenever a required repair action was never taken. A public contract change in
 * particular always requires a matching contract-doc repair action; completion never proceeds
 * on a public contract change with no doc repair to show for it.
 *
 * Input contract:
 *   architecture_docs_stale?:  bool
 *   command_docs_stale?:       bool
 *   contract_docs_stale?:      bool
 *   knowledge_map_stale?:      bool
 *   public_contract_changed?:  bool
 *   repair_actions_taken?:     list<string>  ('repair_architecture_docs'|'repair_command_docs'|'repair_contract_docs'|'repair_knowledge_map')
 *
 * public_contract_changed always pulls in repair_contract_docs, even when contract_docs_stale
 * was never explicitly flagged — a contract change is never invisible to the docs describing it.
 *
 * Pure PHP, deterministic, no I/O.
 */
final class AtlasExternalBrainDocumentationDriftRepairPlanner
{
    public const SCHEMA = 'atlas.external_brain.documentation_drift_repair_planner.v1';

    public const DECISION_COMPLETE = 'complete';
    public const DECISION_HOLD     = 'hold';

    public const REPAIR_ARCHITECTURE_DOCS = 'repair_architecture_docs';
    public const REPAIR_COMMAND_DOCS      = 'repair_command_docs';
    public const REPAIR_CONTRACT_DOCS     = 'repair_contract_docs';
    public const REPAIR_KNOWLEDGE_MAP     = 'repair_knowledge_map';

    /**
     * @param  array<string,mixed>  $facts
     * @return array{schema:string, decision:string, required_repair_actions:list<string>, missing_repair_actions:list<string>}
     */
    public function plan(array $facts): array
    {
        $architectureStale = (bool) ($facts['architecture_docs_stale'] ?? false);
        $commandStale       = (bool) ($facts['command_docs_stale'] ?? false);
        $contractStale      = (bool) ($facts['contract_docs_stale'] ?? false);
        $knowledgeMapStale  = (bool) ($facts['knowledge_map_stale'] ?? false);
        $publicContractChanged = (bool) ($facts['public_contract_changed'] ?? false);
        $taken = array_values(array_unique(array_filter(array_map('strval', (array) ($facts['repair_actions_taken'] ?? [])))));

        $required = [];

        if ($architectureStale) {
            $required[] = self::REPAIR_ARCHITECTURE_DOCS;
        }
        if ($commandStale) {
            $required[] = self::REPAIR_COMMAND_DOCS;
        }
        if ($contractStale || $publicContractChanged) {
            $required[] = self::REPAIR_CONTRACT_DOCS;
        }
        if ($knowledgeMapStale) {
            $required[] = self::REPAIR_KNOWLEDGE_MAP;
        }

        $required = array_values(array_unique($required));
        $missing  = array_values(array_diff($required, $taken));

        return [
            'schema'                    => self::SCHEMA,
            'decision'                  => $missing === [] ? self::DECISION_COMPLETE : self::DECISION_HOLD,
            'required_repair_actions'   => $required,
            'missing_repair_actions'    => $missing,
        ];
    }
}
