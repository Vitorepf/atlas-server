<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Autopoiesis;

/**
 * Pure Autopoiesis hypothesis generator. From bounded inputs (recent failures, give_backs, gaps,
 * repeated friction), produces a deterministic list of self-improvement HYPOTHESES that each name:
 *   - target_organ
 *   - expected_leverage (a structured FACT vector — NOT a scalar score)
 *   - safety_boundary
 *   - required_evidence
 *
 * Rejects PROXY-ONLY hypothesis seeds (those motivated solely by novelty/task_count/line_churn/
 * green_self_report signals with no concrete failure/gap evidence).
 *
 * Output: {schema_version, hypotheses:list<...>, rejected:list<...>}
 * NO live system calls.
 */
final class AtlasSelfConstructionAutopoiesisHypothesisGenerator
{
    public const SCHEMA = 'atlas.autopoiesis.hypothesis.v1';

    public const PROXY_ONLY_KINDS = ['novelty', 'task_count', 'line_churn', 'green_self_report'];

    /**
     * @param  array<string,mixed>  $facts {
     *     failures: list<{organ:string, class:string, evidence_refs:list<string>}>,
     *     give_backs: list<{organ:string, class:string, evidence_refs:list<string>}>,
     *     gaps: list<{organ:string, capability_gap:string}>,
     *     repeated_friction: list<{organ:string, kind:string, count:int}>,
     *     seed_signals?: list<string>  // proxy-only kinds — used to detect seeds with no real motivation
     *   }
     * @return array<string,mixed>
     */
    public function generate(array $facts): array
    {
        $hypotheses = [];
        $rejected = [];

        $seedSignals = array_values((array) ($facts['seed_signals'] ?? []));
        if ($seedSignals !== []
            && array_values(array_diff($seedSignals, self::PROXY_ONLY_KINDS)) === []
            && empty($facts['failures']) && empty($facts['give_backs']) && empty($facts['gaps']) && empty($facts['repeated_friction'])) {
            $rejected[] = ['reason' => 'proxy_only_seed:'.implode(',', $seedSignals)];

            return $this->envelope($hypotheses, $rejected);
        }

        foreach ((array) ($facts['failures'] ?? []) as $f) {
            $h = $this->hypothesisFromFact($f, 'failure', 'remove_failure_class', ['phpunit_green', 'mutop_kill']);
            if ($h !== null) {
                $hypotheses[] = $h;
            }
        }
        foreach ((array) ($facts['give_backs'] ?? []) as $g) {
            $h = $this->hypothesisFromFact($g, 'give_back', 'unblock_class', ['extractor_exists', 'integration_test_green']);
            if ($h !== null) {
                $hypotheses[] = $h;
            }
        }
        foreach ((array) ($facts['gaps'] ?? []) as $gap) {
            $organ = (string) ((array) $gap)['organ'] ?? '';
            $capabilityGap = (string) ((array) $gap)['capability_gap'] ?? '';
            if ($organ === '' || $capabilityGap === '') {
                continue;
            }
            $hypotheses[] = [
                'origin' => 'gap',
                'target_organ' => $organ,
                'class' => 'capability_gap:'.$capabilityGap,
                'expected_leverage' => ['capability_lift' => 1, 'autonomy_unlock' => 1],
                'safety_boundary' => 'sandbox_first_promote_after_evidence',
                'required_evidence' => ['demonstration_test_green', 'consumer_intact'],
            ];
        }
        foreach ((array) ($facts['repeated_friction'] ?? []) as $rf) {
            $organ = (string) ((array) $rf)['organ'] ?? '';
            $kind = (string) ((array) $rf)['kind'] ?? '';
            $count = (int) ((array) $rf)['count'] ?? 0;
            if ($organ === '' || $kind === '' || $count < 2) {
                continue;
            }
            $hypotheses[] = [
                'origin' => 'repeated_friction',
                'target_organ' => $organ,
                'class' => 'friction:'.$kind,
                'expected_leverage' => ['waste_reduction' => 1, 'autonomy_unlock' => 1],
                'safety_boundary' => 'change_one_friction_seam_at_a_time',
                'required_evidence' => ['friction_occurrence_drops_after_change'],
            ];
        }

        // Deterministic ordering by target_organ, then class.
        usort($hypotheses, static function (array $a, array $b): int {
            return strcmp($a['target_organ'], $b['target_organ']) ?: strcmp($a['class'], $b['class']);
        });

        return $this->envelope($hypotheses, $rejected);
    }

    /**
     * @param  array<string,mixed>  $fact
     * @param  list<string>  $evidence
     * @return array<string,mixed>|null
     */
    private function hypothesisFromFact(mixed $fact, string $origin, string $action, array $evidence): ?array
    {
        if (! is_array($fact)) {
            return null;
        }
        $organ = (string) ($fact['organ'] ?? '');
        $class = (string) ($fact['class'] ?? '');
        $refs = (array) ($fact['evidence_refs'] ?? []);
        if ($organ === '' || $class === '' || $refs === []) {
            return null;
        }

        return [
            'origin' => $origin,
            'target_organ' => $organ,
            'class' => $action.':'.$class,
            'expected_leverage' => ['failure_removal' => 1, 'autonomy_unlock' => 1],
            'safety_boundary' => 'change_one_organ_at_a_time',
            'required_evidence' => array_values($evidence),
            'supporting_refs' => array_values($refs),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $hypotheses
     * @param  list<array<string,mixed>>  $rejected
     * @return array<string,mixed>
     */
    private function envelope(array $hypotheses, array $rejected): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'hypotheses' => $hypotheses,
            'rejected' => $rejected,
        ];
    }
}
