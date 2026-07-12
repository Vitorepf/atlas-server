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
        $required = ['assignment_hash','experiment_hash','order_hash','run_hash','release_hash','outcome_hash','change_class','hypothesis','baseline','metric','window','effect','ci_low','ci_high','confounders','rollback','reversible','assignment_precedes_run','real_outcome','authority_hash','scope','expiry','assignment_at','release_at','run_at','outcome_at','binding_refs'];
        foreach ($required as $key) {
            if (! array_key_exists($key, $data)) throw new InvalidArgumentException('causal_candidate_'.$key.'_required');
        }
        foreach (['assignment_hash','experiment_hash','order_hash','run_hash','release_hash','outcome_hash','authority_hash'] as $key) {
            if (preg_match('/^[a-f0-9]{64}$/', (string) $data[$key]) !== 1) throw new InvalidArgumentException('causal_candidate_'.$key.'_invalid');
        }
        if (! in_array($data['change_class'], ['routing','memory_policy','operational_policy','simplification','code_task'], true)) throw new InvalidArgumentException('causal_candidate_change_class_invalid');
        foreach (['hypothesis','baseline','metric','window','rollback','scope','expiry'] as $key) if (! is_string($data[$key]) || trim($data[$key]) === '') throw new InvalidArgumentException('causal_candidate_'.$key.'_required');
        if (date_create_immutable((string) $data['expiry']) === false) throw new InvalidArgumentException('causal_candidate_expiry_invalid');
        foreach (['assignment_at','release_at','run_at','outcome_at'] as $key) {
            if (! is_string($data[$key]) || date_create_immutable($data[$key]) === false) throw new InvalidArgumentException('causal_candidate_'.$key.'_invalid');
        }
        if (! is_array($data['binding_refs'])) throw new InvalidArgumentException('causal_candidate_binding_refs_invalid');
        foreach (['assignment','experiment','order','run','release','outcome','authority'] as $binding) {
            $ref = $data['binding_refs'][$binding] ?? null;
            $hashKey = $binding.'_hash';
            if (! is_array($ref) || ! is_string($ref['hash'] ?? null) || ! hash_equals((string) $data[$hashKey], $ref['hash'])) {
                throw new InvalidArgumentException('causal_candidate_binding_ref_mismatch_'.$binding);
            }
            if (! is_string($ref['artifact_id'] ?? null) || trim($ref['artifact_id']) === '') {
                throw new InvalidArgumentException('causal_candidate_binding_ref_artifact_id_'.$binding);
            }
        }
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
