<?php

namespace App\Services;

use App\Models\Behavior;
use App\Models\BehaviorLog;
use App\Services\Bitacula\CanonicalBehaviorCatalog;
use App\Support\BehaviorCategories;
use App\Support\BehaviorLifecycle;
use App\Support\Metadata;
use Illuminate\Support\Str;

class BitaculaService
{
    public function __construct(private readonly CanonicalBehaviorCatalog $catalog) {}

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

        $this->recomputeBehaviorCounters($payload['behavior_client_id'], $payload);

        return ['model' => $log->refresh(), 'created' => $created];
    }

    public function normalizeBehaviorPayload(array $data): array
    {
        $name = trim((string) $data['name']);
        $slug = trim((string) ($data['slug'] ?? Str::slug($name, '_')));
        $suggestion = $this->shouldNormalizeBehavior($data)
            ? $this->catalog->bestSuggestion($name)
            : null;
        $canonical = $suggestion ? $this->catalog->behaviorPayload($suggestion) : [];
        $applyCanonical = $suggestion && empty($data['parent_factor']);
        $autoQuestion = "{$name} aconteceu ontem?";
        $storedName = $applyCanonical ? $canonical['name'] : $name;
        $storedSlug = $applyCanonical && ($slug === '' || $slug === Str::slug($name, '_'))
            ? Str::slug($storedName, '_')
            : $slug;
        $storedQuestion = $data['question_text'] ?? null;
        if ($applyCanonical && (! $storedQuestion || trim((string) $storedQuestion) === $autoQuestion)) {
            $storedQuestion = $canonical['question_text'];
        }
        $providedCategory = BehaviorCategories::canonicalize($data['category'] ?? null);
        $canonicalCategory = BehaviorCategories::canonicalize($canonical['category'] ?? null);
        $storedCategory = $applyCanonical && $providedCategory === 'outro'
            ? $canonicalCategory
            : ($providedCategory !== 'outro' ? $providedCategory : $canonicalCategory);
        $lifecycle = BehaviorLifecycle::canonicalize($data['lifecycle_status'] ?? null);
        $showInBriefing = (bool) ($data['show_in_morning_briefing'] ?? true);

        if (! array_key_exists('lifecycle_status', $data) && $showInBriefing === false) {
            $lifecycle = BehaviorLifecycle::MANUAL_ONLY;
        }

        if (! BehaviorLifecycle::isPromptable($lifecycle)) {
            $showInBriefing = false;
        }

        return [
            ...$data,
            'name' => $storedName,
            'slug' => $storedSlug === '' ? Str::slug($storedName, '_') : $storedSlug,
            'category' => $storedCategory,
            'input_type' => $data['input_type'] ?? $canonical['input_type'] ?? 'yes_no',
            'question_text' => trim((string) ($storedQuestion ?? $canonical['question_text'] ?? "{$storedName} aconteceu ontem?")),
            'default_value' => $data['default_value'] ?? 'no',
            'parent_factor' => $data['parent_factor'] ?? $canonical['parent_factor'] ?? null,
            'factor_condition' => $data['factor_condition'] ?? $canonical['factor_condition'] ?? null,
            'target_outcomes' => empty($data['target_outcomes']) ? ($canonical['target_outcomes'] ?? []) : $data['target_outcomes'],
            'expected_lag' => $data['expected_lag'] ?? $canonical['expected_lag'] ?? null,
            'expected_direction' => $data['expected_direction'] ?? $canonical['expected_direction'] ?? null,
            'granularity_level' => $data['granularity_level'] ?? $canonical['granularity_level'] ?? 'binary',
            'sensitivity_level' => $data['sensitivity_level'] ?? $canonical['sensitivity_level'] ?? 'normal',
            'derived_from' => Metadata::forStorage(empty($data['derived_from']) ? ($canonical['derived_from'] ?? []) : $data['derived_from']),
            'operator_confirmed' => $data['operator_confirmed'] ?? true,
            'created_by' => $data['created_by'] ?? $canonical['created_by'] ?? 'operator',
            'source_capture_ids' => Metadata::forStorage($data['source_capture_ids'] ?? []),
            'activation_rules' => Metadata::forStorage($data['activation_rules'] ?? []),
            'lifecycle_status' => $lifecycle,
            'paused_until' => $data['paused_until'] ?? null,
            'last_prompted_at' => $data['last_prompted_at'] ?? null,
            'prompt_cadence_days' => (int) ($data['prompt_cadence_days'] ?? 1),
            'auto_suppress_reason' => $data['auto_suppress_reason'] ?? null,
            'show_in_morning_briefing' => $showInBriefing,
            'priority_score' => $this->priorityScore($data['priority_score'] ?? null),
            'streak_yes' => (int) ($data['streak_yes'] ?? 0),
            'streak_no' => (int) ($data['streak_no'] ?? 0),
            'total_yes_count' => (int) ($data['total_yes_count'] ?? 0),
            'total_no_count' => (int) ($data['total_no_count'] ?? 0),
            'relational_privacy' => $data['relational_privacy'] ?? false,
            'activated_at' => $data['activated_at'] ?? now(),
            'metadata' => Metadata::forStorage($data['metadata'] ?? []),
        ];
    }

    private function priorityScore(mixed $value): int
    {
        if (! is_numeric($value)) {
            return 50;
        }

        $score = (int) $value;

        return $score >= 0 && $score <= 100 ? $score : 50;
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
            'occurred_at' => $data['occurred_at'] ?? null,
            'occurred_timezone' => $data['occurred_timezone'] ?? null,
            'quantity_numeric' => $data['quantity_numeric'] ?? null,
            'quantity_unit' => $data['quantity_unit'] ?? null,
            'intensity' => $data['intensity'] ?? null,
            'context' => Metadata::forStorage($data['context'] ?? []),
            'auto_marked' => $data['auto_marked'] ?? false,
            'confirmed_by_operator' => $data['confirmed_by_operator'] ?? true,
            'confidence' => $data['confidence'] ?? null,
            'inferred_by' => $data['inferred_by'] ?? null,
            'consent_snapshot_id' => $data['consent_snapshot_id'] ?? null,
            'metadata' => Metadata::forStorage($data['metadata'] ?? []),
        ];
    }

    public function recomputeBehaviorCounters(string $behaviorClientId, ?array $lastLogPayload = null): void
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

        $lifecyclePatch = $this->lifecyclePatch($behavior, $streakYes, $streakNo, $totalYes, $totalNo, $lastLogPayload);

        $behavior->forceFill([
            'total_yes_count' => $totalYes,
            'total_no_count' => $totalNo,
            'streak_yes' => $streakYes,
            'streak_no' => $streakNo,
            ...$lifecyclePatch,
        ])->save();
    }

    private function lifecyclePatch(Behavior $behavior, int $streakYes, int $streakNo, int $totalYes, int $totalNo, ?array $lastLogPayload): array
    {
        $status = BehaviorLifecycle::canonicalize($behavior->lifecycle_status);
        $metadata = $behavior->metadata ?? [];

        if (($metadata['disable_lifecycle_auto_management'] ?? false) === true) {
            return [];
        }

        if ($status === BehaviorLifecycle::MANUAL_ONLY || $status === BehaviorLifecycle::PAUSED) {
            return [];
        }

        if ($status === BehaviorLifecycle::DORMANT && ($lastLogPayload['value'] ?? null) === 'yes') {
            return [
                'lifecycle_status' => BehaviorLifecycle::ACTIVE,
                'show_in_morning_briefing' => true,
                'auto_suppress_reason' => null,
            ];
        }

        $total = $totalYes + $totalNo;
        $yesRate = $total > 0 ? $totalYes / $total : 0;

        if ($total >= 14 && $yesRate >= 0.85 && $streakYes >= 10) {
            return [
                'lifecycle_status' => BehaviorLifecycle::BASELINE,
                'show_in_morning_briefing' => false,
                'auto_suppress_reason' => 'stable_baseline',
            ];
        }

        if ($streakNo >= 50) {
            return [
                'lifecycle_status' => BehaviorLifecycle::DORMANT,
                'show_in_morning_briefing' => false,
                'auto_suppress_reason' => 'long_absence',
            ];
        }

        return [];
    }

    private function numericValueFor(string $value): ?int
    {
        return match (strtolower($value)) {
            'yes', 'sim', 'true' => 1,
            'no', 'nao', 'não', 'false' => 0,
            default => null,
        };
    }

    private function shouldNormalizeBehavior(array $data): bool
    {
        return empty($data['parent_factor'])
            || empty($data['factor_condition'])
            || ! array_key_exists('target_outcomes', $data);
    }
}
