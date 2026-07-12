<?php

namespace App\Services\Ai\Rivals\Core;

use App\Services\Ai\Rivals\Support\AtomicWriter;
use App\Services\Ai\Rivals\Support\RunPaths;
use InvalidArgumentException;

/** Frozen statistical analysis contract created before native execution. */
final class Preregistration
{
    public const SCHEMA = 'atlas.rivals2.preregistration.v1';

    private function __construct(public readonly array $data) {}

    public static function fromPlan(RunPlan $plan): self
    {
        $tier = (string) $plan->data['claim_tier'];
        $public = $tier === ClaimTier::PUBLIC;
        $payload = [
            'schema_version' => self::SCHEMA,
            'run_id' => $plan->runId(),
            'revision' => 1,
            'hypothesis' => 'scoped_resolution_and_efficiency',
            'primary_endpoint' => 'success_rate',
            'secondary_endpoints' => [
                'cost_per_task',
                'wall_time',
                'tokens',
                'failure_classes',
                'native_dimensions',
            ],
            'analysis_population' => 'intention_to_treat',
            'pairing_key' => 'case_id|repetition',
            'suite_id' => $plan->data['suite_id'],
            'claim_tier' => $tier,
            'case_ids' => $plan->data['case_ids'],
            'arm_ids' => array_column($plan->data['arms'], 'arm_id'),
            'comparisons' => array_values((array) ($plan->data['comparisons'] ?? [])),
            'repetitions' => $plan->data['repetitions'],
            'seed' => $plan->data['seed'],
            'alpha' => 0.05,
            'target_power' => 0.90,
            'multiplicity' => [
                'method' => 'holm',
                'family' => 'all_primary_comparisons',
            ],
            'min_distinct_cases' => (int) config(
                $public
                    ? 'atlas_rivals.claim.min_distinct_cases_public'
                    : 'atlas_rivals.claim.min_distinct_cases_internal',
                $public ? 10 : 3,
            ),
            'max_ci_width' => (float) config(
                $public
                    ? 'atlas_rivals.claim.max_ci_width_public'
                    : 'atlas_rivals.claim.max_ci_width_internal',
                $public ? 0.25 : 0.50,
            ),
            'missing_data_policy' => [
                'imputation' => 'forbidden',
                'primary_denominator' => 'all_planned_attempts',
                'environment_failures' => 'reported_in_itt_and_separate_conditional_rate',
                'timeouts' => 'failure_for_resolution_and_censored_at_timeout_for_time',
            ],
            'outlier_policy' => 'retain_and_flag_never_drop',
            'stopping_rules' => [
                'budget_usd' => (float) ($plan->data['budget']['max_usd'] ?? 0.0),
                'max_minutes' => (int) ($plan->data['budget']['max_minutes'] ?? 0),
                'negative_multiplier' => 'stop_the_line',
            ],
            'expansion_policy' => 'new_revision_never_merge_post_hoc_silently',
            'created_at' => now()->toIso8601String(),
        ];
        $payload['preregistration_hash'] = self::hashPayload($payload);

        return self::fromArray($payload);
    }

    public static function fromArray(array $data): self
    {
        foreach ([
            'schema_version', 'run_id', 'revision', 'hypothesis',
            'primary_endpoint', 'secondary_endpoints', 'analysis_population',
            'pairing_key', 'suite_id', 'claim_tier', 'case_ids', 'arm_ids',
            'comparisons', 'repetitions', 'seed', 'alpha', 'target_power', 'multiplicity',
            'min_distinct_cases', 'max_ci_width', 'missing_data_policy',
            'outlier_policy', 'stopping_rules', 'expansion_policy',
            'created_at', 'preregistration_hash',
        ] as $field) {
            if (! array_key_exists($field, $data)) {
                throw new InvalidArgumentException("rivals_preregistration_missing:{$field}");
            }
        }
        if ($data['schema_version'] !== self::SCHEMA) {
            throw new InvalidArgumentException('rivals_preregistration_schema_mismatch');
        }
        ClaimTier::assert((string) $data['claim_tier']);
        if (! hash_equals(
            (string) $data['preregistration_hash'],
            self::hashPayload($data),
        )) {
            throw new InvalidArgumentException('rivals_preregistration_hash_mismatch');
        }

        return new self($data);
    }

    public static function load(string $runId): self
    {
        $path = RunPaths::preregistrationPath($runId);
        if (! is_file($path)) {
            throw new InvalidArgumentException("rivals_preregistration_not_found:{$runId}");
        }

        return self::fromArray(json_decode((string) file_get_contents($path), true) ?? []);
    }

    public function persist(): string
    {
        $path = RunPaths::preregistrationPath((string) $this->data['run_id']);
        AtomicWriter::write(
            $path,
            json_encode(
                $this->data,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
            )
        );

        return $path;
    }

    public function hash(): string
    {
        return (string) $this->data['preregistration_hash'];
    }

    /** @param array<string, mixed> $payload */
    public static function hashPayload(array $payload): string
    {
        unset($payload['preregistration_hash']);

        return hash('sha256', json_encode(
            self::canonicalize($payload),
            JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonicalize(...), $value);
    }
}
