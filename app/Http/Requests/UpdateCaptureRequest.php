<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesMetadata;
use App\Http\Requests\Concerns\ValidatesAtlasDomain;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCaptureRequest extends FormRequest
{
    use NormalizesMetadata;
    use ValidatesAtlasDomain;

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
            'domain' => $this->atlasDomainRule(required: false),
            'content_text' => ['sometimes', 'nullable', 'string'],
            'content_duration_ms' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'captured_at' => ['sometimes', 'date'],
            'captured_timezone' => ['sometimes', 'string', 'max:128'],
            'captured_lat' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'captured_lng' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'pre_capture_digital_context' => ['sometimes', 'array'],
            'metadata' => ['sometimes', 'array'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $allowed = [
                'domain',
                'content_text',
                'content_duration_ms',
                'captured_at',
                'captured_timezone',
                'captured_lat',
                'captured_lng',
                'pre_capture_digital_context',
                'metadata',
            ];

            if (count(array_intersect($allowed, array_keys($this->all()))) === 0) {
                $validator->errors()->add('payload', 'At least one field is required.');
            }
        });
    }
}
