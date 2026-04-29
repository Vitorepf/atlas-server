<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FeedbackAiTraceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'feedback_score' => ['nullable', 'integer', 'between:1,5'],
            'feedback_action' => ['nullable', 'string', 'in:useful,not_useful,wrong_agent,wrong_context,too_slow,too_expensive,unsafe,dismissed'],
            'feedback_comment' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
