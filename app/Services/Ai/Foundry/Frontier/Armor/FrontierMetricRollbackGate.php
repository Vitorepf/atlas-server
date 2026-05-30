<?php

declare(strict_types=1);

namespace App\Services\Ai\Foundry\Frontier\Armor;

use App\Services\Ai\Foundry\FoundrySchemas;
use App\Services\Ai\Mission\MissionCanonicalHash;

/**
 * Foundry AP-C Armor · Invariant I2 (Metric + Rollback) — inbox-entry gate.
 *
 * GENERATES NOTHING, WRITES NOTHING canonical, EXECUTES NOTHING. This is the
 * LAST adversarial armor stage and runs at the inbox-admission boundary: a
 * survivor is admissible ONLY when it declares a FALSIFIABLE success_metric
 * (parseable as {operator, baseline, threshold}) AND a COMPLETE rollback
 * (carrying rollback_condition). Verification happens BEFORE admission; a
 * proposal that fails any check is DROPPED with a machine-readable reason.
 *
 * Drop reasons (each independently able to drop a proposal):
 *   - metric_or_rollback_absent      : success_metric OR rollback empty/missing
 *   - metric_not_falsifiable         : success_metric not parseable as a metric
 *   - rollback_contract_incomplete   : rollback present but no rollback_condition
 *
 * On pass, the gate stamps a deterministic rollback_contract
 * {metric_id, baseline_hash, rollback_condition} (sha256 via
 * MissionCanonicalHash) whose shape MIRRORS the merge-governor handoff for
 * downstream compat. It NEVER mutates the provenanced proposal: the inbox-entry
 * shape re-check runs FoundrySchemas::validateShape(EVOLUTION_PROPOSAL) on the
 * 13-key STRIPPED PROJECTION only (provenance keys stripped), so a
 * fully-provenanced proposal still passes the shape gate.
 */
final class FrontierMetricRollbackGate
{
    public const STAGE_ID = 'I2';

    public const DROP_METRIC_OR_ROLLBACK_ABSENT = 'metric_or_rollback_absent';

    public const DROP_METRIC_NOT_FALSIFIABLE = 'metric_not_falsifiable';

    public const DROP_ROLLBACK_CONTRACT_INCOMPLETE = 'rollback_contract_incomplete';

    /**
     * The 13 canonical keys of atlas.foundry.evolution_proposal.v1. Any extra
     * (provenance) key makes validateShape() report valid:false, so the gate
     * always validates the STRIPPED projection — never the raw object.
     *
     * @var list<string>
     */
    private const PROJECTION_KEYS = [
        'proposal_id', 'horizon', 'title', 'thesis', 'evidence_refs',
        'why_it_multiplies', 'success_metric', 'rollback', 'risk_level',
        'dependencies', 'proposed_packets', 'provider_tier_required',
        'anti_pattern_self_check',
    ];

    /**
     * Evaluate a single proposal at the inbox-entry boundary.
     *
     * @param  array<string,mixed>  $proposal  a (possibly provenanced) evolution_proposal.v1 object
     * @return array{
     *     stage:string,
     *     admit:bool,
     *     drop_reason:?string,
     *     detail:?string,
     *     falsifiable_metric:?array{operator:string,baseline:string,threshold:string},
     *     rollback_contract:?array{metric_id:string,baseline_hash:string,rollback_condition:string},
     *     projection_shape_valid:bool,
     *     projection_shape:array{valid:bool,missing:list<string>,unexpected_keys:list<string>}
     * }
     */
    public function evaluate(array $proposal): array
    {
        // Inbox-entry shape re-check on the 13-key STRIPPED PROJECTION only.
        $projection = $this->strippedProjection($proposal);
        $shape = FoundrySchemas::validateShape(FoundrySchemas::EVOLUTION_PROPOSAL, $projection);
        $proposalId = is_string($proposal['proposal_id'] ?? null) ? $proposal['proposal_id'] : '';

        $successMetric = $proposal['success_metric'] ?? null;
        $rollback = $proposal['rollback'] ?? null;

        // 1) metric_or_rollback_absent — either side empty/missing.
        if ($this->isEmptyValue($successMetric) || $this->isEmptyValue($rollback)) {
            return $this->drop(
                self::DROP_METRIC_OR_ROLLBACK_ABSENT,
                'success_metric and rollback must both be present and non-empty',
                $shape,
            );
        }

        // 2) metric_not_falsifiable — success_metric not parseable as {operator,baseline,threshold}.
        $metric = $this->parseFalsifiableMetric($successMetric);
        if ($metric === null) {
            return $this->drop(
                self::DROP_METRIC_NOT_FALSIFIABLE,
                'success_metric must declare a falsifiable {operator, baseline, threshold}',
                $shape,
            );
        }

        // 3) rollback_contract_incomplete — rollback present but missing rollback_condition.
        $rollbackCondition = $this->extractRollbackCondition($rollback);
        if ($rollbackCondition === null) {
            return $this->drop(
                self::DROP_ROLLBACK_CONTRACT_INCOMPLETE,
                'rollback must carry a non-empty rollback_condition',
                $shape,
            );
        }

        $rollbackContract = [
            'metric_id' => MissionCanonicalHash::sha256([
                'proposal_id' => $proposalId,
                'operator' => $metric['operator'],
                'baseline' => $metric['baseline'],
                'threshold' => $metric['threshold'],
            ]),
            'baseline_hash' => MissionCanonicalHash::sha256($metric['baseline']),
            'rollback_condition' => $rollbackCondition,
        ];

        return [
            'stage' => self::STAGE_ID,
            'admit' => true,
            'drop_reason' => null,
            'detail' => null,
            'falsifiable_metric' => $metric,
            'rollback_contract' => $rollbackContract,
            'projection_shape_valid' => $shape['valid'],
            'projection_shape' => $shape,
        ];
    }

