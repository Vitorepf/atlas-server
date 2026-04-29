<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexSemanticCurationProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::in(['pending', 'accepted', 'edited', 'dismissed', 'postponed'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
