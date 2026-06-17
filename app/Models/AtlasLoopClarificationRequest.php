<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * ACDE U5 — one pending OPERATOR CLARIFICATION REQUEST (the calibrated-abstain-and-ask queue).
 *
 * Enqueued when the structured planner abstains (vague goal / un-ready spec / ill-formed DAG) instead of
 * silently falling back to the dumb one-shot. {@see fingerprint()} is a deterministic hash of the
 * normalized goal so the SAME goal re-abstaining de-dupes onto one row (times_seen increments) and U6's
 * answer cache can match an answer to a re-ask. Anchored on the planner's machine-resolved abstention,
 * never on a model declaring confusion.
 */
class AtlasLoopClarificationRequest extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ANSWERED = 'answered';

    public const STATUS_DISMISSED = 'dismissed';

    protected $table = 'atlas_loop_clarification_requests';

    protected $fillable = [
        'goal_fingerprint',
        'goal',
        'objective_kind',
        'family',
        'reason',
        'status',
        'answer',
        'times_seen',
        'answered_at',
    ];

    protected function casts(): array
    {
        return [
            'times_seen' => 'integer',
            'answered_at' => 'datetime',
        ];
    }

    /**
     * Deterministic fingerprint of a goal — case/space-insensitive, id-independent — so the same directive
     * always lands on the same queue row and U6's cache can key an answer to a re-ask.
     */
    public static function fingerprint(string $goal): string
    {
        return substr(hash('sha256', mb_strtolower(trim($goal))), 0, 32);
    }

    /**
     * ACDE U6 — the cached operator answer for a goal: the most-recently-answered request whose fingerprint
     * matches (any abstention reason — the operator's clarification answers the GOAL, not one reason). Null
     * if the goal has never been answered (only pending/dismissed, or unseen). This is what lets the loop
     * fold a prior clarification back in instead of re-asking.
     */
    public static function cachedAnswerFor(string $goal): ?string
    {
        $row = static::query()
            ->where('goal_fingerprint', static::fingerprint($goal))
            ->where('status', self::STATUS_ANSWERED)
            ->whereNotNull('answer')
            ->orderByDesc('answered_at')
            ->orderByDesc('updated_at')
            ->first();

        $answer = $row?->answer;

        return is_string($answer) && trim($answer) !== '' ? trim($answer) : null;
    }

    /**
     * Enqueue an abstention as a clarification request. Idempotent on (goal_fingerprint, reason): the first
     * sighting inserts (pending, times_seen=1); a recurrence increments times_seen and refreshes the goal
     * text, but NEVER resets an already-answered/dismissed status (the operator's resolution is sticky).
     *
     * @param  array{objective_kind?:string, family?:string, reason?:string}  $receipt  a plan-abstention receipt
     */
    public static function enqueue(array $receipt, string $goal): ?self
    {
        $goal = trim($goal);
        $reason = trim((string) ($receipt['reason'] ?? ''));
        if ($goal === '' || $reason === '') {
            return null;
        }

        $fingerprint = static::fingerprint($goal);
        $request = static::query()->firstOrNew([
            'goal_fingerprint' => $fingerprint,
            'reason' => $reason,
        ]);

        if ($request->exists) {
            $request->times_seen = ($request->times_seen ?? 0) + 1;
            $request->goal = $goal; // keep the freshest raw text (fingerprint is unchanged)
        } else {
            $request->goal = $goal;
            $request->status = self::STATUS_PENDING;
            $request->times_seen = 1;
        }
        $request->objective_kind = trim((string) ($receipt['objective_kind'] ?? '')) ?: null;
        $request->family = trim((string) ($receipt['family'] ?? '')) ?: null;
        $request->save();

        return $request;
    }
}
