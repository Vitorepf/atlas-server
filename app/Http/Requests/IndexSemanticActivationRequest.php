<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexSemanticActivationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'context_type' => ['nullable', Rule::in(['morning_briefing', 'capture_created', 'health_state', 'rize_context', 'weekly_review', 'manual_search', 'cognitive_game', 'notification_candidate'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
