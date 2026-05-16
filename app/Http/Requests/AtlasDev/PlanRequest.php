<?php

declare(strict_types=1);

namespace App\Http\Requests\AtlasDev;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Request validator for POST /ai/interactions/atlas-dev/plan.
 *
 * Surface adapters translate their native payload into these fields. The
 * core orchestrator never sees the raw HTTP body; the controller plucks
 * validated values out of here and calls planOnly() with primitives.
 */
final class PlanRequest extends FormRequest
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
            'surface_id' => ['required', 'string', 'min:1', 'max:64'],
            'workspace' => ['required', 'string', 'min:1', 'max:2048'],
            'raw_intent' => ['required', 'string', 'min:1', 'max:8192'],
            'user_constraints' => ['sometimes', 'array'],
            'user_constraints.*' => ['string', 'max:512'],
            'thread_id' => ['nullable', 'string', 'max:128'],
            'previous_run_id' => ['nullable', 'string', 'max:128'],
            'attachments' => ['sometimes', 'array'],
            'policy_hints' => ['sometimes', 'array'],
            'surface_context' => ['sometimes', 'array'],
        ];
    }

    /**
     * @return list<string>
     */
    public function userConstraintsList(): array
    {
        $raw = $this->input('user_constraints', []);
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = $item;
            }
        }

        return array_values($out);
    }

    /**
     * @return array<string, mixed>
     */
    public function surfaceHints(): array
    {
        $hints = [];

        $surfaceContext = $this->input('surface_context');
        if (is_array($surfaceContext)) {
            foreach (['thread_id', 'conversation_id', 'composer_mode', 'composer_task', 'provider_choice'] as $key) {
                $value = $surfaceContext[$key] ?? null;
                if (is_string($value) && $value !== '') {
                    $hints[$key] = $value;
                }
            }
        }

        $threadId = $this->input('thread_id');
        if (is_string($threadId) && $threadId !== '' && ! isset($hints['thread_id'])) {
            $hints['thread_id'] = $threadId;
        }

        $previousRunId = $this->input('previous_run_id');
        if (is_string($previousRunId) && $previousRunId !== '') {
            $hints['previous_run_id'] = $previousRunId;
        }

        $policyHints = $this->input('policy_hints');
        if (is_array($policyHints) && $policyHints !== []) {
            $hints['policy_hints'] = $policyHints;
        }

        return $hints;
    }
}
