<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesMetadata;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePassiveSignalRequest extends FormRequest
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
            'source' => ['required', Rule::in(['healthkit', 'rize'])],
            'signal_type' => ['required', 'string', 'max:128'],
            'value_numeric' => ['nullable', 'numeric'],
            'value_text' => ['nullable', 'string', 'max:1024'],
            'unit' => ['nullable', 'string', 'max:64'],
            'started_at' => ['required', 'date'],
            'ended_at' => ['nullable', 'date', 'after_or_equal:started_at'],
            'recorded_timezone' => ['required', 'string', 'max:128'],
            'metadata' => ['array'],
            'deleted_at' => ['nullable', 'date'],
        ];
    }
}
