<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ScopeExpansion;

/**
 * Pure, deterministic ranker for Self-Construction scope-expansion candidates.
 *
 * Refuses evidence-free, scalar-only, human-dependent or external-provider-dependent candidates.
 * Ranks survivors by proven leverage tier → autonomy readiness → lower risk → lexical scope_id.
 *
 * No I/O. No process. No provider. No git. No queue mutation. No file writes.
 */
final class AtlasSelfConstructionScopeExpansionCandidateRanker
{
    public const SCHEMA = 'atlas.self_construction.scope_expansion_candidate_ranker.v1';

    public const REQUIRED_CANDIDATE_KEYS = [
        'scope_id',
        'evidence_refs',
        'atlas_native_owner',
        'requires_operator',
        'requires_human',
        'requires_external_provider',
        'proven_leverage_tier',
        'autonomy_readiness_tier',
        'risk',
    ];

    public const REQUIRED_BUDGET_KEYS = ['max_risk'];

    public const REASON_MISSING_EVIDENCE_REFS = 'missing_evidence_refs';

    public const REASON_NON_ATLAS_NATIVE_OWNER = 'non_atlas_native_owner';

    public const REASON_HUMAN_DEPENDENCY = 'human_dependency';

    public const REASON_OPERATOR_DEPENDENCY = 'operator_dependency';

    public const REASON_EXTERNAL_PROVIDER_DEPENDENCY = 'external_provider_dependency';

    public const REASON_RISK_BUDGET_EXCEEDED = 'risk_budget_exceeded';

    public const REASON_SCALAR_ONLY_PROXY = 'scalar_only_proxy';

    public const REASON_MISSING_REQUIRED_FACT = 'missing_required_fact';

    public const REASON_HYPE_ONLY = 'hype_only';

    public const REASON_MISSING_STRUCTURAL_LEVERAGE_REFS = 'missing_structural_leverage_refs';

    public const REASON_DUPLICATE_SCOPE_ID = 'duplicate_scope_id';

