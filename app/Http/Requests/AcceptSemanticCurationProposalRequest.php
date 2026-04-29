<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AcceptSemanticCurationProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'path' => ['nullable', 'string', 'max:500'],
            'frontmatter_edits' => ['nullable', 'array'],
            'body_edits' => ['nullable', 'string'],
        ];
    }
}
