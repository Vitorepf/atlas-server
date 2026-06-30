<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Knowledge;

/**
 * Decides the minimum documentation, memory, code-index, and context-pack refresh actions
 * required after each task batch so the next originator round operates on current system truth.
 *
 * REFRESH TRIGGERS:
 *   refresh_code_index        — changed_files non-empty AND code_index_freshness_seconds > CODE_INDEX_STALE_THRESHOLD
 *   sync_docs                 — docs_touched non-empty AND memory_writes empty
 *   capture_give_backs        — give_back_count >= GIVE_BACK_THRESHOLD AND NOT give_backs_captured_in_learning
 *   refresh_context_pack      — context_pack_age_seconds > CONTEXT_PACK_STALE_THRESHOLD (advisory only)
 *
 * next_originator_context_ready = false when ANY of the first three triggers fire.
 * Context-pack age alone is advisory and does NOT block readiness.
 *
 * INPUT:
 *   changed_files:                     list<string>  (default [])
 *   docs_touched:                      list<string>  (default [])
 *   memory_writes:                     list<string>  (default [])
 *   code_index_freshness_seconds:      int           (default 0)
 *   context_pack_age_seconds:          int           (default 0)
 *   give_back_count:                   int           (default 0)
 *   give_backs_captured_in_learning:   bool          (default false)
 *
 * OUTPUT:
 *   { schema, refresh_actions, skipped_actions, next_originator_context_ready, not_ready_reasons }
 *
 *   refresh_actions: list<{ action_id, reason, command }>
 *   skipped_actions: list<{ action_id, reason }>
 *   not_ready_reasons: list<string>
 *
 * PURE / DETERMINISTIC / NO I/O.
 */
final class AtlasSelfConstructionKnowledgeDominanceLoopPlanner
{
    public const SCHEMA = 'atlas.self_construction.knowledge.dominance_loop_planner.v1';

    public const ACTION_REFRESH_CODE_INDEX   = 'refresh_code_index';
    public const ACTION_SYNC_DOCS            = 'sync_docs';
    public const ACTION_CAPTURE_GIVE_BACKS   = 'capture_give_backs_to_memory';
    public const ACTION_REFRESH_CONTEXT_PACK = 'refresh_context_pack';

    private const CODE_INDEX_STALE_THRESHOLD    = 300;   // seconds
    private const CONTEXT_PACK_STALE_THRESHOLD  = 3600;  // seconds
    private const GIVE_BACK_THRESHOLD           = 2;

