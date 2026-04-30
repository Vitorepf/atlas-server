<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TriageCaptureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action' => [
                'required',
                Rule::in([
                    'promote',
                    'archive',
                    'snooze',
                    'attach_note',
                    'create_task',
                    'create_project',
                    'create_hypothesis',
                ]),
            ],
            'title' => ['nullable', 'string', 'max:180'],
            'note_id' => ['nullable', 'uuid', 'exists:semantic_notes,id'],
            'note_title' => ['nullable', 'string', 'max:180'],
            'snoozed_until' => ['nullable', 'date', 'after:now'],
            'due_at' => ['nullable', 'date'],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'planned_for_date' => ['nullable', 'date'],
            'planned_start_at' => ['nullable', 'date'],
            'planned_end_at' => ['nullable', 'date'],
            'estimated_minutes' => ['nullable', 'integer', 'min:5', 'max:480'],
            'energy_required' => ['nullable', Rule::in(['low', 'medium', 'high'])],
            'urgency_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'impact_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'effort_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'priority_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'goal' => ['nullable', 'string', 'max:500'],
            'next_action' => ['nullable', 'string', 'max:300'],
            'project_type' => ['nullable', Rule::in(['study', 'technical_build', 'creative', 'business', 'research', 'writing', 'health', 'admin', 'personal', 'tedious', 'routine_candidate'])],
            'desired_outcome' => ['nullable', 'string', 'max:1500'],
            'minimum_viable_outcome' => ['nullable', 'string', 'max:1500'],
            'definition_of_done' => ['nullable', 'string', 'max:1500'],
            'why_now' => ['nullable', 'string', 'max:1000'],
            'deadline_at' => ['nullable', 'date'],
            'deadline_kind' => ['nullable', Rule::in(['real', 'desired', 'artificial', 'none'])],
            'energy_profile' => ['nullable', Rule::in(['low', 'medium', 'high', 'mixed'])],
            'avoidance_reason' => ['nullable', Rule::in(['unclear', 'boring', 'too_large', 'scary', 'perfectionism', 'no_reward', 'low_energy', 'dependency', 'unknown'])],
            'execution_mode' => ['nullable', Rule::in(['quick_win', 'deep_work', 'admin', 'study', 'tedious', 'creative', 'decision', 'maintenance', 'recovery'])],
            'friction_level' => ['nullable', 'integer', 'min:0', 'max:100'],
            'emotional_resistance' => ['nullable', 'integer', 'min:0', 'max:100'],
            'clarity_level' => ['nullable', 'integer', 'min:0', 'max:100'],
            'starter_step' => ['nullable', 'string', 'max:300'],
            'minimum_viable_action' => ['nullable', 'string', 'max:300'],
            'if_then_plan' => ['nullable', 'string', 'max:500'],
            'reward_hint' => ['nullable', 'string', 'max:300'],
            'reason' => ['nullable', 'string', 'max:500'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($this->input('action') === 'snooze' && ! $this->filled('snoozed_until')) {
                $validator->errors()->add('snoozed_until', 'A snooze date is required.');
            }

            if ($this->input('action') === 'attach_note' && ! $this->filled('note_id')) {
                $validator->errors()->add('note_id', 'A valid note id is required.');
            }
        });
    }
}
