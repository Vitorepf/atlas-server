<?php

declare(strict_types=1);

namespace App\Services\Ai\Compounding;

use InvalidArgumentException;

final readonly class CausalLearningCandidate
{
    private function __construct(public array $data, public string $candidateHash) {}

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $required = ['assignment_hash','experiment_hash','run_hash','release_hash','outcome_hash','change_class','hypothesis','baseline','metric','window','effect','ci_low','ci_high','confounders','rollback','reversible','assignment_precedes_run','real_outcome','authority_hash','scope','expiry'];
        foreach ($required as $key) {
            if (! array_key_exists($key, $data)) throw new InvalidArgumentException('causal_candidate_'.$key.'_required');
        }
        foreach (['assignment_hash','experiment_hash','run_hash','release_hash','outcome_hash','authority_hash'] as $key) {
            if (preg_match('/^[a-f0-9]{64}$/', (string) $data[$key]) !== 1) throw new InvalidArgumentException('causal_candidate_'.$key.'_invalid');
        }
        if (! in_array($data['change_class'], ['routing','memory_policy','operational_policy','code_task'], true)) throw new InvalidArgumentException('causal_candidate_change_class_invalid');
        foreach (['hypothesis','baseline','metric','window','rollback','scope','expiry'] as $key) if (! is_string($data[$key]) || trim($data[$key]) === '') throw new InvalidArgumentException('causal_candidate_'.$key.'_required');
        foreach (['reversible','assignment_precedes_run','real_outcome'] as $key) if (! is_bool($data[$key])) throw new InvalidArgumentException('causal_candidate_'.$key.'_invalid');
        foreach (['effect','ci_low','ci_high'] as $key) if (! is_numeric($data[$key])) throw new InvalidArgumentException('causal_candidate_'.$key.'_invalid');
        if (! is_array($data['confounders']) || $data['confounders'] === []) throw new InvalidArgumentException('causal_candidate_confounders_required');
        $canonical = $data; unset($canonical['candidate_hash']); ksort($canonical);
        $hash = CompoundingHash::make($canonical);
        if (isset($data['candidate_hash']) && preg_match('/^[a-f0-9]{64}$/', (string) $data['candidate_hash']) === 1 && ! hash_equals((string) $data['candidate_hash'], $hash)) throw new InvalidArgumentException('causal_candidate_hash_mismatch');

        return new self($canonical, $hash);
    }

    /** @return array<string,mixed> */
    public function toArray(): array { return $this->data + ['candidate_hash' => $this->candidateHash]; }
}
