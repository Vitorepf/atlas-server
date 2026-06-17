<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Bitacula\CanonicalBehaviorCatalog;
use App\Support\BehaviorCategories;
use App\Support\BehaviorLifecycle;
use App\Support\Metadata;
use Illuminate\Support\Str;

final class BitaculaServiceSupport
{
    public function normalizeBehaviorPayload(array $data, CanonicalBehaviorCatalog $catalog): array
    {
        $name = trim((string) $data['name']);
        $identity = $this->normalizeBehaviorIdentity($data, $catalog, $name);
        $prompting = $this->normalizeBehaviorPrompting($data);

        return $this->composeBehaviorPayload($data, $identity, $prompting);
    }

    public function normalizeBehaviorIdentity(array $data, CanonicalBehaviorCatalog $catalog, string $name): array
    {
        $slug = trim((string) ($data['slug'] ?? Str::slug($name, '_')));
        $suggestion = $this->shouldNormalizeBehavior($data)
            ? $catalog->bestSuggestion($name)
            : null;
        $canonical = $suggestion ? $catalog->behaviorPayload($suggestion) : [];
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

        return [
            'canonical' => $canonical,
            'stored_name' => $storedName,
            'stored_slug' => $storedSlug,
            'stored_question' => $storedQuestion,
            'stored_category' => $storedCategory,
        ];
    }

    public function normalizeBehaviorPrompting(array $data): array
    {
        $lifecycle = BehaviorLifecycle::canonicalize($data['lifecycle_status'] ?? null);
        $showInBriefing = (bool) ($data['show_in_morning_briefing'] ?? true);

        if (! array_key_exists('lifecycle_status', $data) && $showInBriefing === false) {
            $lifecycle = BehaviorLifecycle::MANUAL_ONLY;
        }

        if (! BehaviorLifecycle::isPromptable($lifecycle)) {
            $showInBriefing = false;
        }

        return [
            'lifecycle' => $lifecycle,
            'show_in_morning_briefing' => $showInBriefing,
        ];
    }

    public function composeBehaviorPayload(array $data, array $identity, array $prompting): array
    {
        $canonical = $identity['canonical'];
        $storedName = $identity['stored_name'];
        $storedSlug = $identity['stored_slug'];
        $storedQuestion = $identity['stored_question'];
        $storedCategory = $identity['stored_category'];

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
            'lifecycle_status' => $prompting['lifecycle'],
            'paused_until' => $data['paused_until'] ?? null,
            'last_prompted_at' => $data['last_prompted_at'] ?? null,
            'prompt_cadence_days' => (int) ($data['prompt_cadence_days'] ?? 1),
            'auto_suppress_reason' => $data['auto_suppress_reason'] ?? null,
            'show_in_morning_briefing' => $prompting['show_in_morning_briefing'],
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

    public function priorityScore(mixed $value): int
    {
        if (! is_numeric($value)) {
            return 50;
        }

        $score = (int) $value;

        return $score >= 0 && $score <= 100 ? $score : 50;
    }

    public function shouldNormalizeBehavior(array $data): bool
    {
        return empty($data['parent_factor'])
            || empty($data['factor_condition'])
            || ! array_key_exists('target_outcomes', $data);
    }
}
