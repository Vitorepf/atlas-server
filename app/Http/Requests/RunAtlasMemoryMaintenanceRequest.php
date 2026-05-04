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
            'promote_learnings' => ['nullable', 'boolean'],
            'auto_promote_candidates' => ['nullable', 'boolean'],
            'promotion_limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            'promotion_min_confidence' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'record_quality_snapshot' => ['nullable', 'boolean'],
            'enforce_quality' => ['nullable', 'boolean'],
            'include_drift_audit' => ['nullable', 'boolean'],
            'apply_projection' => ['nullable', 'boolean'],
            'confirm' => ['nullable', 'boolean'],
        ];
    }
}
