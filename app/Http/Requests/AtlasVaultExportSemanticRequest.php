<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AtlasVaultExportSemanticRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'semantic_note_id' => ['required', 'uuid'],
            'dry_run' => ['nullable', 'boolean'],
            'write' => ['nullable', 'boolean'],
        ];
    }

    public function writeRequested(): bool
    {
        $dryRun = (bool) $this->boolean('dry_run', false);
        $write = (bool) $this->boolean('write', false);
        if ($dryRun === $write) {
            throw new \RuntimeException('Use exactly one of dry_run or write.');
        }

        return $write;
    }
}
