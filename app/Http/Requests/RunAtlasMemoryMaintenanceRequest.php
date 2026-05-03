<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RunAtlasMemoryMaintenanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'workspace' => ['nullable', 'string', 'max:1000'],
            'dry_run' => ['nullable', 'boolean'],
            'sync' => ['nullable', 'boolean'],
            'index_code' => ['nullable', 'boolean'],
            'prune' => ['nullable', 'boolean'],
            'include_drift_audit' => ['nullable', 'boolean'],
            'apply_projection' => ['nullable', 'boolean'],
            'confirm' => ['nullable', 'boolean'],
        ];
    }
}
