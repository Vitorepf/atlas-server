<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesMetadata;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCheckinRequest extends FormRequest
{
    use NormalizesMetadata;

    protected function prepareForValidation(): void
    {
        $this->normalizeMetadata();
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'state' => ['sometimes', Rule::in(['focused', 'disperse', 'blocked', 'pause'])],
            'energy_level' => ['sometimes', 'integer', 'between:1,5'],
            'mood_level' => ['sometimes', 'integer', 'between:1,5'],
            'note' => ['sometimes', 'nullable', 'string'],
            'recorded_at' => ['sometimes', 'date'],
            'recorded_timezone' => ['sometimes', 'string', 'max:128'],
            'metadata' => ['sometimes', 'array'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $allowed = ['state', 'energy_level', 'mood_level', 'note', 'recorded_at', 'recorded_timezone', 'metadata'];

            if (count(array_intersect($allowed, array_keys($this->all()))) === 0) {
                $validator->errors()->add('payload', 'At least one field is required.');
            }
        });
    }
}
