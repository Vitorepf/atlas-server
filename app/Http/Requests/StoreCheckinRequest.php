<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesMetadata;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCheckinRequest extends FormRequest
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
            'client_id' => ['required', 'uuid'],
            'state' => ['required', Rule::in(['focused', 'disperse', 'blocked', 'pause'])],
            'energy_level' => ['required', 'integer', 'between:1,5'],
            'mood_level' => ['required', 'integer', 'between:1,5'],
            'note' => ['nullable', 'string'],
            'recorded_at' => ['required', 'date'],
            'recorded_timezone' => ['required', 'string', 'max:128'],
            'metadata' => ['array'],
        ];
    }
}