    /**
     * @param  array<string,mixed>  $facts {candidates:list<array>, current_scope?:array, queue_health?:array, autonomy?:array, risk_budget:array}
     * @return array<string,mixed>
     */
    public function rank(array $facts): array
    {
        $candidates = array_values((array) ($facts['candidates'] ?? []));
        $budget = (array) ($facts['risk_budget'] ?? []);

        $accepted = [];
        $rejected = [];

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                $rejected[] = [
                    'scope_id' => null,
                    'reasons' => [self::REASON_MISSING_REQUIRED_FACT],
                ];

                continue;
            }
            $reasons = $this->reject($candidate, $budget);
            if ($reasons !== []) {
                $rejected[] = [
                    'scope_id' => (string) ($candidate['scope_id'] ?? ''),
                    'reasons' => $reasons,
                ];

                continue;
            }
            $accepted[] = $candidate;
        }

        usort($accepted, function (array $a, array $b): int {
            return [
                -1 * (int) $a['proven_leverage_tier'],
                -1 * (int) $a['autonomy_readiness_tier'],
                (int) ($a['proof_cost'] ?? 5),
                -1 * (int) ($a['isolation'] ?? 5),
                (int) $a['risk'],
                (string) $a['scope_id'],
            ] <=> [
                -1 * (int) $b['proven_leverage_tier'],
                -1 * (int) $b['autonomy_readiness_tier'],
                (int) ($b['proof_cost'] ?? 5),
                -1 * (int) ($b['isolation'] ?? 5),
                (int) $b['risk'],
                (string) $b['scope_id'],
            ];
        });

        // Deduplicate by scope_id: keep the best-ranked (first in sorted order), reject the rest.
        $seenScopeIds = [];
        $deduped = [];
        foreach ($accepted as $c) {
            $sid = (string) ($c['scope_id'] ?? '');
            if (isset($seenScopeIds[$sid])) {
                $rejected[] = ['scope_id' => $sid, 'reasons' => [self::REASON_DUPLICATE_SCOPE_ID]];
            } else {
                $seenScopeIds[$sid] = true;
                $deduped[] = $c;
            }
        }
        $accepted = $deduped;

        foreach ($accepted as $idx => &$candidate) {
            $candidate['score_components'] = [
                'leverage'   => (int) ($candidate['proven_leverage_tier'] ?? 0),
                'readiness'  => (int) ($candidate['autonomy_readiness_tier'] ?? 0),
                'proof_cost' => (int) ($candidate['proof_cost'] ?? 5),
                'isolation'  => (int) ($candidate['isolation'] ?? 5),
                'risk'       => (int) ($candidate['risk'] ?? 0),
            ];
            $candidate['decision_facts'] = [
                'rank'            => $idx + 1,
                'sort_dimensions' => ['proven_leverage_tier', 'autonomy_readiness_tier', 'proof_cost', 'isolation', 'risk', 'scope_id'],
                'accepted'        => true,
            ];
        }
        unset($candidate);

        $payload = [
            'schema_version' => self::SCHEMA,
            'status' => 'ok',
            'accepted_candidates' => $accepted,
            'rejected_candidates' => $rejected,
            'required_facts' => [
                'candidate_keys' => self::REQUIRED_CANDIDATE_KEYS,
                'risk_budget_keys' => self::REQUIRED_BUDGET_KEYS,
            ],
        ];
        $payload['ranker_hash'] = $this->hash($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @param  array<string,mixed>  $budget
     * @return list<string>
     */
    private function reject(array $candidate, array $budget): array
    {
        $reasons = [];
        foreach (self::REQUIRED_CANDIDATE_KEYS as $key) {
            if (! array_key_exists($key, $candidate)) {
                $reasons[] = self::REASON_MISSING_REQUIRED_FACT;
                break;
            }
        }
        foreach (self::REQUIRED_BUDGET_KEYS as $key) {
            if (! array_key_exists($key, $budget)) {
                $reasons[] = self::REASON_MISSING_REQUIRED_FACT;
                break;
            }
        }

        $evidence = (array) ($candidate['evidence_refs'] ?? []);
        if ($evidence === []) {
            $reasons[] = self::REASON_MISSING_EVIDENCE_REFS;
        }
        if (($candidate['atlas_native_owner'] ?? null) !== true) {
            $reasons[] = self::REASON_NON_ATLAS_NATIVE_OWNER;
        }
        if (($candidate['requires_human'] ?? null) === true) {
            $reasons[] = self::REASON_HUMAN_DEPENDENCY;
        }
        if (($candidate['requires_operator'] ?? null) === true) {
            $reasons[] = self::REASON_OPERATOR_DEPENDENCY;
        }
        if (($candidate['requires_external_provider'] ?? null) === true) {
            $reasons[] = self::REASON_EXTERNAL_PROVIDER_DEPENDENCY;
        }
        if (array_key_exists('risk', $candidate) && array_key_exists('max_risk', $budget)) {
            if ((int) $candidate['risk'] > (int) $budget['max_risk']) {
                $reasons[] = self::REASON_RISK_BUDGET_EXCEEDED;
            }
        }
        if (($candidate['scalar_only_proxy'] ?? false) === true) {
            $reasons[] = self::REASON_SCALAR_ONLY_PROXY;
        }
        if (($candidate['hype_only'] ?? false) === true) {
            $reasons[] = self::REASON_HYPE_ONLY;
        }
        // Only fires when the key is explicitly present but empty — absent means the candidate hasn't declared refs yet.
        if (array_key_exists('structural_leverage_refs', $candidate) && (array) ($candidate['structural_leverage_refs']) === []) {
            $reasons[] = self::REASON_MISSING_STRUCTURAL_LEVERAGE_REFS;
        }

        return array_values(array_unique($reasons));
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hash(array $payload): string
    {
        $copy = $payload;
        unset($copy['ranker_hash']);
        $copy = $this->ksortDeep($copy);

        return hash('sha256', (string) json_encode($copy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<mixed,mixed>  $value
     * @return array<mixed,mixed>
     */
    private function ksortDeep(array $value): array
    {
        $isAssoc = $value !== [] && array_keys($value) !== range(0, count($value) - 1);
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $value[$k] = $this->ksortDeep($v);
            }
        }
        if ($isAssoc) {
            ksort($value);
        }

        return $value;
    }
}
