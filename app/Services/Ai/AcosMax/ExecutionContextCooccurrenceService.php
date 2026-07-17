<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Services\Ai\Support\AiValueNormalizer;

final class ExecutionContextCooccurrenceService
{
    public const SCHEMA_VERSION = 'atlas.context.execution_cooccurrence.v1';

    public const MEASURE_ID = 'atlas.context.execution_cooccurrence.v1';

    public const FORMULA_VERSION = 'atlas_context_execution_cooccurrence_v1';

    /**
     * @return array<string,mixed>
     */
    public function report(?string $runsPath = null): array
    {
        $base = [
            'schema_version' => self::SCHEMA_VERSION,
            'measure_id' => self::MEASURE_ID,
            'formula_version' => self::FORMULA_VERSION,
            'generated_at' => now()->toIso8601String(),
            'context_causal_binding' => 'correlational_cooccurrence',
            'enforcement_allowed' => false,
            'requires_counterfactual_before_enforcement' => GoldenCounterfactualReplayService::MEASURE_ID,
            'claim_policy' => [
                'read_only' => true,
                'provider_calls_made' => false,
                'memory_written' => false,
                'feeds_enforcement' => false,
                'intersection_alone_is_not_causal' => true,
            ],
        ];

        if ($runsPath === null || AiValueNormalizer::trimmedString($runsPath) === '' || ! is_file($runsPath)) {
            return array_replace($base, [
                'status' => 'unmeasurable',
                'reason' => 'run_artifact_unavailable',
                'runs_path' => $runsPath,
                'denominator' => [
                    'runs' => 0,
                    'measured_runs' => 0,
                    'measured_share' => 0.0,
                ],
                'cooccurrences' => [],
            ]);
        }

        $runs = array_values((array) ($this->readJson($runsPath)['runs'] ?? []));
        $measured = [];
        foreach ($runs as $run) {
            if (! is_array($run) || ($run['measured'] ?? false) !== true) {
                continue;
            }
            $measured[] = $run;
        }

        $runCount = count($runs);
        $measuredCount = count($measured);
        $measuredShare = $runCount > 0 ? round($measuredCount / $runCount, 6) : 0.0;
        if ($measuredCount === 0) {
            return array_replace($base, [
                'status' => 'unmeasurable',
                'reason' => 'measured_share_zero',
                'runs_path' => $runsPath,
                'denominator' => [
                    'runs' => $runCount,
                    'measured_runs' => 0,
                    'measured_share' => $measuredShare,
                ],
                'cooccurrences' => [],
            ]);
        }

        $cooccurrences = [];
        foreach ($measured as $run) {
            $delivered = $this->stringList((array) ($run['delivered_refs'] ?? []));
            $used = $this->stringList((array) ($run['used_refs'] ?? []));
            $intersection = array_values(array_intersect($delivered, $used));
            if ($intersection === []) {
                continue;
            }
            $cooccurrences[] = [
                'run_id' => (string) ($run['run_id'] ?? ''),
                'outcome_receipt_id' => (string) ($run['outcome_receipt_id'] ?? ''),
                'green_run' => ($run['green_run'] ?? false) === true,
                'delivered_ref_count' => count($delivered),
                'used_ref_count' => count($used),
                'intersection_refs' => $intersection,
                'context_causal_binding' => 'correlational_cooccurrence',
            ];
        }

        return array_replace($base, [
            'status' => 'ok',
            'reason' => null,
            'runs_path' => $runsPath,
            'denominator' => [
                'runs' => $runCount,
                'measured_runs' => $measuredCount,
                'measured_share' => $measuredShare,
            ],
            'cooccurrence_count' => count($cooccurrences),
            'cooccurrences' => $cooccurrences,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function readJson(string $path): array
    {
        $raw = file_get_contents($path);
        if (AiValueNormalizer::trimmedStringOrNull($raw) === null) {
            return [];
        }
        $decoded = json_decode($raw, true);

        return AiValueNormalizer::arrayOrEmpty($decoded);
    }

    /**
     * @param  list<mixed>  $values
     * @return list<string>
     */
    private function stringList(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            $value = AiValueNormalizer::trimmedString($value);
            if ($value !== '' && ! in_array($value, $out, true)) {
                $out[] = $value;
            }
        }

        sort($out);

        return $out;
    }
}
