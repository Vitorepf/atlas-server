<?php

namespace App\Services\Ai\Mission;

use App\Models\AiMission;
use App\Models\AiMissionEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class MissionLifecycleService
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PLANNED = 'planned';

    public const STATUS_RUNNING = 'running';

    public const STATUS_WAITING_APPROVAL = 'waiting_approval';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_REPAIRING = 'repairing';

    public const STATUS_CERTIFYING = 'certifying';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @var array<string,array<int,string>>
     */
    private const ALLOWED_TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_PLANNED, self::STATUS_CANCELLED],
        self::STATUS_PLANNED => [self::STATUS_RUNNING, self::STATUS_CANCELLED],
        self::STATUS_RUNNING => [
            self::STATUS_WAITING_APPROVAL,
            self::STATUS_BLOCKED,
            self::STATUS_CERTIFYING,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
        ],
        self::STATUS_WAITING_APPROVAL => [self::STATUS_RUNNING, self::STATUS_BLOCKED, self::STATUS_CANCELLED, self::STATUS_FAILED],
        self::STATUS_BLOCKED => [self::STATUS_REPAIRING, self::STATUS_CANCELLED, self::STATUS_FAILED],
        self::STATUS_REPAIRING => [self::STATUS_RUNNING, self::STATUS_FAILED, self::STATUS_CANCELLED],
        self::STATUS_CERTIFYING => [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_REPAIRING],
        self::STATUS_COMPLETED => [],
        self::STATUS_FAILED => [],
        self::STATUS_CANCELLED => [],
    ];

    /**
     * @param  array<string,mixed>  $context
     */
    public function transition(AiMission $mission, string $to, array $context = []): AiMissionEvent
    {
        $from = (string) $mission->status;
        $allowed = self::ALLOWED_TRANSITIONS[$from] ?? null;

        if ($allowed === null || ! in_array($to, $allowed, true)) {
            throw MissionLifecycleException::invalidTransition($from, $to);
        }

        if ($to === self::STATUS_COMPLETED) {
            $this->guardCompletion($mission);
        }

        $mission->status = $to;
        if ($to === self::STATUS_BLOCKED) {
            $mission->blocker_reason = (string) ($context['blocker_reason'] ?? $mission->blocker_reason ?? 'unspecified');
        }
        if ($to === self::STATUS_COMPLETED) {
            $mission->completed_at = Carbon::now();
        }
        $mission->save();

        return $this->recordEvent(
            $mission,
            'mission.transition',
            (string) ($context['actor_type'] ?? 'system'),
            array_merge($context, ['from' => $from, 'to' => $to]),
            $from,
            $to,
        );
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function recordEvent(
        AiMission $mission,
        string $eventType,
        string $actorType,
        array $payload,
        ?string $statusBefore = null,
        ?string $statusAfter = null,
        ?string $receiptHash = null,
    ): AiMissionEvent {
        return AiMissionEvent::query()->create([
            'uuid' => (string) Str::uuid(),
            'mission_id' => $mission->id,
            'event_type' => $eventType,
            'status_before' => $statusBefore,
            'status_after' => $statusAfter,
            'actor_type' => $actorType,
            'payload' => $payload,
            'receipt_hash' => $receiptHash,
        ]);
    }

    /**
     * @return array<int,string>
     */
    public function allowedNext(string $from): array
    {
        return self::ALLOWED_TRANSITIONS[$from] ?? [];
    }

    private function guardCompletion(AiMission $mission): void
    {
        if ($mission->evidenceRefs()->count() === 0) {
            throw MissionLifecycleException::missingEvidence();
        }

        $latest = $mission->latestCertification()->first();
        if ($latest === null || $latest->status !== 'passed') {
            throw MissionLifecycleException::missingCertification();
        }
    }
}
