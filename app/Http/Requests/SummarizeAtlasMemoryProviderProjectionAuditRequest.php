<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SummarizeAtlasMemoryProviderProjectionAuditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'target' => ['nullable', 'string', Rule::in(['claude', 'agents', 'all'])],
            'workspace' => ['nullable', 'string', 'max:2048'],
            'initiator' => ['nullable', 'string', Rule::in(['api', 'cli', 'system'])],
            'status' => ['nullable', 'string', Rule::in(['passed', 'needs_review', 'confirmation_required', 'cancelled'])],
            'ok' => ['nullable', 'boolean'],
        ];
    }
}
