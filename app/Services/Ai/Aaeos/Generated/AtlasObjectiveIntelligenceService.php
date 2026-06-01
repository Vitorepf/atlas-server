<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Runtime for the "Atlas Objective Intelligence" specification.
 *
 * Turns the doc's contract into deterministic, pure decision logic that sits
 * between Mission Mode and the domain runtimes:
 *
 *  - buildObjective(): assembles the documented objective record (minimum
 *    fields objective_id, mission_id, objective, primary_metric,
 *    secondary_metrics, constraints, assumptions, risks, definition_of_done,
 *    blockers, receipt_hash) and enforces the hard gate
 *    "Nao executar meta complexa sem objetivo e DoD": an objective is only
 *    `ready_to_execute` when objective text, a primary metric, constraints and
 *    a Definition of Done are all present AND no blocker is open. Emits a
 *    stable receipt_hash and dod_hash (observability_signals: dod_hash).
 *  - selectPrimaryMetric(): from candidate metrics picks the most operational
 *    one and refuses a vanity metric as primary when the business requires a
 *    real result ("Nao usar vaidade como metrica principal quando negocio
 *    exige resultado"); the rest become secondary metrics.
 *  - resolveMissingInfo(): for a missing field decides assume-conservatively /
 *    research / ask-for-decision. Budget, prazo (deadline) and permission can
 *    never be invented as fact ("Nao inventar budget, prazo ou permissao como
 *    fato") -> high-risk -> ask for decision; low-risk fields get a
 *    conservative assumption.
 *  - splitSellIntent(): when the goal is "vender", separates building
 *    infrastructure from generating revenue into two distinct sub-objectives
 *    ("Quando a meta for 'vender', separar criar infraestrutura de gerar
 *    receita").
 *
 * Pure: no database, no IO, no wall clock. Same input -> same output. The
 * receipt/DoD hashes are content hashes of the normalized payload.
 *
 * @see docs/engineering-knowledge-base/atlas-objective-intelligence.md
 */
final class AtlasObjectiveIntelligenceService
{
    public const SCHEMA_VERSION = 'atlas.ai.objective.v1';

    public const DOD_CONTRACT = 'atlas.ai.objective.definition_of_done.v1';

    /** Missing-info dispositions (doc -> "Fluxo" step 8). */
    public const RESOLVE_ASSUME = 'assume_conservatively';

    public const RESOLVE_RESEARCH = 'research';

    public const RESOLVE_ASK = 'ask_for_decision';

    /**
     * Documented minimum fields of an objective record (doc -> "Contratos":
     * "Campos minimos"). Order is the canonical contract order.
     *
     * @var array<int, string>
     */
    private const MINIMUM_FIELDS = [
        'objective_id',
        'mission_id',
        'objective',
        'primary_metric',
        'secondary_metrics',
        'constraints',
        'assumptions',
        'risks',
        'definition_of_done',
        'blockers',
        'receipt_hash',
    ];

    /**
     * Fields that, per "Regras para IA", must NEVER be invented as fact. A
     * missing value here is always escalated, never assumed.
     *
     * @var array<int, string>
     */
    private const NON_INVENTABLE_FIELDS = ['budget', 'deadline', 'permission'];

    /**
     * Build the documented objective record from a mission and gate it for
     * execution. The gate enforces the doc: a non-trivial mission may not
     * execute without an objective AND a Definition of Done (plus a primary
     * metric and constraints, the doc's quality_gates: objective/metrics/
     * constraints/dod-defined). An open blocker also stops execution, because
     * "uma blocker real e um resultado valido" — it is surfaced, not hidden.
     *
     * @param array{
     *     mission_id?: string|int|null,
     *     objective?: string,
     *     primary_metric?: string|array<string,mixed>|null,
     *     secondary_metrics?: array<int,mixed>,
     *     constraints?: array<int,mixed>,
     *     assumptions?: array<int,mixed>,
     *     risks?: array<int,mixed>,
     *     definition_of_done?: array<int,mixed>,
     *     blockers?: array<int,mixed>,
     *     trivial?: bool
     * } $mission
     * @return array{
     *     schema_version: string,
     *     objective_id: string,
     *     mission_id: string|null,
     *     objective: string,
     *     primary_metric: string|array<string,mixed>|null,
     *     secondary_metrics: array<int,mixed>,
     *     constraints: array<int,mixed>,
     *     assumptions: array<int,mixed>,
     *     risks: array<int,mixed>,
     *     definition_of_done: array<int,mixed>,
     *     blockers: array<int,mixed>,
     *     is_trivial: bool,
     *     has_objective: bool,
     *     has_primary_metric: bool,
     *     has_constraints: bool,
     *     has_definition_of_done: bool,
     *     open_blocker_count: int,
     *     missing_fields: array<int,string>,
     *     ready_to_execute: bool,
     *     dod_hash: string,
     *     receipt_hash: string
     * }
     */
    public function buildObjective(array $mission): array
    {
        $objectiveText = trim((string) ($mission['objective'] ?? ''));
        $primaryMetric = $mission['primary_metric'] ?? null;
        $secondary = $this->cleanList($mission['secondary_metrics'] ?? []);
        $constraints = $this->cleanList($mission['constraints'] ?? []);
        $assumptions = $this->cleanList($mission['assumptions'] ?? []);
        $risks = $this->cleanList($mission['risks'] ?? []);
        $dod = $this->cleanList($mission['definition_of_done'] ?? []);
        $blockers = $this->cleanList($mission['blockers'] ?? []);
        $isTrivial = (bool) ($mission['trivial'] ?? false);

        $missionId = isset($mission['mission_id']) && $mission['mission_id'] !== ''
            ? (string) $mission['mission_id']
            : null;

        $hasObjective = $objectiveText !== '';
        $hasPrimaryMetric = $this->isMetricPresent($primaryMetric);
        $hasConstraints = $constraints !== [];
        $hasDod = $dod !== [];
        $openBlockers = count($blockers);

        // Which required gates are missing (only meaningful for non-trivial work).
        $missing = [];
        if (! $hasObjective) {
            $missing[] = 'objective';
        }
        if (! $hasPrimaryMetric) {
            $missing[] = 'primary_metric';
        }
        if (! $hasConstraints) {
            $missing[] = 'constraints';
        }
        if (! $hasDod) {
            $missing[] = 'definition_of_done';
        }

        // Doc gate: trivial work needs no objective record; non-trivial work is
        // ready only when every required field is present and no blocker is open.
        $readyToExecute = $isTrivial
            || ($missing === [] && $openBlockers === 0);

        $objectiveId = $this->objectiveId($missionId, $objectiveText);

        $dodHash = $this->hashPayload(self::DOD_CONTRACT, [
            'definition_of_done' => $dod,
        ]);

        $record = [
            'schema_version' => self::SCHEMA_VERSION,
            'objective_id' => $objectiveId,
            'mission_id' => $missionId,
            'objective' => $objectiveText,
            'primary_metric' => $primaryMetric,
            'secondary_metrics' => $secondary,
            'constraints' => $constraints,
            'assumptions' => $assumptions,
            'risks' => $risks,
            'definition_of_done' => $dod,
            'blockers' => $blockers,
            'is_trivial' => $isTrivial,
            'has_objective' => $hasObjective,
            'has_primary_metric' => $hasPrimaryMetric,
            'has_constraints' => $hasConstraints,
            'has_definition_of_done' => $hasDod,
            'open_blocker_count' => $openBlockers,
            'missing_fields' => array_values($missing),
            'ready_to_execute' => $readyToExecute,
            'dod_hash' => $dodHash,
        ];

        // The objective receipt hash binds the whole normalized record.
        $record['receipt_hash'] = $this->hashPayload(self::SCHEMA_VERSION, $record);

        return $record;
    }

    /**
     * Select the most operational primary metric from candidates and demote the
     * rest to secondary. A vanity metric (e.g. likes, impressions, followers)
     * may not be primary when the business requires a real result; an
     * operational metric (revenue, conversion, first sale, ...) is preferred.
     *
     * Selection order:
     *   1. a non-vanity candidate explicitly marked operational, else
     *   2. the first non-vanity candidate, else
     *   3. (only if every candidate is vanity AND a result is required) no
     *      valid primary -> needs_real_metric=true.
     *
     * @param array<int, array{name?: string, vanity?: bool, operational?: bool}|string> $candidates
     * @param array{business_requires_result?: bool} $context
     * @return array{
     *     schema_version: string,
     *     candidate_count: int,
     *     business_requires_result: bool,
     *     primary_metric: string|null,
     *     secondary_metrics: array<int,string>,
     *     rejected_vanity: array<int,string>,
     *     needs_real_metric: bool,
     *     reason: string
     * }
     */
    public function selectPrimaryMetric(array $candidates, array $context = []): array
    {
        $requiresResult = (bool) ($context['business_requires_result'] ?? true);

        $normalized = [];
        foreach ($candidates as $candidate) {
            if (is_string($candidate)) {
                $name = trim($candidate);
                if ($name === '') {
                    continue;
                }
                $normalized[] = [
                    'name' => $name,
                    'vanity' => $this->looksLikeVanity($name),
                    'operational' => false,
                ];

                continue;
            }

            $name = trim((string) ($candidate['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $normalized[] = [
                'name' => $name,
                'vanity' => (bool) ($candidate['vanity'] ?? $this->looksLikeVanity($name)),
                'operational' => (bool) ($candidate['operational'] ?? false),
            ];
        }

        $rejectedVanity = [];
        $usable = [];
        foreach ($normalized as $metric) {
            // A vanity metric is disqualified from PRIMARY only when the
            // business requires a result; otherwise it remains usable.
            if ($metric['vanity'] && $requiresResult) {
                $rejectedVanity[] = $metric['name'];

                continue;
            }
            $usable[] = $metric;
        }

        $primary = null;
        $reason = 'no_candidates';

        // Prefer an explicitly operational metric, then the first usable one.
        foreach ($usable as $metric) {
            if ($metric['operational']) {
                $primary = $metric['name'];
                $reason = 'selected_operational_metric';
                break;
            }
        }
        if ($primary === null && $usable !== []) {
            $primary = $usable[0]['name'];
            $reason = 'selected_first_non_vanity_metric';
        }

        $needsRealMetric = false;
        if ($primary === null) {
            if ($normalized !== [] && $requiresResult) {
                // Every candidate was vanity and a real result is required.
                $needsRealMetric = true;
                $reason = 'all_candidates_vanity_needs_real_metric';
            }
        }

        // Secondary metrics: every usable candidate that is not the chosen primary.
        $secondary = [];
        foreach ($usable as $metric) {
            if ($metric['name'] !== $primary) {
                $secondary[] = $metric['name'];
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'candidate_count' => count($normalized),
            'business_requires_result' => $requiresResult,
            'primary_metric' => $primary,
            'secondary_metrics' => array_values(array_unique($secondary)),
            'rejected_vanity' => array_values(array_unique($rejectedVanity)),
            'needs_real_metric' => $needsRealMetric,
            'reason' => $reason,
        ];
    }

    /**
     * Decide what to do about a missing piece of information (doc -> "Fluxo"
     * step 8: assumir, pesquisar ou pedir decisao).
     *
     *  - budget / deadline / permission are never invented -> ask_for_decision.
     *  - high risk -> ask_for_decision.
     *  - researchable (a fact that can be looked up) and not high risk -> research.
     *  - otherwise a low-risk field gets a conservative assumption.
     *
     * @param array{
     *     field?: string,
     *     risk?: string,
     *     researchable?: bool
     * } $input
     * @return array{
     *     schema_version: string,
     *     field: string,
     *     risk: string,
     *     non_inventable: bool,
     *     disposition: string,
     *     may_assume: bool,
     *     requires_human_decision: bool,
     *     is_blocker: bool,
     *     reason: string
     * }
     */
    public function resolveMissingInfo(array $input): array
    {
        $field = trim((string) ($input['field'] ?? ''));
        $risk = strtolower(trim((string) ($input['risk'] ?? 'low')));
        if (! in_array($risk, ['low', 'medium', 'high'], true)) {
            $risk = 'low';
        }
        $researchable = (bool) ($input['researchable'] ?? false);

        $nonInventable = in_array(strtolower($field), self::NON_INVENTABLE_FIELDS, true);

        if ($nonInventable) {
            $disposition = self::RESOLVE_ASK;
            $reason = 'field_must_not_be_invented';
        } elseif ($risk === 'high') {
            $disposition = self::RESOLVE_ASK;
            $reason = 'high_risk_requires_decision';
        } elseif ($researchable) {
            $disposition = self::RESOLVE_RESEARCH;
            $reason = 'fact_is_researchable';
        } else {
            $disposition = self::RESOLVE_ASSUME;
            $reason = 'low_risk_conservative_assumption';
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'field' => $field,
            'risk' => $risk,
            'non_inventable' => $nonInventable,
            'disposition' => $disposition,
            'may_assume' => $disposition === self::RESOLVE_ASSUME,
            'requires_human_decision' => $disposition === self::RESOLVE_ASK,
            // An escalation that blocks execution is a real blocker, not a
            // silent default.
            'is_blocker' => $disposition === self::RESOLVE_ASK,
            'reason' => $reason,
        ];
    }

    /**
     * When the goal is "vender" (sell), split it into two distinct
     * sub-objectives: build the selling infrastructure vs generate revenue.
     * Conflating them is the documented failure the rule guards against.
     *
     * @param string $goal
     * @return array{
     *     schema_version: string,
     *     goal: string,
     *     is_sell_intent: bool,
     *     sub_objectives: array<int, array{key: string, objective: string, primary_metric: string}>,
     *     reason: string
     * }
     */
    public function splitSellIntent(string $goal): array
    {
        $normalized = strtolower(trim($goal));
        $isSell = $normalized !== '' && (
            str_contains($normalized, 'vend')      // vender, vendas, venda
            || str_contains($normalized, 'sell')
            || str_contains($normalized, 'receita')
            || str_contains($normalized, 'revenue')
        );

        if (! $isSell) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'goal' => trim($goal),
                'is_sell_intent' => false,
                'sub_objectives' => [],
                'reason' => 'not_a_sell_intent',
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'goal' => trim($goal),
            'is_sell_intent' => true,
            'sub_objectives' => [
                [
                    'key' => 'build_infrastructure',
                    'objective' => 'Build the selling infrastructure (catalog, payment, storefront, campaign setup).',
                    'primary_metric' => 'infrastructure_ready',
                ],
                [
                    'key' => 'generate_revenue',
                    'objective' => 'Generate revenue (drive traffic, convert visits, record the first real sale).',
                    'primary_metric' => 'first_sale_recorded',
                ],
            ],
            'reason' => 'sell_intent_split_infrastructure_from_revenue',
        ];
    }

    /**
     * The canonical contract projection used as the default command output.
     *
     * @return array{
     *     schema_version: string,
     *     contracts: array<int,string>,
     *     minimum_fields: array<int,string>,
     *     non_inventable_fields: array<int,string>,
     *     resolve_dispositions: array<int,string>,
     *     flow: array<int,string>
     * }
     */
    public function contract(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'contracts' => [
                'atlas.ai.objective.v1',
                'atlas.ai.objective.metric.v1',
                'atlas.ai.objective.constraint.v1',
                'atlas.ai.objective.definition_of_done.v1',
            ],
            'minimum_fields' => self::MINIMUM_FIELDS,
            'non_inventable_fields' => self::NON_INVENTABLE_FIELDS,
            'resolve_dispositions' => [
                self::RESOLVE_ASSUME,
                self::RESOLVE_RESEARCH,
                self::RESOLVE_ASK,
            ],
            'flow' => [
                'receive_mission',
                'extract_primary_objective',
                'split_subobjectives',
                'define_success_metrics',
                'map_deadline_budget_risk_credentials_jurisdiction_constraints',
                'create_definition_of_done',
                'identify_missing_information',
                'decide_assume_research_or_ask',
                'emit_objective_receipt',
            ],
        ];
    }

    /**
     * @param array<int,mixed>|mixed $value
     * @return array<int,mixed>
     */
    private function cleanList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $trimmed = trim($item);
                if ($trimmed === '') {
                    continue;
                }
                $out[] = $trimmed;

                continue;
            }
            $out[] = $item;
        }

        return array_values($out);
    }

    private function isMetricPresent(mixed $metric): bool
    {
        if (is_string($metric)) {
            return trim($metric) !== '';
        }

        if (is_array($metric)) {
            return $metric !== [];
        }

        return false;
    }

    private function looksLikeVanity(string $name): bool
    {
        $needles = ['like', 'curtida', 'follower', 'seguidor', 'impression', 'impressao', 'view', 'visualizac', 'vanity'];
        $haystack = strtolower($name);
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function objectiveId(?string $missionId, string $objectiveText): string
    {
        $seed = ($missionId ?? 'no-mission').'|'.$objectiveText;

        return 'obj_'.substr(hash('sha256', $seed), 0, 24);
    }

    /**
     * Deterministic content hash over a normalized payload. Stable across runs
     * for identical input (no clock, no randomness).
     *
     * @param array<string,mixed> $payload
     */
    private function hashPayload(string $namespace, array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', $namespace.'|'.($json !== false ? $json : ''));
    }
}
