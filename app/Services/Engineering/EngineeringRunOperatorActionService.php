<?php

namespace App\Services\Engineering;

use App\Models\AtlasEngineeringRun;
use App\Models\AtlasEngineeringRunOperatorAction;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class EngineeringRunOperatorActionService
{
    private const ACTION_TRANSITIONS = [
        'cancel' => ['status' => 'cancelled', 'decision' => 'unresolved'],
        'accept' => ['status' => 'passed', 'decision' => 'resolved'],
        'needs_human' => ['status' => 'blocked', 'decision' => 'blocked'],
        'reject' => ['status' => 'failed', 'decision' => 'unresolved'],
    ];

    /**
     * @param  array<string,mixed>  $options
     */
    public function apply(AtlasEngineeringRun $run, string $action, array $options = []): AtlasEngineeringRunOperatorAction
    {
        $action = trim($action);
        if (! array_key_exists($action, self::ACTION_TRANSITIONS)) {
            throw new InvalidArgumentException("Unsupported engineering run operator action [{$action}].");
        }

        return DB::transaction(function () use ($run, $action, $options): AtlasEngineeringRunOperatorAction {
            $run = AtlasEngineeringRun::query()->lockForUpdate()->findOrFail($run->id);
            $statusBefore = $run->status;
            $decisionBefore = $run->decision;
            $transition = self::ACTION_TRANSITIONS[$action];
            $now = now();
            $actor = is_string($options['actor'] ?? null) && trim((string) $options['actor']) !== ''
                ? trim((string) $options['actor'])
                : 'operator';
            $note = is_string($options['note'] ?? null) && trim((string) $options['note']) !== ''
                ? trim((string) $options['note'])
                : null;
            $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];

            $actionRecord = AtlasEngineeringRunOperatorAction::query()->create([
                'engineering_run_id' => $run->id,
                'action' => $action,
                'actor' => $actor,
                'status_before' => $statusBefore,
                'decision_before' => $decisionBefore,
                'status_after' => $transition['status'],
                'decision_after' => $transition['decision'],
                'note' => $note,
                'payload_json' => $payload,
                'acted_at' => $now,
            ]);

            $metadata = (array) ($run->metadata ?? []);
            $metadata['operator_decision'] = [
                'action_id' => $actionRecord->id,
                'action' => $action,
                'actor' => $actor,
                'note' => $note,
                'status_before' => $statusBefore,
                'decision_before' => $decisionBefore,
                'status_after' => $transition['status'],
                'decision_after' => $transition['decision'],
                'acted_at' => $now->toJSON(),
            ];
            $history = is_array($metadata['operator_decision_history'] ?? null)
                ? $metadata['operator_decision_history']
                : [];
            $metadata['operator_decision_history'] = array_slice([
                $metadata['operator_decision'],
                ...$history,
            ], 0, 20);

            $run->forceFill([
                'status' => $transition['status'],
                'decision' => $transition['decision'],
                'finished_at' => $run->finished_at ?: $now,
                'metadata' => $metadata,
            ])->save();

            if ($action === 'cancel') {
                $run->attempts()
                    ->whereIn('status', ['queued', 'running'])
                    ->update([
                        'status' => 'cancelled',
                        'failure_summary' => 'Cancelled by operator action.',
                        'finished_at' => $now,
                        'updated_at' => $now,
                    ]);
            }

            return $actionRecord->load('run');
        });
    }

    /**
     * @return array<string,string>
     */
    public function transitions(): array
    {
        return collect(self::ACTION_TRANSITIONS)
            ->mapWithKeys(fn (array $transition, string $action): array => [$action => $transition['status'].'/'.$transition['decision']])
            ->all();
    }
}
