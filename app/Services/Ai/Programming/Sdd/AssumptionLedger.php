<?php

namespace App\Services\Ai\Programming\Sdd;

use App\Models\AtlasAssumption;
use App\Models\AtlasSpec;
use App\Services\Ai\Programming\Sdd\Enums\ConfidenceClass;

/**
 * Persists assumptions associated with a spec and tracks blocking ambiguity.
 *
 * Per autonomy-and-clarification-policy.md, assumptions classified as
 * `blocking_ambiguity` MUST be resolved before any execution receipt is
 * signed. The ClarificationGate consumes this ledger.
 */
class AssumptionLedger
{
    /**
     * @param  array<string,mixed>  $input
     */
    public function record(AtlasSpec $spec, array $input): AtlasAssumption
    {
        $confidence = $this->resolveConfidence($input['confidence_class'] ?? null);
        $blocking = $confidence->isBlocking() || (bool) ($input['blocking'] ?? false);

        return AtlasAssumption::query()->create([
            'spec_id' => $spec->id,
            'text' => (string) ($input['text'] ?? ''),
            'confidence_class' => $confidence->value,
            'blocking' => $blocking,
            'evidence_json' => array_values((array) ($input['evidence'] ?? [])),
            'clarification_questions_json' => array_values((array) ($input['questions'] ?? [])),
        ]);
    }

    public function resolve(AtlasAssumption $assumption, string $status, ?string $resolvedConfidence = null): void
    {
        $assumption->forceFill([
            'resolved_status' => $status,
            'resolved_at' => now(),
            'confidence_class' => $resolvedConfidence !== null
                ? $this->resolveConfidence($resolvedConfidence)->value
                : $assumption->confidence_class,
            'blocking' => false,
        ])->save();
    }

    /**
     * @return list<AtlasAssumption>
     */
    public function blockingFor(AtlasSpec $spec): array
    {
        return $spec->assumptions()
            ->blocking()
            ->orderBy('created_at')
            ->get()
            ->all();
    }

    public function hasBlocking(AtlasSpec $spec): bool
    {
        return $spec->assumptions()->blocking()->exists();
    }

    private function resolveConfidence(mixed $value): ConfidenceClass
    {
        if ($value instanceof ConfidenceClass) {
            return $value;
        }
        if (! is_string($value)) {
            return ConfidenceClass::Hypothesis;
        }

        return ConfidenceClass::tryFrom($value) ?? ConfidenceClass::Hypothesis;
    }
}
