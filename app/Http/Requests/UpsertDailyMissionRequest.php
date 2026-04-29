<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesMetadata;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertDailyMissionRequest extends FormRequest
{
    use NormalizesMetadata;

    protected function prepareForValidation(): void
    {
        $this->normalizeMetadata();

        if (! $this->has('metadata')) {
            $this->merge(['metadata' => []]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'timezone' => ['required', 'timezone', 'max:128'],
            'title' => ['required', 'string', 'max:140'],
            'detail' => ['nullable', 'string', 'max:2048'],
            'status' => ['sometimes', Rule::in(['active', 'done', 'skipped'])],
            'metadata' => ['array'],
        ];
    }
}
