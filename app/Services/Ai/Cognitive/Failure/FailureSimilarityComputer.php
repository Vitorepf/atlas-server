<?php

namespace App\Services\Ai\Cognitive\Failure;

use App\Services\Ai\Kernel\Slo\KernelSloProbe;

class FailureSimilarityComputer
{
    public function __construct(
        private readonly KernelSloProbe $slo,
    ) {}

    /**
     * @param  array<string,mixed>  $signature
     * @param  array<int,array<string,mixed>>  $previous
     */
    public function similarity(array $signature, array $previous): float
    {
        return $this->slo->measure('cognitive.failure.similarity', function () use ($signature, $previous): float {
            if ($previous === []) {
                return 0.0;
            }

            $key = $this->signatureKey($signature);
            $best = 0.0;

            foreach ($previous as $row) {
                $rowKey = (string) ($row['signature_key'] ?? $this->signatureKey($row));
                if ($rowKey === $key) {
                    return 1.0;
                }

                if (($row['category'] ?? null) === ($signature['category'] ?? null)
                    && ($row['sub_cause'] ?? null) === ($signature['sub_cause'] ?? null)) {
                    $best = max($best, 0.85);

                    continue;
                }

                if (($row['category'] ?? null) === ($signature['category'] ?? null)) {
                    $best = max($best, 0.55);
                }
            }

            return round($best, 3);
        }, [
            'domain' => (string) ($signature['domain'] ?? 'unknown'),
            'category' => (string) ($signature['category'] ?? 'unknown'),
        ]);
    }

    /**
     * @param  array<string,mixed>  $signature
     */
    public function signatureKey(array $signature): string
    {
        $features = (array) ($signature['canonical_features'] ?? []);
        $identity = [
            'domain' => (string) ($signature['domain'] ?? 'unknown'),
            'category' => (string) ($signature['category'] ?? 'technical'),
            'sub_cause' => (string) ($signature['sub_cause'] ?? 'unknown'),
            'failure_domain' => (string) data_get($features, 'classification.failure_domain', data_get($features, 'failure_domain', 'unknown')),
            'event_type' => (string) data_get($features, 'event_type', 'unknown'),
        ];

        return 'fsig_'.substr(hash('sha256', json_encode($this->canonicalize($identity), JSON_THROW_ON_ERROR)), 0, 32);
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private function canonicalize(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }

        ksort($value);

        return $value;
    }
}
