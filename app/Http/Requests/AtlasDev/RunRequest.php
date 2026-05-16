<?php

declare(strict_types=1);

namespace App\Http\Requests\AtlasDev;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Request validator for POST /ai/interactions/atlas-dev/run.
 *
 * Validation hard preconditions enforced here (422 on failure):
 *   - run_id present and well-formed.
 *   - task_contract_hash present and 64-char lowercase hex sha256.
 *   - confirmation_token present and sized like the minted token.
 *   - operator_confirmed present (presence only; the controller enforces
 *     "literal boolean true" with a 400 response per the canon contract).
 */
final class RunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'run_id' => ['required', 'string', 'regex:/^[A-Za-z0-9._-]{1,128}$/'],
            'task_contract_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'confirmation_token' => ['required', 'string', 'min:32', 'max:128'],
            'operator_confirmed' => ['required'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function messages(): array
    {
        return [
            'task_contract_hash.regex' => 'task_contract_hash must be a 64-char lowercase hex sha256.',
            'run_id.regex' => 'run_id must match Atlas Dev run-id pattern.',
        ];
    }
}
