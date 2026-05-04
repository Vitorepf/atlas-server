<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AtlasVaultImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'path' => ['required', 'string', 'max:1000'],
            'dry_run' => ['nullable', 'boolean'],
            'write' => ['nullable', 'boolean'],
        ];
    }

    public function writeRequested(): bool
    {
        $dryRun = (bool) $this->boolean('dry_run', false);
        $write = (bool) $this->boolean('write', false);
        if ($dryRun === $write) {
            abort(422, 'Use exactly one of dry_run or write.');
        }

        return $write;
    }
}
