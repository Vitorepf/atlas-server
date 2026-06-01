<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas Context Pack Contract — pure, deterministic enforcement of the contract
 * a context pack ref-set must satisfy before it can hydrate a provider prompt.
 *
 * The context pack reminds the provider which canonical sources matter for a
 * task. It does NOT paste every document. This service is the contract gate:
 * it validates ref shape, ranks + caps refs, de-duplicates rendered sections by
 * hash, decides whether engineering autonomy is allowed, and degrades safely
 * when the knowledge table is absent. It mutates nothing.
 *
 * Contract (from the doc):
 *   Ref shape — each reference includes: id, slug, title, category, priority,
 *     canonical_path, content_hash, summary, reason. A ref missing any required
 *     field is invalid and is dropped (never silently malformed into the prompt).
 *   Blueprint refs are core — for engineering tasks task_contract,
 *     engineering_blueprint, contingency_policy, review_gates, acceptance_matrix
 *     and scenario_inventory are treated as nucleus, not optional context.
 *     "Sem esses dados, o provider pode ajudar a investigar, mas nao deve
 *     receber autonomia total para concluir task de risco medio/alto."
 *     => when any core blueprint ref is missing, full autonomy is denied for
 *        medium/high risk tasks; investigate-only is still allowed.
 *   Size policy — "Por padrao, o Harness usa ate 8 referencias." Cap at 8.
 *     Ranking prioritizes architecture, maintenance and capability-matrix
 *     categories, "com boost para categorias relacionadas a tags da task".
 *   No-duplicate — "Se o Open Brain ja renderizou uma secao com o mesmo
 *     context_pack_hash, o prompt final deve usar uma unica secao auditavel."
 *     => identical context_pack_hash collapses to a single section.
 *   Safe-fail — "Se a migration ainda nao rodou ou a tabela nao existe, o
 *     context pack continua funcionando sem knowledge refs." => never throws;
 *     emits an empty, valid pack flagged as degraded.
 *
 * @see docs/engineering-knowledge-base/context-pack.md
 */
final class AtlasContextPackContractService
{
    /** Stable schema id this contract emits. */
    public const SCHEMA = 'atlas.context_pack.contract.v1';

    /**
     * Default reference budget. "Por padrao, o Harness usa ate 8 referencias."
     */
    public const DEFAULT_MAX_REFS = 8;

    /**
     * Required fields of a single knowledge ref (closed set, from the doc's
     * "Cada referencia inclui" list). Order is the canonical render order.
     *
     * @var list<string>
     */
    public const REQUIRED_REF_FIELDS = [
        'id',
        'slug',
        'title',
        'category',
        'priority',
        'canonical_path',
        'content_hash',
        'summary',
        'reason',
    ];

    /**
     * Blueprint refs the doc declares as "nucleo, nao contexto opcional" for
     * engineering tasks (the "Blueprint Refs" section). Missing any of these
     * removes full-autonomy eligibility for medium/high risk work.
     *
     * @var list<string>
     */
    public const CORE_BLUEPRINT_REFS = [
        'task_contract',
        'engineering_blueprint',
        'contingency_policy',
        'review_gates',
        'acceptance_matrix',
        'scenario_inventory',
    ];

    /**
     * Categories the ranking prioritizes (architecture, maintenance, capability
     * matrix). Lower weight = ranked higher (sorted ascending). Anything not
     * listed gets the default bucket.
     *
     * @var array<string,int>
     */
    private const CATEGORY_PRIORITY = [
        'architecture' => 0,
        'maintenance' => 1,
        'capabilities' => 2,
        'capability_matrix' => 2,
    ];

    /** Autonomy verdicts (closed set). */
    public const AUTONOMY_FULL = 'full';
    public const AUTONOMY_INVESTIGATE_ONLY = 'investigate_only';

    /** Risk levels that require the full blueprint nucleus for full autonomy. */
    private const AUTONOMY_GATED_RISK = ['medium', 'high', 'critical'];