    /**
     * Project the (possibly provenanced) proposal down to ONLY the 13 canonical
     * keys. Provenance keys (generator_label, generator_input_hash,
     * proposal_hash, …) are dropped so validateShape() sees no unexpected key.
     *
     * @param  array<string,mixed>  $proposal
     * @return array<string,mixed>
     */
    private function strippedProjection(array $proposal): array
    {
        $projection = [];
        foreach (self::PROJECTION_KEYS as $key) {
            if (array_key_exists($key, $proposal)) {
                $projection[$key] = $proposal[$key];
            }
        }

        return $projection;
    }

    /**
     * Parse success_metric into a falsifiable {operator, baseline, threshold}.
     * Accepts either a structured array already carrying those keys, or a string
     * of the form "<baseline> <operator> <threshold>" with a comparison operator.
     *
     * @return array{operator:string,baseline:string,threshold:string}|null
     */
    private function parseFalsifiableMetric(mixed $successMetric): ?array
    {
        if (is_array($successMetric)) {
            $operator = $successMetric['operator'] ?? null;
            $baseline = $successMetric['baseline'] ?? null;
            $threshold = $successMetric['threshold'] ?? null;

            if (
                ! $this->isEmptyValue($operator)
                && ! $this->isEmptyValue($baseline)
                && ! $this->isEmptyValue($threshold)
                && $this->isComparisonOperator((string) $operator)
            ) {
                return [
                    'operator' => (string) $operator,
                    'baseline' => $this->scalarToString($baseline),
                    'threshold' => $this->scalarToString($threshold),
                ];
            }

            return null;
        }

        if (is_string($successMetric)) {
            $matches = [];
            // baseline <op> threshold, e.g. "p95_latency_ms <= 120" or "42 >= 40".
            if (preg_match('/^\s*(\S+)\s*(<=|>=|<|>|==|!=)\s*(\S+)\s*$/', $successMetric, $matches) === 1) {
                return [
                    'operator' => $matches[2],
                    'baseline' => $matches[1],
                    'threshold' => $matches[3],
                ];
            }
        }

        return null;
    }

    private function isComparisonOperator(string $operator): bool
    {
        return in_array($operator, ['<=', '>=', '<', '>', '==', '!='], true);
    }

    /**
     * Extract the rollback_condition from a rollback value. Accepts a structured
     * array carrying rollback_condition, or treats a non-empty string as the
     * condition itself.
     */
    private function extractRollbackCondition(mixed $rollback): ?string
    {
        if (is_array($rollback)) {
            $condition = $rollback['rollback_condition'] ?? null;

            return $this->isEmptyValue($condition) ? null : $this->scalarToString($condition);
        }

        if (is_string($rollback)) {
            $trimmed = trim($rollback);

            return $trimmed === '' ? null : $trimmed;
        }

        return null;
    }

    private function isEmptyValue(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        if (is_array($value)) {
            return $value === [];
        }

        return false;
    }

    private function scalarToString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return MissionCanonicalHash::canonicalJson($value);
    }

    /**
     * @param  array{valid:bool,missing:list<string>,unexpected_keys:list<string>}  $shape
     * @return array{
     *     stage:string,admit:bool,drop_reason:string,detail:string,
     *     falsifiable_metric:null,rollback_contract:null,
     *     projection_shape_valid:bool,
     *     projection_shape:array{valid:bool,missing:list<string>,unexpected_keys:list<string>}
     * }
     */
    private function drop(string $reason, string $detail, array $shape): array
    {
        return [
            'stage' => self::STAGE_ID,
            'admit' => false,
            'drop_reason' => $reason,
            'detail' => $detail,
            'falsifiable_metric' => null,
            'rollback_contract' => null,
            'projection_shape_valid' => $shape['valid'],
            'projection_shape' => $shape,
        ];
    }
}
