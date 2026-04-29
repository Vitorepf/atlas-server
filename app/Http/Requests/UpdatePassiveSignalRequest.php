<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesMetadata;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePassiveSignalRequest extends FormRequest
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
            'source' => ['sometimes', Rule::in(['healthkit', 'rize'])],
            'signal_type' => ['sometimes', 'string', 'max:128'],
            'value_numeric' => ['sometimes', 'nullable', 'numeric'],
            'value_text' => ['sometimes', 'nullable', 'string', 'max:1024'],
            'unit' => ['sometimes', 'nullable', 'string', 'max:64'],
            'started_at' => ['sometimes', 'date'],
            'ended_at' => ['sometimes', 'nullable', 'date'],
            'recorded_timezone' => ['sometimes', 'string', 'max:128'],
            'metadata' => ['sometimes', 'array'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $allowed = [
                'source',
                'signal_type',
                'value_numeric',
                'value_text',
                'unit',
                'started_at',
                'ended_at',
                'recorded_timezone',
                'metadata',
            ];

            if (count(array_intersect($allowed, array_keys($this->all()))) === 0) {
                $validator->errors()->add('payload', 'At least one field is required.');
            }
        });
    }
}