    /**
     * Build a contract-valid context pack from a raw ref-set + blueprint inputs.
     *
     * @param array<string,mixed> $input
     *        knowledge_refs : list<array<string,mixed>>  candidate refs
     *        blueprint_refs : array<string,mixed>        present blueprint nucleus keys
     *        task_tags      : list<string>               tags of the current task
     *        risk_level     : string                     low|medium|high|critical
     *        max_refs       : int                        optional override (clamped >=0)
     *        table_present  : bool                        knowledge table exists (default true)
     *
     * @return array<string,mixed>
     */
    public function build(array $input): array
    {
        $tablePresent = ! array_key_exists('table_present', $input)
            || (bool) $input['table_present'];

        // Safe-fail: no table => empty but valid pack, never throws.
        if (! $tablePresent) {
            return $this->degradedPack();
        }

        $maxRefs = $this->resolveMaxRefs($input['max_refs'] ?? self::DEFAULT_MAX_REFS);
        $taskTags = $this->normalizeTags($input['task_tags'] ?? []);
        $riskLevel = $this->normalizeRisk($input['risk_level'] ?? 'low');

        $candidates = is_array($input['knowledge_refs'] ?? null)
            ? $input['knowledge_refs']
            : [];

        // 1. Validate ref shape; drop any ref missing a required field.
        $valid = [];
        $rejected = [];
        foreach ($candidates as $candidate) {
            $check = $this->validateRef(is_array($candidate) ? $candidate : []);
            if ($check['valid']) {
                $valid[] = $check['ref'];
            } else {
                $rejected[] = [
                    'ref' => is_array($candidate) ? ($candidate['id'] ?? $candidate['slug'] ?? null) : null,
                    'missing_fields' => $check['missing_fields'],
                ];
            }
        }

        // 2. Rank (architecture/maintenance/capabilities first, tag boost, then
        //    by numeric priority) and 3. cap at the budget.
        $ranked = $this->rank($valid, $taskTags);
        $selected = array_slice($ranked, 0, $maxRefs);

        // 4. No-duplicate-by-hash: identical content_hash collapses to one ref.
        $deduped = $this->dedupeByHash($selected);

        return [
            'schema' => self::SCHEMA,
            'degraded' => false,
            'max_refs' => $maxRefs,
            'ref_count' => count($deduped),
            'truncated' => count($ranked) > $maxRefs,
            'knowledge_refs' => $deduped,
            'rejected_refs' => $rejected,
            'duplicate_hashes_collapsed' => count($selected) - count($deduped),
            'autonomy' => $this->autonomy($input['blueprint_refs'] ?? [], $riskLevel),
        ];
    }

    /**
     * Validate one knowledge ref against the documented 9-field shape.
     * A field counts as present only when it is a non-empty scalar.
     *
     * @param array<string,mixed> $ref
     * @return array{valid:bool,missing_fields:list<string>,ref:array<string,mixed>}
     */
    public function validateRef(array $ref): array
    {
        $missing = [];
        foreach (self::REQUIRED_REF_FIELDS as $field) {
            if (! $this->fieldPresent($ref[$field] ?? null)) {
                $missing[] = $field;
            }
        }

        return [
            'valid' => $missing === [],
            'missing_fields' => $missing,
            'ref' => $ref,
        ];
    }

    /**
     * Rank refs by the documented policy:
     *   - priority categories (architecture > maintenance > capabilities) first;
     *   - boost refs whose category matches one of the task tags;
     *   - then by the ref's own numeric priority (higher first);
     *   - stable on original order for full ties.
     *
     * @param list<array<string,mixed>> $refs
     * @param list<string> $taskTags
     * @return list<array<string,mixed>>
     */
    public function rank(array $refs, array $taskTags): array
    {
        $tags = array_map('strtolower', $taskTags);

        $decorated = [];
        foreach ($refs as $index => $ref) {
            $category = strtolower((string) ($ref['category'] ?? ''));
            $categoryRank = self::CATEGORY_PRIORITY[$category] ?? 99;
            // Tag boost: a ref whose category matches a task tag jumps ahead.
            $tagBoost = in_array($category, $tags, true) ? 0 : 1;

            $decorated[] = [
                'tag_boost' => $tagBoost,
                'category_rank' => $categoryRank,
                'priority' => $this->intOf($ref['priority'] ?? 0),
                'index' => $index,
                'ref' => $ref,
            ];
        }

        usort($decorated, static function (array $a, array $b): int {
            return [$a['tag_boost'], $a['category_rank'], -$a['priority'], $a['index']]
                <=> [$b['tag_boost'], $b['category_rank'], -$b['priority'], $b['index']];
        });

        return array_map(static fn (array $d): array => $d['ref'], $decorated);
    }

