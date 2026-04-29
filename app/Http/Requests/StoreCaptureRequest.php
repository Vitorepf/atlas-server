<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesMetadata;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCaptureRequest extends FormRequest
{
    use NormalizesMetadata;

    protected function prepareForValidation(): void
    {
        $this->normalizeMetadata();

        if ($this->has('pre_capture_digital_context') && is_string($this->input('pre_capture_digital_context'))) {
            $decoded = json_decode($this->input('pre_capture_digital_context'), true);
            $this->merge([
                'pre_capture_digital_context' => json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : null,
            ]);
        }

        if (! $this->has('domain')) {
            $this->merge(['domain' => 'outro']);
        }

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
        $maxUploadKb = max(1, (int) ceil(config('atlas.max_upload_bytes') / 1024));

        return [
            'client_id' => ['required', 'uuid'],
            'kind' => ['required', Rule::in(['audio', 'text', 'photo'])],
            'domain' => ['required', Rule::in(['blackink', 'saude', 'financas', 'outro'])],
            'content_text' => [
                Rule::requiredIf(fn () => $this->input('kind') === 'text'),
                'nullable',
                'string',
            ],
            'file' => [
                Rule::requiredIf(fn () => in_array($this->input('kind'), ['audio', 'photo'], true)),
                Rule::prohibitedIf(fn () => $this->input('kind') === 'text'),
                'file',
                'max:'.$maxUploadKb,
            ],
            'content_duration_ms' => ['nullable', 'integer', 'min:0'],
            'captured_at' => ['required', 'date'],
            'captured_timezone' => ['required', 'string', 'max:128'],
            'captured_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'captured_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'pre_capture_digital_context' => ['sometimes', 'array'],
            'metadata' => ['array'],
        ];
    }
}
