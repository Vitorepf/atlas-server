<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexAtlasMemoryProviderProjectionAuditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            'target' => ['nullable', 'string', Rule::in(['claude', 'agents', 'all'])],
            'workspace' => ['nullable', 'string', 'max:2048'],
            'initiator' => ['nullable', 'string', Rule::in(['api', 'cli', 'system'])],
            'status' => ['nullable', 'string', Rule::in(['passed', 'needs_review', 'confirmation_required', 'cancelled'])],
            'ok' => ['nullable', 'boolean'],
        ];
    }
}
