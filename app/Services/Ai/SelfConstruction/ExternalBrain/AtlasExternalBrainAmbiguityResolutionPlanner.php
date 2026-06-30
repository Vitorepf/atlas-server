<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure ambiguity resolution planner. Converts uncertain model claims into
 * deterministic local checks rather than letting them become speculative specs.
 *
 * Each ambiguity_item is classified into an action_type (first match wins):
 *   local_grep_check       — has grep_pattern or mentions a symbol name
 *   target_existence_check — has target_path (file/class to verify)
 *   queue_collision_check  — has task_class/task_family to check for queued duplicate
 *   evidence_replay        — has prior_evidence_id to re-validate
 *   explicit_escalation    — ambiguity too high for local resolution; requires frontier
 *
 * An item is UNRESOLVED when:
 *   - action_type=explicit_escalation (no local check possible), OR
 *   - ambiguity_score >= UNRESOLVABLE_THRESHOLD and no specific check fields present
 *
 * AC3: task_creation_allowed = false when any unresolved item exists.
 *
 * AC4: output always includes ambiguity_items (echoed), resolution_actions,
 *      unresolved_items, and task_creation_allowed.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainAmbiguityResolutionPlanner
{
    public const SCHEMA = 'atlas.external_brain.ambiguity_resolution_planner.v1';

    public const ACTION_LOCAL_GREP          = 'local_grep_check';
    public const ACTION_TARGET_EXISTENCE    = 'target_existence_check';
    public const ACTION_QUEUE_COLLISION     = 'queue_collision_check';
    public const ACTION_EVIDENCE_REPLAY     = 'evidence_replay';
    public const ACTION_EXPLICIT_ESCALATION = 'explicit_escalation';
    public const ACTION_CAPABILITY_CLAIM    = 'capability_claim_check';

    private const UNRESOLVABLE_THRESHOLD = 0.80;

    /**
     * @param  array{ambiguity_items?: list<array<string,mixed>>}  $input
     * @return array{schema:string, ambiguity_items:list<array<string,mixed>>, resolution_actions:list<array<string,mixed>>, unresolved_items:list<array<string,mixed>>, task_creation_allowed:bool}
     */
    public function plan(array $input): array
    {
        $items = (array) ($input['ambiguity_items'] ?? []);

        $resolutionActions = [];
        $unresolvedItems   = [];

        foreach ($items as $idx => $item) {
            $itemId         = (string) ($item['item_id']        ?? "item_{$idx}");
            $claim          = (string) ($item['claim']          ?? '');
            $ambiguityScore = max(0.0, min(1.0, (float) ($item['ambiguity_score'] ?? 0.0)));

            $actionType    = $this->resolveActionType($item, $ambiguityScore);
            $enqueueBlocking = $this->isUnresolved($actionType, $ambiguityScore, $item);

            $action = [
                'item_id'     => $itemId,
                'action_type' => $actionType,
                'claim'       => $claim,
            ];

            $detail = $this->actionDetail($actionType, $item);
            $action = array_merge($action, $detail, [
                'local_command_or_gate' => $detail['check_command'] ?? 'operator_or_frontier_review_gate',
                'expected_evidence'     => $this->expectedEvidence($actionType),
                'enqueue_blocking'      => $enqueueBlocking,
            ]);
            $resolutionActions[] = $action;

            if ($enqueueBlocking) {
                $unresolvedItems[] = [
                    'item_id'         => $itemId,
                    'claim'           => $claim,
                    'ambiguity_score' => $ambiguityScore,
                    'reason'          => $actionType === self::ACTION_EXPLICIT_ESCALATION
                        ? 'no_local_check_can_reduce_ambiguity'
                        : 'ambiguity_too_high_without_specific_check_fields',
                ];
            }
        }

        return [
            'schema'                 => self::SCHEMA,
            'ambiguity_items'        => $items,
            'resolution_actions'     => $resolutionActions,
            'unresolved_items'       => $unresolvedItems,
            'task_creation_allowed'  => $unresolvedItems === [],
        ];
    }

    private function resolveActionType(array $item, float $ambiguityScore): string
    {
        // 1. Evidence replay — has prior evidence reference
        if (! empty($item['prior_evidence_id'])) {
            return self::ACTION_EVIDENCE_REPLAY;
        }

        // 2. Queue collision — has task family to check
        if (! empty($item['task_class']) || ! empty($item['task_family'])) {
            return self::ACTION_QUEUE_COLLISION;
        }

        // 3. Target existence — has file/class path to verify
        if (! empty($item['target_path'])) {
            return self::ACTION_TARGET_EXISTENCE;
        }

        // 4. Local grep — has grep pattern or symbol name
        if (! empty($item['grep_pattern']) || ! empty($item['symbol_name'])) {
            return self::ACTION_LOCAL_GREP;
        }

        // 5. Capability claim — verify claimed capability exists in local codebase
        if (! empty($item['capability_claim'])) {
            return self::ACTION_CAPABILITY_CLAIM;
        }

        // 6. Explicit escalation — nothing local can resolve
        return self::ACTION_EXPLICIT_ESCALATION;
    }

    private function isUnresolved(string $actionType, float $ambiguityScore, array $item): bool
    {
        if ($actionType === self::ACTION_EXPLICIT_ESCALATION) {
            return true;
        }

        // High ambiguity with only a grep/existence check might still be resolvable;
        // only flag unresolved when nothing specific is present
        if ($ambiguityScore >= self::UNRESOLVABLE_THRESHOLD
            && empty($item['grep_pattern'])
            && empty($item['symbol_name'])
            && empty($item['target_path'])
            && empty($item['task_class'])
            && empty($item['task_family'])
            && empty($item['prior_evidence_id'])
            && empty($item['capability_claim'])
        ) {
            return true;
        }

        return false;
    }

    private function expectedEvidence(string $actionType): string
    {
        return match ($actionType) {
            self::ACTION_LOCAL_GREP          => 'grep_match_or_confirmed_absence',
            self::ACTION_TARGET_EXISTENCE    => 'file_existence_confirmed_or_refuted',
            self::ACTION_QUEUE_COLLISION     => 'duplicate_check_result',
            self::ACTION_EVIDENCE_REPLAY     => 'replayed_evidence_payload',
            self::ACTION_CAPABILITY_CLAIM    => 'capability_grep_result',
            self::ACTION_EXPLICIT_ESCALATION => 'frontier_or_operator_decision',
        };
    }

    /** @return array<string,mixed> */
    private function actionDetail(string $actionType, array $item): array
    {
        return match ($actionType) {
            self::ACTION_LOCAL_GREP         => ['check_command' => 'grep -r '.($item['grep_pattern'] ?? $item['symbol_name'] ?? '?').' app/'],
            self::ACTION_TARGET_EXISTENCE   => ['check_command' => 'test -f '.($item['target_path'] ?? '?')],
            self::ACTION_QUEUE_COLLISION    => ['check_command' => 'atlas:task:check-duplicate --class='.($item['task_class'] ?? $item['task_family'] ?? '?')],
            self::ACTION_EVIDENCE_REPLAY    => ['check_command' => 'atlas:evidence:replay --id='.($item['prior_evidence_id'] ?? '?')],
            self::ACTION_CAPABILITY_CLAIM    => ['check_command' => 'grep -rli '.($item['capability_claim'] ?? '?').' app/'],
            self::ACTION_EXPLICIT_ESCALATION => ['check_command' => null, 'escalation_note' => 'require frontier model or operator review before proceeding'],
        };
    }
}
