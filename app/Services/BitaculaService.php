<?php

namespace App\Services;

use App\Models\Behavior;
use App\Models\BehaviorLog;
use App\Support\Metadata;
use Illuminate\Support\Str;

class BitaculaService
{
    public function upsertBehavior(array $data): array
    {
        $payload = $this->normalizeBehaviorPayload($data);
        $behavior = Behavior::withTrashed()
            ->where('client_id', $payload['client_id'])
            ->first();
        $created = false;

        if (! $behavior) {
            $behavior = Behavior::create($payload);
            $created = true;
        } else {
            $behavior->fill($payload);
            if ($behavior->isDirty()) {
                $behavior->save();
            }

            if ($behavior->trashed()) {
                $behavior->restore();
            }
        }

        return ['model' => $behavior->refresh(), 'created' => $created];
    }

    public function upsertBehaviorLog(array $data): array
    {
        $payload = $this->normalizeBehaviorLogPayload($data);
        $log = BehaviorLog::withTrashed()
            ->where('client_id', $payload['client_id'])
            ->orWhere(function ($query) use ($payload): void {
                $query
                    ->where('behavior_client_id', $payload['behavior_client_id'])
                    ->whereDate('log_date', $payload['log_date']);
            })
            ->first();
        $created = false;

        if (! $log) {
            $log = BehaviorLog::create($payload);
            $created = true;
        } else {
            $log->fill($payload);
            if ($log->isDirty()) {
                $log->save();
            }

            if ($log->trashed()) {
                $log->restore();
            }
        }

        $this->recomputeBehaviorCounters($payload['behavior_client_id']);

        return ['model' => $log->refresh(), 'created' => $created];
    }

    public function normalizeBehaviorPayload(array $data): array
    {
        $name = trim((string) $data['name']);
        $slug = trim((string) ($data['slug'] ?? Str::slug($name, '_')));

        return [
            ...$data,
            'name' => $name,
            'slug' => $slug === '' ? Str::slug($name, '_') : $slug,
            'category' => $data['category'] ?? 'outro',
            'input_type' => $data['input_type'] ?? 'yes_no',
            'question_text' => trim((string) ($data['question_text'] ?? "{$name} aconteceu ontem?")),
            'default_value' => $data['default_value'] ?? 'no',
            'created_by' => $data['created_by'] ?? 'operator',
            'source_capture_ids' => Metadata::forStorage($data['source_capture_ids'] ?? []),
            'activation_rules' => Metadata::forStorage($data['activation_rules'] ?? []),
            'show_in_morning_briefing' => $data['show_in_morning_briefing'] ?? true,
            'priority_score' => (int) ($data['priority_score'] ?? 0),
            'streak_yes' => (int) ($data['streak_yes'] ?? 0),
            'streak_no' => (int) ($data['streak_no'] ?? 0),
            'total_yes_count' => (int) ($data['total_yes_count'] ?? 0),
            'total_no_count' => (int) ($data['total_no_count'] ?? 0),
            'relational_privacy' => $data['relational_privacy'] ?? false,
            'activated_at' => $data['activated_at'] ?? now(),
            'metadata' => Metadata::forStorage($data['metadata'] ?? []),
        ];
    }

    public function normalizeBehaviorLogPayload(array $data): array
    {
        $behavior = Behavior::withTrashed()
            ->where('client_id', $data['behavior_client_id'])
            ->first();

        return [
            ...$data,
            'behavior_id' => $behavior?->id,
            'value' => trim((string) $data['value']),
            'numeric_value' => $data['numeric_value'] ?? $this->numericValueFor(trim((string) $data['value'])),
            'source' => $data['source'] ?? 'manual',
            'auto_marked' => $data['auto_marked'] ?? false,
            'confirmed_by_operator' => $data['confirmed_by_operator'] ?? true,
            'metadata' => Metadata::forStorage($data['metadata'] ?? []),
        ];
    }

    public function recomputeBehaviorCounters(string $behaviorClientId): void
    {
        $behavior = Behavior::withTrashed()->where('client_id', $behaviorClientId)->first();
        if (! $behavior) {
            return;
        }

        $logs = BehaviorLog::query()
            ->where('behavior_client_id', $behaviorClientId)
            ->whereNull('reverted_at')
            ->orderBy('log_date')
            ->get(['value', 'log_date']);

        $totalYes = $logs->where('value', 'yes')->count();
        $totalNo = $logs->where('value', 'no')->count();
        $streakYes = 0;
        $streakNo = 0;

        foreach ($logs->reverse() as $log) {
            if ($log->value === 'yes' && $streakNo === 0) {
                $streakYes++;

                continue;
            }

            if ($log->value === 'no' && $streakYes === 0) {
                $streakNo++;

                continue;
            }

            break;
        }

        $behavior->forceFill([
            'total_yes_count' => $totalYes,
            'total_no_count' => $totalNo,
            'streak_yes' => $streakYes,
            'streak_no' => $streakNo,
        ])->save();
    }

    private function numericValueFor(string $value): ?int
    {
        return match (strtolower($value)) {
            'yes', 'sim', 'true' => 1,
            'no', 'nao', 'não', 'false' => 0,
            default => null,
        };
    }
}
