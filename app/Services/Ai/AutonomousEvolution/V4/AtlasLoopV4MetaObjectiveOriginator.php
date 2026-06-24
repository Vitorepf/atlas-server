<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\V4;

final class AtlasLoopV4MetaObjectiveOriginator
{
    private const BUCKETS = ['origination', 'delivery', 'capability'];

    private const PROXY_TOKENS = [
        'refactor',
        'cleanup',
        'coverage',
        'complexity',
        'cyclomatic',
        'lint',
        'dead code',
        'style',
    ];

    /** @var (callable(array<string,mixed>, array<string,mixed>, array<string,mixed>): array<string,mixed>|null)|null */
    private $writer;

    /** @param (callable(array<string,mixed>, array<string,mixed>, array<string,mixed>): array<string,mixed>|null)|null $writer */
    public function __construct(?callable $writer = null)
    {
        $this->writer = $writer;
    }

    /**
     * @param  array<string,mixed>  $originationOutcomes
     * @param  array<string,mixed>  $deliveryOutcomes
     * @param  array<string,mixed>  $capabilityTrend
     * @return array{originated:bool,objective:?string,target_metric:?string,target_delta:?float,cited_facts:list<string>,reason:?string}
     */
    public function originate(array $originationOutcomes, array $deliveryOutcomes, array $capabilityTrend): array
    {
        if ($this->writer === null) {
            return $this->refuse('no_writer');
        }

        $proposal = ($this->writer)($originationOutcomes, $deliveryOutcomes, $capabilityTrend);
        if (! is_array($proposal)) {
            return $this->refuse('no_writer');
        }

        $objective = trim((string) ($proposal['objective'] ?? ''));
        $citedFacts = $this->normalizedCitations((array) ($proposal['cited_facts'] ?? []));
        if ($objective === '' || $citedFacts === []) {
            return $this->refuse('ungrounded_proposal');
        }

        if ($this->containsProxyVocabulary($objective)) {
            return $this->refuse('proxy_vocabulary_refused', citedFacts: $citedFacts);
        }

        $facts = $this->factIndex($originationOutcomes, $deliveryOutcomes, $capabilityTrend);
        if (! $this->citationsGrounded($citedFacts, $facts)) {
            return $this->refuse('citations_refuted_by_fact_judge', citedFacts: $citedFacts);
        }

        $targetMetric = trim((string) ($proposal['target_metric'] ?? ''));
        if (! in_array($targetMetric, self::BUCKETS, true)) {
            return $this->refuse('target_metric_out_of_scope', citedFacts: $citedFacts);
        }

        $targetDelta = $this->finiteFloat($proposal['target_delta'] ?? null);
        if ($targetDelta === null || $targetDelta <= 0.0) {
            return $this->refuse('target_delta_not_positive', citedFacts: $citedFacts);
        }

        if (abs($targetDelta) > $this->reachabilityCeiling($capabilityTrend)) {
            return $this->refuse('target_delta_exceeds_reachability_ceiling', citedFacts: $citedFacts);
        }

        return [
            'originated' => true,
            'objective' => $objective,
            'target_metric' => $targetMetric,
            'target_delta' => $targetDelta,
            'cited_facts' => $citedFacts,
            'reason' => null,
        ];
    }

    /**
     * @param  list<string>  $citedFacts
     * @return array{originated:false,objective:null,target_metric:null,target_delta:null,cited_facts:list<string>,reason:string}
     */
    private function refuse(string $reason, array $citedFacts = []): array
    {
        return [
            'originated' => false,
            'objective' => null,
            'target_metric' => null,
            'target_delta' => null,
            'cited_facts' => $citedFacts,
            'reason' => $reason,
        ];
    }

    /**
     * @param  list<mixed>  $facts
     * @return list<string>
     */
    private function normalizedCitations(array $facts): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $fact): string => trim((string) $fact),
            $facts,
        ), static fn (string $fact): bool => $fact !== ''));
    }

    private function containsProxyVocabulary(string $objective): bool
    {
        $objective = strtolower($objective);
        foreach (self::PROXY_TOKENS as $token) {
            if (str_contains($objective, $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $citedFacts
     * @param  array<string,array{value:string,numeric:bool}>  $facts
     */
    private function citationsGrounded(array $citedFacts, array $facts): bool
    {
        $numericCitations = 0;
        foreach ($citedFacts as $citation) {
            if (! isset($facts[$citation])) {
                return false;
            }
            if ($facts[$citation]['numeric']) {
                $numericCitations++;
            }
        }

        return $numericCitations > 0;
    }

    /**
     * @param  array<string,mixed>  $origination
     * @param  array<string,mixed>  $delivery
     * @param  array<string,mixed>  $capability
     * @return array<string,array{value:string,numeric:bool}>
     */
    private function factIndex(array $origination, array $delivery, array $capability): array
    {
        return array_merge(
            $this->bucketFacts('origination', $origination),
            $this->bucketFacts('delivery', $delivery),
            $this->bucketFacts('capability', $capability),
        );
    }

    /**
     * @param  array<string,mixed>  $values
     * @return array<string,array{value:string,numeric:bool}>
     */
    private function bucketFacts(string $bucket, array $values, string $prefix = ''): array
    {
        $facts = [];
        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.(string) $key;
            if (is_array($value)) {
                $facts += $this->bucketFacts($bucket, $value, $path);

                continue;
            }
            if (! is_scalar($value)) {
                continue;
            }

            $fact = $bucket.'.'.$path.'='.$this->scalarString($value);
            $facts[$fact] = ['value' => $this->scalarString($value), 'numeric' => is_int($value) || is_float($value)];
        }

        return $facts;
    }

    /**
     * @param  array<string,mixed>  $capabilityTrend
     */
    private function reachabilityCeiling(array $capabilityTrend): float
    {
        $max = 0.0;
        foreach ($this->numericValues($capabilityTrend) as $value) {
            $max = max($max, abs($value));
        }

        return $max * 2.0;
    }

    /**
     * @param  array<string,mixed>  $values
     * @return list<float>
     */
    private function numericValues(array $values): array
    {
        $numbers = [];
        foreach ($values as $value) {
            if (is_array($value)) {
                array_push($numbers, ...$this->numericValues($value));

                continue;
            }
            if (is_int($value) || is_float($value)) {
                $numbers[] = (float) $value;
            }
        }

        return $numbers;
    }

    private function finiteFloat(mixed $value): ?float
    {
        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
            return null;
        }

        $float = (float) $value;

        return is_finite($float) ? $float : null;
    }

    private function scalarString(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }
}