    /**
     * Collapse refs that share the same content_hash into a single auditable
     * entry (No-duplicate rule). First occurrence wins; render order preserved.
     *
     * @param list<array<string,mixed>> $refs
     * @return list<array<string,mixed>>
     */
    public function dedupeByHash(array $refs): array
    {
        $seen = [];
        $out = [];
        foreach ($refs as $ref) {
            $hash = (string) ($ref['content_hash'] ?? '');
            if ($hash !== '' && isset($seen[$hash])) {
                continue;
            }
            if ($hash !== '') {
                $seen[$hash] = true;
            }
            $out[] = $ref;
        }

        return array_values($out);
    }

    /**
     * Autonomy verdict from the blueprint nucleus + risk.
     *   - Full autonomy requires the complete blueprint nucleus for
     *     medium/high/critical risk tasks.
     *   - Missing any core ref at gated risk => investigate_only.
     *   - Low risk is never gated by this contract.
     *
     * @param mixed  $blueprintRefs present nucleus (assoc keys or list of keys)
     * @return array{verdict:string,full_autonomy_allowed:bool,risk_level:string,missing_blueprint_refs:list<string>,present_blueprint_refs:list<string>}
     */
    public function autonomy(mixed $blueprintRefs, string $riskLevel): array
    {
        $riskLevel = $this->normalizeRisk($riskLevel);
        $present = $this->presentBlueprintRefs($blueprintRefs);
        $missing = array_values(array_diff(self::CORE_BLUEPRINT_REFS, $present));

        $gatedRisk = in_array($riskLevel, self::AUTONOMY_GATED_RISK, true);
        $fullAllowed = ! $gatedRisk || $missing === [];

        return [
            'verdict' => $fullAllowed ? self::AUTONOMY_FULL : self::AUTONOMY_INVESTIGATE_ONLY,
            'full_autonomy_allowed' => $fullAllowed,
            'risk_level' => $riskLevel,
            'missing_blueprint_refs' => $missing,
            'present_blueprint_refs' => $present,
        ];
    }

    /**
     * The empty-but-valid pack emitted when the knowledge table is absent.
     *
     * @return array<string,mixed>
     */
    private function degradedPack(): array
    {
        return [
            'schema' => self::SCHEMA,
            'degraded' => true,
            'max_refs' => self::DEFAULT_MAX_REFS,
            'ref_count' => 0,
            'truncated' => false,
            'knowledge_refs' => [],
            'rejected_refs' => [],
            'duplicate_hashes_collapsed' => 0,
            // No nucleus is provable without the table, so autonomy stays denied
            // for gated risk; default (low) input keeps full when truly low-risk.
            'autonomy' => $this->autonomy([], 'low'),
        ];
    }

    /**
     * @param mixed $blueprintRefs
     * @return list<string>
     */
    private function presentBlueprintRefs(mixed $blueprintRefs): array
    {
        $present = [];

        if (is_array($blueprintRefs)) {
            foreach ($blueprintRefs as $key => $value) {
                // Assoc shape: key=ref name, value=truthy when present.
                if (is_string($key)) {
                    if ($this->fieldPresent($value) || $value === true || (is_array($value) && $value !== [])) {
                        $present[] = $key;
                    }

                    continue;
                }
                // List shape: ["task_contract", "review_gates", ...].
                if (is_string($value) && trim($value) !== '') {
                    $present[] = trim($value);
                }
            }
        }

        return array_values(array_unique(array_intersect($present, self::CORE_BLUEPRINT_REFS)));
    }

    private function resolveMaxRefs(mixed $value): int
    {
        $n = $this->intOf($value);

        return $n < 0 ? 0 : $n;
    }

    /**
     * @param mixed $tags
     * @return list<string>
     */
    private function normalizeTags(mixed $tags): array
    {
        if (! is_array($tags)) {
            return [];
        }

        $clean = [];
        foreach ($tags as $tag) {
            if (is_string($tag) && trim($tag) !== '') {
                $clean[] = trim($tag);
            }
        }

        return array_values(array_unique($clean));
    }

    private function normalizeRisk(mixed $risk): string
    {
        $key = is_string($risk) ? strtolower(trim($risk)) : '';

        return in_array($key, ['low', 'medium', 'high', 'critical'], true) ? $key : 'low';
    }

    private function fieldPresent(mixed $value): bool
    {
        if (is_string($value)) {
            return trim($value) !== '';
        }

        return is_int($value) || is_float($value) || is_bool($value);
    }

    private function intOf(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (int) $value;
        }
        if (is_string($value) && is_numeric(trim($value))) {
            return (int) trim($value);
        }

        return 0;
    }
}
