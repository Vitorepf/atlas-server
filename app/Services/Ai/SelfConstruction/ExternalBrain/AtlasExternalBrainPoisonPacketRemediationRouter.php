<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure router that maps malformed sweep and quarantine reasons into
 * repair, retire, give_back, or keep_blocked actions so the originator
 * creates fixes instead of requeueing poison.
 *
 * Routing rules:
 *   missing_scope / missing_objective / missing_allowed_files → repair
 *   forbidden_self_target / forbidden_axis → retire
 *   duplicate_satisfied_work / already_completed → give_back
 *   active_protected_governance / operator_hold → keep_blocked
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainPoisonPacketRemediationRouter
{
    public const SCHEMA = 'atlas.external_brain.poison_packet_remediation_router.v1';

    public const ACTION_REPAIR = 'repair';
    public const ACTION_RETIRE = 'retire';
    public const ACTION_GIVE_BACK = 'give_back';
    public const ACTION_KEEP_BLOCKED = 'keep_blocked';

    private const REPAIR_REASONS = [
        'missing_scope',
        'missing_objective',
        'missing_allowed_files',
        'missing_acceptance_criteria',
        'missing_required_evidence',
        'incomplete_spec',
    ];

    private const RETIRE_REASONS = [
        'forbidden_self_target',
        'forbidden_axis',
        'forbidden_files_in_allowed',
        'self_programming_attempt',
    ];

    private const GIVE_BACK_REASONS = [
        'duplicate_satisfied_work',
        'already_completed',
        'duplicate_target',
    ];

    private const KEEP_BLOCKED_REASONS = [
        'active_protected_governance',
        'operator_hold',
        'quarantined_pattern',
        'poison_ratio_exceeded',
    ];

    /**
     * @param  array<string, mixed>  $packet
     * @return array<string, mixed>
     */
    public function route(array $packet): array
    {
        $reason = strtolower(trim((string) ($packet['reason'] ?? '')));
        $taskId = (string) ($packet['task_id'] ?? $packet['task_packet_id'] ?? '');

        $action = match (true) {
            in_array($reason, self::REPAIR_REASONS, true) => self::ACTION_REPAIR,
            in_array($reason, self::RETIRE_REASONS, true) => self::ACTION_RETIRE,
            in_array($reason, self::GIVE_BACK_REASONS, true) => self::ACTION_GIVE_BACK,
            in_array($reason, self::KEEP_BLOCKED_REASONS, true) => self::ACTION_KEEP_BLOCKED,
            default => self::ACTION_KEEP_BLOCKED,
        };

        $remediationGuidance = match ($action) {
            self::ACTION_REPAIR => 'originator_should_fix_spec_and_requeue',
            self::ACTION_RETIRE => 'originator_should_not_requeue_retire_permanently',
            self::ACTION_GIVE_BACK => 'originator_should_give_back_already_satisfied',
            self::ACTION_KEEP_BLOCKED => 'keep_blocked_awaiting_governance_resolution',
            default => 'investigate',
        };

        return [
            'schema_version' => self::SCHEMA,
            'task_id' => $taskId,
            'reason' => $reason,
            'action' => $action,
            'remediation_guidance' => $remediationGuidance,
            'requeue_allowed' => $action === self::ACTION_REPAIR,
        ];
    }

    /**
     * Route a batch of poison packets.
     *
     * @param  array<int, array<string, mixed>>  $packets
     * @return array<string, mixed>
     */
    public function routeBatch(array $packets): array
    {
        $routed = [];
        foreach ($packets as $packet) {
            if (! is_array($packet)) {
                continue;
            }
            $routed[] = $this->route($packet);
        }

        $actionCounts = [];
        foreach ($routed as $r) {
            $action = $r['action'];
            $actionCounts[$action] = ($actionCounts[$action] ?? 0) + 1;
        }

        return [
            'schema_version' => self::SCHEMA,
            'routed' => $routed,
            'action_counts' => $actionCounts,
            'total' => count($routed),
        ];
    }
}