    private const COMMANDS = [
        self::ACTION_REFRESH_CODE_INDEX   => 'atlas engineering knowledge index-code --prune',
        self::ACTION_SYNC_DOCS            => 'atlas engineering knowledge sync --prune',
        self::ACTION_CAPTURE_GIVE_BACKS   => 'atlas memory record give_back_learning',
        self::ACTION_REFRESH_CONTEXT_PACK => 'atlas context-pack refresh',
    ];

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function plan(array $input): array
    {
        $changedFiles              = is_array($input['changed_files'] ?? null) ? $input['changed_files'] : [];
        $docsTouched               = is_array($input['docs_touched'] ?? null) ? $input['docs_touched'] : [];
        $memoryWrites              = is_array($input['memory_writes'] ?? null) ? $input['memory_writes'] : [];
        $codeIndexFreshness        = max(0, (int) ($input['code_index_freshness_seconds'] ?? 0));
        $contextPackAge            = max(0, (int) ($input['context_pack_age_seconds'] ?? 0));
        $giveBackCount             = max(0, (int) ($input['give_back_count'] ?? 0));
        $giveBacksCaptured         = (bool) ($input['give_backs_captured_in_learning'] ?? false);

        $refreshActions  = [];
        $skippedActions  = [];
        $notReadyReasons = [];

        // 1. Code index refresh.
        if ($changedFiles !== [] && $codeIndexFreshness > self::CODE_INDEX_STALE_THRESHOLD) {
            $refreshActions[] = [
                'action_id' => self::ACTION_REFRESH_CODE_INDEX,
                'reason'    => sprintf(
                    '%d file(s) changed and code index is %ds stale (threshold=%ds)',
                    count($changedFiles),
                    $codeIndexFreshness,
                    self::CODE_INDEX_STALE_THRESHOLD,
                ),
                'command'   => self::COMMANDS[self::ACTION_REFRESH_CODE_INDEX],
            ];
            $notReadyReasons[] = 'code_changed_without_index_refresh';
        } elseif ($changedFiles === []) {
            $skippedActions[] = [
                'action_id' => self::ACTION_REFRESH_CODE_INDEX,
                'reason'    => 'no files changed in this batch',
            ];
        } else {
            $skippedActions[] = [
                'action_id' => self::ACTION_REFRESH_CODE_INDEX,
                'reason'    => sprintf('code index is fresh (%ds <= threshold %ds)', $codeIndexFreshness, self::CODE_INDEX_STALE_THRESHOLD),
            ];
        }

        // 2. Docs sync.
        if ($docsTouched !== [] && $memoryWrites === []) {
            $refreshActions[] = [
                'action_id' => self::ACTION_SYNC_DOCS,
                'reason'    => sprintf('%d doc(s) touched but no memory_writes recorded as sync evidence', count($docsTouched)),
                'command'   => self::COMMANDS[self::ACTION_SYNC_DOCS],
            ];
            $notReadyReasons[] = 'docs_changed_without_sync_evidence';
        } elseif ($docsTouched === []) {
            $skippedActions[] = [
                'action_id' => self::ACTION_SYNC_DOCS,
                'reason'    => 'no docs touched in this batch',
            ];
        } else {
            $skippedActions[] = [
                'action_id' => self::ACTION_SYNC_DOCS,
                'reason'    => 'docs sync already evidenced by memory_writes',
            ];
        }

        // 3. Capture give_backs to learning memory.
        if ($giveBackCount >= self::GIVE_BACK_THRESHOLD && ! $giveBacksCaptured) {
            $refreshActions[] = [
                'action_id' => self::ACTION_CAPTURE_GIVE_BACKS,
                'reason'    => sprintf(
                    '%d give_back(s) in batch not captured into learning memory',
                    $giveBackCount,
                ),
                'command'   => self::COMMANDS[self::ACTION_CAPTURE_GIVE_BACKS],
            ];
            $notReadyReasons[] = 'give_backs_not_captured_in_learning';
        } elseif ($giveBackCount < self::GIVE_BACK_THRESHOLD) {
            $skippedActions[] = [
                'action_id' => self::ACTION_CAPTURE_GIVE_BACKS,
                'reason'    => sprintf('give_back_count=%d < threshold=%d', $giveBackCount, self::GIVE_BACK_THRESHOLD),
            ];
        } else {
            $skippedActions[] = [
                'action_id' => self::ACTION_CAPTURE_GIVE_BACKS,
                'reason'    => 'give_backs already captured in learning memory',
            ];
        }

        // 4. Context pack refresh (advisory — does NOT block next_originator_context_ready).
        if ($contextPackAge > self::CONTEXT_PACK_STALE_THRESHOLD) {
            $refreshActions[] = [
                'action_id' => self::ACTION_REFRESH_CONTEXT_PACK,
                'reason'    => sprintf('context_pack_age=%ds > threshold=%ds (advisory)', $contextPackAge, self::CONTEXT_PACK_STALE_THRESHOLD),
                'command'   => self::COMMANDS[self::ACTION_REFRESH_CONTEXT_PACK],
            ];
        } else {
            $skippedActions[] = [
                'action_id' => self::ACTION_REFRESH_CONTEXT_PACK,
                'reason'    => sprintf('context pack is fresh (%ds <= threshold %ds)', $contextPackAge, self::CONTEXT_PACK_STALE_THRESHOLD),
            ];
        }

        return [
            'schema'                          => self::SCHEMA,
            'refresh_actions'                 => $refreshActions,
            'skipped_actions'                 => $skippedActions,
            'next_originator_context_ready'   => $notReadyReasons === [],
            'not_ready_reasons'               => $notReadyReasons,
        ];
    }
}
