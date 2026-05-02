<?php

namespace App\Http\Requests;

use App\Models\AtlasMemoryEntryUsage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FeedbackAtlasMemoryUsageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'feedback_action' => ['required', 'string', Rule::in(AtlasMemoryEntryUsage::FEEDBACK_ACTIONS)],
            'feedback_score' => ['nullable', 'integer', 'between:1,5'],
            'feedback_comment' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
