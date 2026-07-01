<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Knowledge-sync completion gate: a compression wave changes the system's shape — code shrank,
 * organs merged, architecture labels moved — so it is never "done" just because tests are green.
 * This planner derives which knowledge surfaces (docs, memory, code index, domain map) a wave
 * touched and emits the required sync actions; if any required action was never taken, or the
 * wave changed public behavior / architecture labels without taking ANY sync action, completion
 * is HELD instead of silently letting knowledge drift from the simplified code.
 *
 * Input contract:
 *   docs_changed?:                 bool
 *   memory_entries_affected?:      list<string>
 *   code_index_stale?:             bool
 *   domain_map_affected?:          list<string>
 *   public_behavior_changed?:      bool
 *   architecture_labels_changed?:  bool
 *   sync_actions_taken?:           list<string>  ('sync_docs'|'sync_memory'|'sync_code_index'|'sync_domain_map')
 *
 * public_behavior_changed / architecture_labels_changed always pull in sync_docs and
 * sync_code_index — a behavior or architecture shift is never invisible to docs or code
 * intelligence, even when the caller forgot to flag docs_changed / code_index_stale directly.
 *
 * Pure PHP, deterministic, no I/O.
 */
final class AtlasExternalBrainSimplificationKnowledgeSyncPlanner
{
    public const SCHEMA = 'atlas.external_brain.simplification_knowledge_sync_planner.v1';

    public const DECISION_COMPLETE = 'complete';
    public const DECISION_HOLD     = 'hold';

    public const SYNC_DOCS        = 'sync_docs';
    public const SYNC_MEMORY      = 'sync_memory';
    public const SYNC_CODE_INDEX  = 'sync_code_index';
    public const SYNC_DOMAIN_MAP  = 'sync_domain_map';

    /**
     * @param  array<string,mixed>  $facts
     * @return array{schema:string, decision:string, required_sync_actions:list<string>, missing_sync_actions:list<string>}
     */
    public function plan(array $facts): array
    {
        $docsChanged      = (bool) ($facts['docs_changed'] ?? false);
        $memoryAffected   = array_values(array_filter(array_map('strval', (array) ($facts['memory_entries_affected'] ?? []))));
        $codeIndexStale   = (bool) ($facts['code_index_stale'] ?? false);
        $domainMapAffected = array_values(array_filter(array_map('strval', (array) ($facts['domain_map_affected'] ?? []))));
        $publicBehaviorChanged = (bool) ($facts['public_behavior_changed'] ?? false);
        $architectureChanged   = (bool) ($facts['architecture_labels_changed'] ?? false);
        $taken = array_values(array_unique(array_filter(array_map('strval', (array) ($facts['sync_actions_taken'] ?? [])))));

        $required = [];

        if ($docsChanged || $publicBehaviorChanged || $architectureChanged) {
            $required[] = self::SYNC_DOCS;
        }
        if ($memoryAffected !== []) {
            $required[] = self::SYNC_MEMORY;
        }
        if ($codeIndexStale || $publicBehaviorChanged || $architectureChanged) {
            $required[] = self::SYNC_CODE_INDEX;
        }
        if ($domainMapAffected !== [] || $architectureChanged) {
            $required[] = self::SYNC_DOMAIN_MAP;
        }

        $required = array_values(array_unique($required));
        $missing  = array_values(array_diff($required, $taken));

        return [
            'schema'                 => self::SCHEMA,
            'decision'               => $missing === [] ? self::DECISION_COMPLETE : self::DECISION_HOLD,
            'required_sync_actions'  => $required,
            'missing_sync_actions'   => $missing,
        ];
    }
}
