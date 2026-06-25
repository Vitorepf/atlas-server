<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\OperatorInterface;

/**
 * Pure FACTS-only envelope. Describes the EXCEPTIONAL emergency-safety actions the operator interface
 * may carry — pause / stop / rollback_request / resume_review — and explicitly REJECTS ordinary-progress
 * actions (task creation, queue progress, verification, merge). The Self-Construction interface stays
 * an emergency-and-visibility surface; routine progress is Atlas-native.
 *
 * Output:
 *   {schema_version, surface_role, accepted_action?, action_kind?, rejected_action?, rejection_reason?,
 *    safety_only: true}
 */
final class AtlasSelfConstructionEmergencyOverrideEnvelope
{
    public const SCHEMA = 'atlas.operator_interface.emergency_override.v1';

    public const SURFACE_ROLE = 'emergency_safety_only';

    public const ACTION_PAUSE = 'pause';

    public const ACTION_STOP = 'stop';

    public const ACTION_ROLLBACK_REQUEST = 'rollback_request';

    public const ACTION_RESUME_REVIEW = 'resume_review';

    public const ALLOWED_EMERGENCY_ACTIONS = [
        self::ACTION_PAUSE,
        self::ACTION_STOP,
        self::ACTION_ROLLBACK_REQUEST,
        self::ACTION_RESUME_REVIEW,
    ];

    public const REJECTED_ORDINARY_ACTIONS = [
        'create_task_packet',
        'progress_queue',
        'run_verification',
        'merge_to_main',
        'schedule_workers',
    ];

    /**
     * @return array<string,mixed>
     */
    public function envelopeFor(string $action): array
    {
        if (in_array($action, self::ALLOWED_EMERGENCY_ACTIONS, true)) {
            return [
                'schema_version' => self::SCHEMA,
                'surface_role' => self::SURFACE_ROLE,
                'accepted_action' => $action,
                'action_kind' => 'emergency_safety',
                'safety_only' => true,
            ];
        }
        if (in_array($action, self::REJECTED_ORDINARY_ACTIONS, true)) {
            return [
                'schema_version' => self::SCHEMA,
                'surface_role' => self::SURFACE_ROLE,
                'rejected_action' => $action,
                'rejection_reason' => 'ordinary_progress_must_stay_atlas_native',
                'safety_only' => true,
            ];
        }

        return [
            'schema_version' => self::SCHEMA,
            'surface_role' => self::SURFACE_ROLE,
            'rejected_action' => $action,
            'rejection_reason' => 'unknown_action_not_in_allowed_or_rejected_lists',
            'safety_only' => true,
        ];
    }
}
