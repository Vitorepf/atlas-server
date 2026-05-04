<?php

namespace App\Http\Requests;

use App\Services\Semantic\AtlasVaultSyncService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AtlasVaultResolveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'resolution' => ['required', 'string', Rule::in(AtlasVaultSyncService::RESOLUTION_ACTIONS)],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
