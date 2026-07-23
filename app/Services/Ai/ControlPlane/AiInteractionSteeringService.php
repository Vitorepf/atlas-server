<?php

namespace App\Services\Ai\ControlPlane;

use App\Models\AiJob;
use App\Models\AiTrace;
use App\Services\Ai\ConversationOps\AiSessionStateService;
use App\Services\Ai\Streaming\AiStreamRecorder;

class AiInteractionSteeringService
{
    public const RESPONSE_SCHEMA = 'atlas.ai.interaction_steer.v1';
    public const EVENT_SCHEMA = 'atlas.ai.interaction_steer_event.v1';

    /** @var list<string> */
    private const ALLOWED_SCOPES = ['current_step', 'replan'];

    public function __construct(
        private readonly AiSessionStateService $states,
        private readonly AiStreamRecorder $stream,
    ) {}

    /**
     * @return array{status:int, body:array<string,mixed>}
     */
    public function steer(AiTrace $trace, mixed $instruction, mixed $scope): array
    {
        $job = $this->latestJobForTrace($trace);
        $thread = $trace->thread()->first();
        $session = $trace->session()->first();
        $instruction = is_string($instruction) ? trim($instruction) : '';
        $scope = is_string($scope) ? trim($scope) : '';

        if ($instruction === '') {
            return $this->reject($trace, $job, 'instruction_required');
        }

        if (! in_array($scope, self::ALLOWED_SCOPES, true)) {
            return $this->reject($trace, $job, 'invalid_scope', [
                'allowed_scopes' => self::ALLOWED_SCOPES,
            ]);
        }

        if (! $thread) {
            return $this->reject($trace, $job, 'trace_without_thread');
        }

        if (! $job || ! in_array($job->status, ['queued', 'processing'], true)) {
            return $this->reject($trace, $job, 'no_active_job');
        }

        $state = $this->states->setPendingSteer((string) $thread->id, $instruction, $session?->id);
        $state->forceFill([
            'provider_context' => array_merge($state->provider_context ?? [], [
                'pending_steer_scope' => $scope,
            ]),
            'metadata' => array_merge($state->metadata ?? [], [
                'steering_contract' => [
                    'schema_version' => self::RESPONSE_SCHEMA,
                    'scope' => $scope,
                    'delivery_status' => 'queued_for_next_safe_checkpoint',
                    'safe_checkpoint' => 'before_next_provider_call',
                    'recorded_at' => now()->toJSON(),
                ],
            ]),
        ])->save();

        $this->recordPublicEvent($job, 'steering_accepted', [
            'scope' => $scope,
            'delivery_status' => 'queued_for_next_safe_checkpoint',
            'safe_checkpoint' => 'before_next_provider_call',
        ]);

        return [
            'status' => 202,
            'body' => [
                'schema_version' => self::RESPONSE_SCHEMA,
                'status' => 'accepted',
                'event' => 'steering_accepted',
                'trace_id' => (string) $trace->id,
                'scope' => $scope,
                'delivery' => [
                    'status' => 'queued_for_next_safe_checkpoint',
                    'safe_checkpoint' => 'before_next_provider_call',
                ],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array{status:int, body:array<string,mixed>}
     */
    private function reject(AiTrace $trace, ?AiJob $job, string $reason, array $extra = []): array
    {
        if ($job) {
            $this->recordPublicEvent($job, 'steering_rejected', array_merge([
                'reason' => $reason,
            ], $extra));
        }

        return [
            'status' => 422,
            'body' => array_merge([
                'schema_version' => self::RESPONSE_SCHEMA,
                'status' => 'rejected',
                'event' => 'steering_rejected',
                'trace_id' => (string) $trace->id,
                'reason' => $reason,
            ], $extra),
        ];
    }

    /**
     * @param  array<string,mixed>  $metadata
     */
    private function recordPublicEvent(AiJob $job, string $name, array $metadata): void
    {
        $this->stream->record(
            $job,
            null,
            'lifecycle',
            '',
            array_merge([
                'schema_version' => self::EVENT_SCHEMA,
                'name' => $name,
                'recorded_at' => now()->toJSON(),
            ], $metadata),
            'system',
        );
    }

    private function latestJobForTrace(AiTrace $trace): ?AiJob
    {
        return $trace->jobs()
            ->whereIn('status', ['queued', 'processing'])
            ->latest('created_at')
            ->first()
            ?: $trace->jobs()->latest('created_at')->first();
    }
}
