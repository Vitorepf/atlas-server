<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesAtlasDomain;
use App\Http\Requests\Concerns\RejectsFutureCheckinRecordedAt;
use App\Services\AtlasDomainRegistry;
use App\Support\BehaviorCategories;
use App\Support\BehaviorLifecycle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncRequest extends FormRequest
{
    use ValidatesAtlasDomain;
    use RejectsFutureCheckinRecordedAt;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $captures = $this->input('captures_to_upload');
        if (is_array($captures)) {
            $defaultDomain = app(AtlasDomainRegistry::class)->defaultSlug();
            foreach ($captures as $index => $capture) {
                if (! is_array($capture)) {
                    continue;
                }

                $domain = $capture['domain'] ?? null;
                if (! is_string($domain) || trim($domain) === '') {
                    $captures[$index]['domain'] = $defaultDomain;
                }
            }

            $this->merge(['captures_to_upload' => $captures]);
        }

        $behaviors = $this->input('behaviors_to_upload');
        if (! is_array($behaviors)) {
            return;
        }

        foreach ($behaviors as $index => $behavior) {
            if (
                is_array($behavior)
                && array_key_exists('category', $behavior)
                && is_string($behavior['category'])
                && trim($behavior['category']) !== ''
            ) {
                $behaviors[$index]['category'] = BehaviorCategories::canonicalize((string) $behavior['category']);
            }

            if (
                is_array($behavior)
                && array_key_exists('lifecycle_status', $behavior)
                && is_string($behavior['lifecycle_status'])
                && trim($behavior['lifecycle_status']) !== ''
            ) {
                $behaviors[$index]['lifecycle_status'] = BehaviorLifecycle::canonicalize((string) $behavior['lifecycle_status']);
            }
        }

        $this->merge(['behaviors_to_upload' => $behaviors]);
    }

    public function rules(): array
    {
        return [
            'device_id' => ['required', 'string', 'max:128'],
            'last_sync_at' => ['nullable', 'date'],
            'captures_to_upload' => ['sometimes', 'array'],
            'captures_to_upload.*.client_id' => ['required', 'uuid'],
            'captures_to_upload.*.kind' => ['required', Rule::in(['text'])],
            'captures_to_upload.*.domain' => $this->atlasDomainRule(required: false),
            'captures_to_upload.*.content_text' => ['required', 'string'],
            'captures_to_upload.*.captured_at' => ['required', 'date'],
            'captures_to_upload.*.captured_timezone' => ['required', 'string', 'max:128'],
            'captures_to_upload.*.captured_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'captures_to_upload.*.captured_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'captures_to_upload.*.pre_capture_digital_context' => ['sometimes', 'array'],
            'captures_to_upload.*.metadata' => ['sometimes', 'array'],
            'checkins_to_upload' => ['sometimes', 'array'],
            'checkins_to_upload.*.client_id' => ['required', 'uuid'],
            'checkins_to_upload.*.state' => ['required', Rule::in(['focused', 'disperse', 'blocked', 'pause'])],
            'checkins_to_upload.*.energy_level' => ['required', 'integer', 'between:1,5'],
            'checkins_to_upload.*.mood_level' => ['required', 'integer', 'between:1,5'],
            'checkins_to_upload.*.note' => ['nullable', 'string'],
            'checkins_to_upload.*.recorded_at' => ['required', 'date'],
            'checkins_to_upload.*.recorded_timezone' => ['required', 'string', 'max:128'],
            'checkins_to_upload.*.metadata' => ['sometimes', 'array'],
            'passive_signals_to_upload' => ['sometimes', 'array'],
            'passive_signals_to_upload.*.client_id' => ['required', 'uuid'],
            'passive_signals_to_upload.*.source' => ['required', Rule::in(['healthkit', 'rize'])],
            'passive_signals_to_upload.*.signal_type' => ['required', 'string', 'max:128'],
            'passive_signals_to_upload.*.value_numeric' => ['nullable', 'numeric'],
            'passive_signals_to_upload.*.value_text' => ['nullable', 'string', 'max:1024'],
            'passive_signals_to_upload.*.unit' => ['nullable', 'string', 'max:64'],
            'passive_signals_to_upload.*.started_at' => ['required', 'date'],
            'passive_signals_to_upload.*.ended_at' => ['nullable', 'date'],
            'passive_signals_to_upload.*.recorded_timezone' => ['required', 'string', 'max:128'],
            'passive_signals_to_upload.*.metadata' => ['sometimes', 'array'],
            'passive_signals_to_upload.*.deleted_at' => ['nullable', 'date'],
            'health_snapshots_to_upload' => ['sometimes', 'array'],
            'health_snapshots_to_upload.*.client_id' => ['required', 'uuid'],
            'health_snapshots_to_upload.*.source' => ['required', Rule::in(['atlas_app', 'server', 'import'])],
            'health_snapshots_to_upload.*.snapshot_date' => ['required', 'date'],
            'health_snapshots_to_upload.*.snapshot_timezone' => ['required', 'string', 'max:128'],
            'health_snapshots_to_upload.*.computed_at' => ['required', 'date'],
            'health_snapshots_to_upload.*.signal_count' => ['required', 'integer', 'min:0'],
            'health_snapshots_to_upload.*.readiness_score' => ['nullable', 'integer', 'between:0,100'],
            'health_snapshots_to_upload.*.current_score' => ['nullable', 'integer', 'between:0,100'],
            'health_snapshots_to_upload.*.body_score' => ['nullable', 'integer', 'between:0,100'],
            'health_snapshots_to_upload.*.mind_score' => ['nullable', 'integer', 'between:0,100'],
            'health_snapshots_to_upload.*.drive_score' => ['nullable', 'integer', 'between:0,100'],
            'health_snapshots_to_upload.*.sleep_score' => ['nullable', 'integer', 'between:0,100'],
            'health_snapshots_to_upload.*.autonomic_score' => ['nullable', 'integer', 'between:0,100'],
            'health_snapshots_to_upload.*.load_score' => ['nullable', 'integer', 'between:0,100'],
            'health_snapshots_to_upload.*.subjective_score' => ['nullable', 'integer', 'between:0,100'],
            'health_snapshots_to_upload.*.stability_score' => ['nullable', 'integer', 'between:0,100'],
            'health_snapshots_to_upload.*.confidence' => ['nullable', 'numeric', 'between:0,100'],
            'health_snapshots_to_upload.*.sleep_duration_hours' => ['nullable', 'numeric'],
            'health_snapshots_to_upload.*.sleep_efficiency' => ['nullable', 'numeric'],
            'health_snapshots_to_upload.*.hrv_ms' => ['nullable', 'numeric'],
            'health_snapshots_to_upload.*.resting_heart_rate_bpm' => ['nullable', 'numeric'],
            'health_snapshots_to_upload.*.respiratory_rate' => ['nullable', 'numeric'],
            'health_snapshots_to_upload.*.wrist_temperature_c' => ['nullable', 'numeric'],
            'health_snapshots_to_upload.*.active_energy_kcal' => ['nullable', 'numeric'],
            'health_snapshots_to_upload.*.basal_energy_kcal' => ['nullable', 'numeric'],
            'health_snapshots_to_upload.*.exercise_minutes' => ['nullable', 'numeric'],
            'health_snapshots_to_upload.*.stand_minutes' => ['nullable', 'numeric'],
            'health_snapshots_to_upload.*.steps' => ['nullable', 'numeric'],
            'health_snapshots_to_upload.*.walking_running_distance_m' => ['nullable', 'numeric'],
            'health_snapshots_to_upload.*.vo2max' => ['nullable', 'numeric'],
            'health_snapshots_to_upload.*.body_mass_kg' => ['nullable', 'numeric'],
            'health_snapshots_to_upload.*.body_fat_percentage' => ['nullable', 'numeric'],
            'health_snapshots_to_upload.*.lean_body_mass_kg' => ['nullable', 'numeric'],
            'health_snapshots_to_upload.*.muscle_mass_percentage' => ['nullable', 'numeric'],
            'health_snapshots_to_upload.*.body_mass_index' => ['nullable', 'numeric'],
            'health_snapshots_to_upload.*.waist_circumference_cm' => ['nullable', 'numeric'],
            'health_snapshots_to_upload.*.energy_level' => ['nullable', 'integer', 'between:1,5'],
            'health_snapshots_to_upload.*.mood_level' => ['nullable', 'integer', 'between:1,5'],
            'health_snapshots_to_upload.*.state' => ['nullable', Rule::in(['focused', 'disperse', 'blocked', 'pause'])],
            'health_snapshots_to_upload.*.metrics' => ['sometimes', 'array'],
            'health_snapshots_to_upload.*.readiness' => ['sometimes', 'array'],
            'health_snapshots_to_upload.*.sleep' => ['sometimes', 'array'],
            'health_snapshots_to_upload.*.recovery' => ['sometimes', 'array'],
            'health_snapshots_to_upload.*.load' => ['sometimes', 'array'],
            'health_snapshots_to_upload.*.subjective' => ['sometimes', 'array'],
            'health_snapshots_to_upload.*.body' => ['sometimes', 'array'],
            'health_snapshots_to_upload.*.metadata' => ['sometimes', 'array'],
            'behaviors_to_upload' => ['sometimes', 'array'],
            'behaviors_to_upload.*.client_id' => ['required', 'uuid'],
            'behaviors_to_upload.*.name' => ['required', 'string', 'max:160'],
            'behaviors_to_upload.*.slug' => ['required', 'string', 'max:180'],
            'behaviors_to_upload.*.category' => ['required', Rule::in(BehaviorCategories::allowed())],
            'behaviors_to_upload.*.input_type' => ['required', Rule::in(['yes_no', 'scale_1_5', 'count_int', 'text_short'])],
            'behaviors_to_upload.*.question_text' => ['required', 'string', 'max:240'],
            'behaviors_to_upload.*.default_value' => ['nullable', 'string', 'max:80'],
            'behaviors_to_upload.*.parent_factor' => ['nullable', 'string', 'max:128'],
            'behaviors_to_upload.*.factor_condition' => ['nullable', 'string', 'max:128'],
            'behaviors_to_upload.*.target_outcomes' => ['sometimes', 'array'],
            'behaviors_to_upload.*.expected_lag' => ['nullable', 'string', 'max:128'],
            'behaviors_to_upload.*.expected_direction' => ['nullable', 'string', 'max:32'],
            'behaviors_to_upload.*.granularity_level' => ['nullable', Rule::in(['binary', 'intensity', 'protocol'])],
            'behaviors_to_upload.*.sensitivity_level' => ['nullable', Rule::in(['normal', 'sensitive', 'relational', 'medical'])],
            'behaviors_to_upload.*.derived_from' => ['sometimes', 'array'],
            'behaviors_to_upload.*.operator_confirmed' => ['nullable', 'boolean'],
            'behaviors_to_upload.*.created_by' => ['nullable', Rule::in(['operator', 'ai_suggestion', 'import'])],
            'behaviors_to_upload.*.source_capture_ids' => ['sometimes', 'array'],
            'behaviors_to_upload.*.activation_rules' => ['sometimes', 'array'],
            'behaviors_to_upload.*.lifecycle_status' => ['nullable', Rule::in(BehaviorLifecycle::allowed())],
            'behaviors_to_upload.*.paused_until' => ['nullable', 'date'],
            'behaviors_to_upload.*.last_prompted_at' => ['nullable', 'date'],
            'behaviors_to_upload.*.prompt_cadence_days' => ['nullable', 'integer', 'min:1', 'max:30'],
            'behaviors_to_upload.*.auto_suppress_reason' => ['nullable', 'string', 'max:128'],
            'behaviors_to_upload.*.show_in_morning_briefing' => ['nullable', 'boolean'],
            'behaviors_to_upload.*.priority_score' => ['nullable', 'integer'],
            'behaviors_to_upload.*.streak_yes' => ['nullable', 'integer', 'min:0'],
            'behaviors_to_upload.*.streak_no' => ['nullable', 'integer', 'min:0'],
            'behaviors_to_upload.*.total_yes_count' => ['nullable', 'integer', 'min:0'],
            'behaviors_to_upload.*.total_no_count' => ['nullable', 'integer', 'min:0'],
            'behaviors_to_upload.*.relational_privacy' => ['nullable', 'boolean'],
            'behaviors_to_upload.*.activated_at' => ['nullable', 'date'],
            'behaviors_to_upload.*.archived_at' => ['nullable', 'date'],
            'behaviors_to_upload.*.promoted_to_object_type' => ['nullable', 'string', 'max:128'],
            'behaviors_to_upload.*.promoted_to_object_id' => ['nullable', 'uuid'],
            'behaviors_to_upload.*.metadata' => ['sometimes', 'array'],
            'behavior_logs_to_upload' => ['sometimes', 'array'],
            'behavior_logs_to_upload.*.client_id' => ['required', 'uuid'],
            'behavior_logs_to_upload.*.behavior_client_id' => ['required', 'uuid'],
            'behavior_logs_to_upload.*.log_date' => ['required', 'date'],
            'behavior_logs_to_upload.*.value' => ['required', 'string', 'max:512'],
            'behavior_logs_to_upload.*.numeric_value' => ['nullable', 'numeric'],
            'behavior_logs_to_upload.*.note' => ['nullable', 'string', 'max:2048'],
            'behavior_logs_to_upload.*.occurred_at' => ['nullable', 'date'],
            'behavior_logs_to_upload.*.occurred_timezone' => ['nullable', 'string', 'max:128'],
            'behavior_logs_to_upload.*.quantity_numeric' => ['nullable', 'numeric'],
            'behavior_logs_to_upload.*.quantity_unit' => ['nullable', 'string', 'max:64'],
            'behavior_logs_to_upload.*.intensity' => ['nullable', 'integer', 'between:1,5'],
            'behavior_logs_to_upload.*.context' => ['sometimes', 'array'],
            'behavior_logs_to_upload.*.recorded_at' => ['required', 'date'],
            'behavior_logs_to_upload.*.recorded_timezone' => ['required', 'string', 'max:128'],
            'behavior_logs_to_upload.*.source' => ['required', Rule::in(['morning_briefing', 'voice_capture', 'manual', 'retroactive', 'import', 'inferred'])],
            'behavior_logs_to_upload.*.source_capture_id' => ['nullable', 'uuid', 'exists:captures,id'],
            'behavior_logs_to_upload.*.auto_marked' => ['nullable', 'boolean'],
            'behavior_logs_to_upload.*.confirmed_by_operator' => ['nullable', 'boolean'],
            'behavior_logs_to_upload.*.confidence' => ['nullable', 'numeric', 'between:0,1'],
            'behavior_logs_to_upload.*.inferred_by' => ['nullable', 'string', 'max:128'],
            'behavior_logs_to_upload.*.consent_snapshot_id' => ['nullable', 'uuid'],
            'behavior_logs_to_upload.*.reverted_at' => ['nullable', 'date'],
            'behavior_logs_to_upload.*.metadata' => ['sometimes', 'array'],
            'digital_sessions_to_upload' => ['sometimes', 'array'],
            'digital_sessions_to_upload.*.client_id' => ['required', 'uuid'],
            'digital_sessions_to_upload.*.source' => ['required', Rule::in(['rize', 'screentime', 'manual', 'import'])],
            'digital_sessions_to_upload.*.source_event_id' => ['nullable', 'string', 'max:512'],
            'digital_sessions_to_upload.*.source_identifier' => ['required', 'string', 'max:512'],
            'digital_sessions_to_upload.*.source_name' => ['required', 'string', 'max:512'],
            'digital_sessions_to_upload.*.source_kind' => ['required', Rule::in(['app', 'domain', 'url', 'project', 'category', 'unknown'])],
            'digital_sessions_to_upload.*.category_class_at_time' => ['nullable', 'integer', 'between:1,10'],
            'digital_sessions_to_upload.*.category_label_at_time' => ['nullable', 'string', 'max:128'],
            'digital_sessions_to_upload.*.intentionality' => ['required', Rule::in(['intentional', 'default', 'mixed', 'unknown'])],
            'digital_sessions_to_upload.*.started_at' => ['required', 'date'],
            'digital_sessions_to_upload.*.ended_at' => ['required', 'date'],
            'digital_sessions_to_upload.*.duration_seconds' => ['required', 'integer', 'min:0'],
            'digital_sessions_to_upload.*.recorded_timezone' => ['required', 'string', 'max:128'],
            'digital_sessions_to_upload.*.focus_mode_active' => ['nullable', 'string', 'max:128'],
            'digital_sessions_to_upload.*.project_name' => ['nullable', 'string', 'max:256'],
            'digital_sessions_to_upload.*.task_name' => ['nullable', 'string', 'max:512'],
            'digital_sessions_to_upload.*.url_domain' => ['nullable', 'string', 'max:256'],
            'digital_sessions_to_upload.*.productivity_score' => ['nullable', 'numeric'],
            'digital_sessions_to_upload.*.linked_capture_id' => ['nullable', 'uuid'],
            'digital_sessions_to_upload.*.linked_decision_id' => ['nullable', 'uuid'],
            'digital_sessions_to_upload.*.raw_payload' => ['sometimes', 'array'],
            'digital_sessions_to_upload.*.metadata' => ['sometimes', 'array'],
            'digital_activity_snapshots_to_upload' => ['sometimes', 'array'],
            'digital_activity_snapshots_to_upload.*.client_id' => ['required', 'uuid'],
            'digital_activity_snapshots_to_upload.*.source' => ['required', Rule::in(['atlas_server', 'rize', 'screentime', 'manual', 'import'])],
            'digital_activity_snapshots_to_upload.*.snapshot_date' => ['required', 'date'],
            'digital_activity_snapshots_to_upload.*.snapshot_timezone' => ['required', 'string', 'max:128'],
            'digital_activity_snapshots_to_upload.*.computed_at' => ['required', 'date'],
            'digital_activity_snapshots_to_upload.*.signal_count' => ['required', 'integer', 'min:0'],
            'digital_activity_snapshots_to_upload.*.total_screen_time_min' => ['nullable', 'integer', 'min:0'],
            'digital_activity_snapshots_to_upload.*.pickups_count' => ['nullable', 'integer', 'min:0'],
            'digital_activity_snapshots_to_upload.*.first_offensive_use_min_after_wake' => ['nullable', 'integer', 'min:0'],
            'digital_activity_snapshots_to_upload.*.deep_work_sessions_count' => ['nullable', 'integer', 'min:0'],
            'digital_activity_snapshots_to_upload.*.deep_work_total_min' => ['nullable', 'integer', 'min:0'],
            'digital_activity_snapshots_to_upload.*.notifications_received' => ['nullable', 'integer', 'min:0'],
            'digital_activity_snapshots_to_upload.*.notifications_actioned' => ['nullable', 'integer', 'min:0'],
            'digital_activity_snapshots_to_upload.*.curated_input_min' => ['nullable', 'integer', 'min:0'],
            'digital_activity_snapshots_to_upload.*.algorithmic_input_min' => ['nullable', 'integer', 'min:0'],
            'digital_activity_snapshots_to_upload.*.intentional_entertainment_min' => ['nullable', 'integer', 'min:0'],
            'digital_activity_snapshots_to_upload.*.default_entertainment_min' => ['nullable', 'integer', 'min:0'],
            'digital_activity_snapshots_to_upload.*.communication_primary_min' => ['nullable', 'integer', 'min:0'],
            'digital_activity_snapshots_to_upload.*.communication_shallow_min' => ['nullable', 'integer', 'min:0'],
            'digital_activity_snapshots_to_upload.*.market_min' => ['nullable', 'integer', 'min:0'],
            'digital_activity_snapshots_to_upload.*.focus_mode_active_min' => ['sometimes', 'array'],
            'digital_activity_snapshots_to_upload.*.category_breakdown' => ['sometimes', 'array'],
            'digital_activity_snapshots_to_upload.*.source_breakdown' => ['sometimes', 'array'],
            'digital_activity_snapshots_to_upload.*.raw_rize_data' => ['sometimes', 'array'],
            'digital_activity_snapshots_to_upload.*.raw_screentime_data' => ['sometimes', 'array'],
            'digital_activity_snapshots_to_upload.*.metadata' => ['sometimes', 'array'],
            'metadata' => ['sometimes', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            'captures_to_upload.*.kind.in' => 'Sync JSON only accepts text captures. Upload audio/photo captures through POST /captures multipart.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $checkins = $this->input('checkins_to_upload', []);
            if (! is_array($checkins)) {
                return;
            }

            foreach ($checkins as $index => $checkin) {
                if (! is_array($checkin)) {
                    continue;
                }

                $this->rejectFutureCheckinRecordedAt(
                    $validator,
                    "checkins_to_upload.$index.recorded_at",
                    $checkin['recorded_at'] ?? null,
                );
            }
        });
    }
}
