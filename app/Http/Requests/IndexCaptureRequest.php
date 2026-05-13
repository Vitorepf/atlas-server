<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesAtlasDomain;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexCaptureRequest extends FormRequest
{
    use ValidatesAtlasDomain;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'since' => ['sometimes', 'date'],
            'domain' => $this->atlasDomainRule(required: false),
            'kind' => ['sometimes', Rule::in(['audio', 'text', 'photo'])],
            'client_id' => ['sometimes', 'uuid'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'cursor' => ['sometimes', 'uuid'],
        ];
    }
}
