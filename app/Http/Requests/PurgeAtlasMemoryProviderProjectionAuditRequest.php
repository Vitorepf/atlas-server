<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PurgeAtlasMemoryProviderProjectionAuditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'older_than_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'dry_run' => ['nullable', 'boolean'],
            'confirm' => ['nullable', 'boolean'],
            'confirmation_fingerprint' => ['nullable', 'string', 'size:64'],
            'target' => ['nullable', 'string', Rule::in(['claude', 'agents', 'all'])],
            'workspace' => ['nullable', 'string', 'max:2048'],
            'initiator' => ['nullable', 'string', Rule::in(['api', 'cli', 'system'])],
            'status' => ['nullable', 'string', Rule::in(['passed', 'needs_review', 'confirmation_required', 'cancelled'])],
            'ok' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $dryRun = filter_var($this->input('dry_run', true), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            $confirm = filter_var($this->input('confirm', false), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($dryRun === false && $confirm !== true) {
                $validator->errors()->add('confirm', 'Confirme explicitamente para executar purge de auditorias.');
            }
        });
    }
}
