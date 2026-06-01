<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas AI Multi-Domain Implementation Sequence decider.
 *
 * Pure, deterministic decider that turns the documented canonical build order
 * into a contract. The doc defines FOURTEEN ordered metas, the strict
 * dependency edges between them, the pairs that may run in parallel (and the
 * pairs that NEVER may), and a file boundary per meta used to detect when two
 * agents would collide.
 *
 * The fourteen metas, in canonical doc order, are:
 *    1  Mission Foundation        governs 2..14 (pre-requisite of every domain)
 *    2  Domain Runtime Contract   governs 7..13 (contract precedes adapters)
 *    3  Policy / Permission / Bud. governs 7..13 (esp. 9,10,11,12,13)
 *    4  Evidence / Certification  governs 7..14 (nothing is "delivered" without)
 *    5  Tool Registry / Runtime   governs 8,9,11,13 (external-tool domains)
 *    6  Router / Runtime Dispatch governs 7..13 (must know where to send)
 *    7  Programming Adapter       pilot; does not block 8..13 once ready
 *    8  Research / Strategy       feeds 9,10,11
 *    9  Finance / Investment
 *   10  Marketing / Growth
 *   11  Cyber Security
 *   12  Personal Development
 *   13  Automation / Tool Factory depends on Tool Registry (5) and Policy (3)
 *   14  Desktop / API Control Plane UX  consumes 1..13; NEVER precedes them
 *
 * Core decision the doc demands ("build Cyber now" example): a meta may only
 * START when every meta it depends on is "ready/green"; otherwise it is blocked
 * and the missing prerequisites must be opened first. Jumps are accepted only
 * with evidence that the dependency already exists and was certified by its own
 * gates — this service models that as "all depends_on must be ready".
 *
 * The "Control Plane UX before runtime" example is enforced: meta 14 depends on
 * 1..13, so asking to start it before any of those is ready is refused.
 *
 * The parallelism contract is encoded as an explicit allow-list of co-runnable
 * meta groups plus the documented NEVER-parallel rules; canParallelize answers
 * "can these two metas run in the same window?" and also blocks when their file
 * boundaries overlap (the multi-agent collision rule).
 *
 * This service NEVER reads a doc, runs a command, or touches git. It consumes
 * an already-normalized set of "ready" meta ids and emits a single verdict per
 * question with an audit receipt. It declares NOTHING implemented: it only
 * decides order, dependencies and boundaries, exactly as the doc states.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-multi-domain-implementation-sequence.md
 */
final class AtlasAiMultiDomainImplementationSequenceService
{
    /** Stable receipt schema id for every verdict this service emits. */
    public const RECEIPT_SCHEMA = 'atlas.ai.multi_domain_implementation_sequence.v1';

    /** Closed set of start verdicts for one meta. */
    public const START_READY = 'ready_to_start';
    public const START_BLOCKED = 'blocked';

    /** Required action per start verdict. */
    public const ACTION_START = 'start_meta';
    public const ACTION_OPEN_PREREQS = 'open_missing_prerequisites_first';
    public const ACTION_UNKNOWN = 'reject_unknown_meta';

    /** Closed set of parallelism verdicts. */
    public const PARALLEL_ALLOWED = 'parallel_allowed';
    public const PARALLEL_FORBIDDEN = 'parallel_forbidden';

    /**
     * The fourteen metas in canonical doc order. Key is the stable meta id
     * (1..14); value is the human name. Order in this array IS the build order.
     *
     * @var array<int,string>
     */
    private const META_ORDER = [
        1 => 'Mission Foundation',
        2 => 'Domain Runtime Contract',
        3 => 'Policy / Permission / Budget',
        4 => 'Evidence / Certification',
        5 => 'Tool Registry / Tool Runtime',
        6 => 'Router / Runtime Dispatch',
        7 => 'Programming Adapter',
        8 => 'Research / Strategy Adapter',
        9 => 'Finance / Investment',
        10 => 'Marketing / Growth',
        11 => 'Cyber Security',
        12 => 'Personal Development / Learning',
        13 => 'Automation / Tool Factory',
        14 => 'Desktop / API Control Plane UX',
    ];

    /**
     * Strict dependency edges from the doc's "Dependencias Estritas" section,
     * inverted to "what each meta depends_on". A meta may only start once every
     * id listed here is ready.
     *
     *   1 governs 2..14            -> 2..14 each depend on 1
     *   2 governs 7..13            -> 7..13 each depend on 2
     *   3 governs 7..13            -> 7..13 each depend on 3
     *   4 governs 7..14            -> 7..14 each depend on 4
     *   5 governs 8,9,11,13        -> 8,9,11,13 each depend on 5
     *   6 governs 7..13            -> 7..13 each depend on 6
     *   8 feeds 9,10,11            -> 9,10,11 each depend on 8
     *  13 depends on Tool Registry(5) and Policy(3) — already covered above.
     *
     * @var array<int,list<int>>
     */
    private const DEPENDS_ON = [
        1 => [],
        2 => [1],
        3 => [1],
        4 => [1],
        5 => [1],
        6 => [1],
        7 => [1, 2, 4, 6],
        8 => [1, 2, 4, 6, 5],
        9 => [1, 2, 3, 4, 6, 5, 8],
        10 => [1, 2, 3, 4, 6, 8],
        11 => [1, 2, 3, 4, 6, 5, 8],
        12 => [1, 2, 3, 4, 6],
        13 => [1, 2, 3, 4, 6, 5],
        14 => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13],
    ];

    /**
     * The file boundary the doc assigns each meta ("Fronteiras de Arquivos por
     * Meta"). Two metas with the same owned path collide and must not run in the
     * same window even if the parallelism rules would otherwise allow it.
     *
     * @var array<int,string>
     */
    private const FILE_BOUNDARY = [
        1 => 'app/Services/Atlas/Mission/',
        2 => 'docs/engineering-knowledge-base/atlas-domain-company-runtimes.md',
        3 => 'app/Services/Atlas/Policy/',
        4 => 'app/Services/Atlas/Evidence/',
        5 => 'app/Services/Atlas/Tool/',
        6 => 'app/Services/Atlas/Router/',
        7 => 'app/Services/Atlas/Domains/Programming/',
        8 => 'app/Services/Atlas/Domains/Research/',
        9 => 'app/Services/Atlas/Domains/Finance/',
        10 => 'app/Services/Atlas/Domains/Marketing/',
        11 => 'app/Services/Atlas/Domains/Cyber/',
        12 => 'app/Services/Atlas/Domains/PersonalDevelopment/',
        13 => 'app/Services/Atlas/Domains/Automation/',
        14 => 'atlas-desktop/',
    ];

    /**
     * Documented co-runnable groups ("Metas que podem rodar em paralelo"). Any
     * two distinct metas inside the same group are eligible to parallelize, so
     * long as their dependencies are ready and their file boundaries differ.
     *
     * @var list<list<int>>
     */
    private const PARALLEL_GROUPS = [
        [1, 2],
        [3, 4],
        [5, 6],
        [8, 9, 10],
        [11, 12],
        [13, 14],
    ];

    /**
     * Documented hard NEVER-parallel rules ("Metas que NUNCA paralelizam").
     * Each pair {a => list-of-bs} means: a never parallelizes with any b. These
     * win over the allow-list. Expressed as the lower id pointing at the set.
     *
     *  - 1 with any domain (1 is pre-requisite of all)
     *  - 2 with 7..13 (contract precedes adapters)
     *  - 3 with 9..12 (no Policy => sensitive domains cannot start)
     *  - 4 with 7..13 (no Evidence => nothing is "delivered")
     *  - 5 with 13 (Automation depends on Tool Registry)
     *  - 14 with any runtime it does not yet govern (1..13)
     *
     * @var array<int,list<int>>
     */
    private const NEVER_PARALLEL = [
        1 => [2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14],
        2 => [7, 8, 9, 10, 11, 12, 13],
        3 => [9, 10, 11, 12],
        4 => [7, 8, 9, 10, 11, 12, 13],
        5 => [13],
    ];

    /**
     * The seven canonical contracts the doc cites (it introduces none of its
     * own). Surfaced for the driver; not used in gating.
     *
     * @var list<string>
     */
    private const CITED_CONTRACTS = [
        'atlas.ai.intelligence.mission.v1',
        'atlas.ai.intelligence.domain_decision.v1',
        'atlas.ai.intelligence.tool_plan.v1',
        'atlas.ai.intelligence.evidence_pack.v1',
        'atlas.ai.intelligence.certification.v1',
        'atlas.ai.domain_runtime.v1',
        'atlas.ai.domain_manifest.v1',
    ];

    /**
     * Decide whether a meta may START given the set of metas already ready.
     *
     * A meta is ready_to_start only when every meta it depends_on is in the
     * ready set; otherwise it is blocked and the caller must open the missing
     * prerequisites first (the doc's "build Cyber now" example). An unknown meta
     * id is rejected. This decider never declares the meta "done": readiness to
     * START is not proof of completion (doc: "este doc e plano, nao prova").
     *
     * @param array<string,mixed> $request
     *   meta        : int|string the meta id (1..14). Unknown => rejected.
     *   ready_metas : list<int|string> ids of metas already ready/green.
     * @return array<string,mixed> verdict + missing prerequisites + receipt
     */
    public function canStart(array $request): array
    {
        $metaId = $this->normalizeMetaId($request['meta'] ?? null);
        if ($metaId === null) {
            return $this->rejectUnknownMeta($request['meta'] ?? null);
        }

        $ready = $this->normalizeReadySet($request['ready_metas'] ?? []);
        $deps = self::DEPENDS_ON[$metaId];

        $missing = [];
        foreach ($deps as $depId) {
            if (! in_array($depId, $ready, true)) {
                $missing[] = $depId;
            }
        }
        sort($missing);

        $canStart = $missing === [];
        $verdict = $canStart ? self::START_READY : self::START_BLOCKED;

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'question' => 'can_start',
            'meta' => $metaId,
            'meta_name' => self::META_ORDER[$metaId],
            'verdict' => $verdict,
            'can_start' => $canStart,
            'depends_on' => $deps,
            'missing_prerequisites' => $missing,
            'missing_prerequisite_names' => array_map(
                fn (int $id): string => self::META_ORDER[$id],
                $missing
            ),
            'file_boundary' => self::FILE_BOUNDARY[$metaId],
            'required_action' => $canStart ? self::ACTION_START : self::ACTION_OPEN_PREREQS,
            'declares_done' => false,
            'auditable' => true,
        ];
    }

    /**
     * Convenience predicate: may this meta start given the ready set?
     *
     * @param int|string|null $meta
     * @param list<int|string> $readyMetas
     */
    public function mayStart(int|string|null $meta, array $readyMetas): bool
    {
        return $this->canStart(['meta' => $meta, 'ready_metas' => $readyMetas])['can_start'] === true;
    }

    /**
     * Decide whether two metas may run in PARALLEL in the same window.
     *
     * Parallel is allowed only when ALL hold:
     *   - both metas are known and distinct;
     *   - the pair is NOT in the documented NEVER-parallel set;
     *   - the pair shares a documented co-runnable group;
     *   - their file boundaries differ (multi-agent collision rule: "Uma meta =
     *     um owner por janela", "touched_files_overlap").
     * Any failure yields parallel_forbidden with the specific reasons.
     *
     * @param int|string|null $a
     * @param int|string|null $b
     * @return array<string,mixed>
     */
    public function canParallelize(int|string|null $a, int|string|null $b): array
    {
        $aId = $this->normalizeMetaId($a);
        $bId = $this->normalizeMetaId($b);

        $reasons = [];

        if ($aId === null || $bId === null) {
            return [
                'schema' => self::RECEIPT_SCHEMA,
                'question' => 'can_parallelize',
                'pair' => [$aId, $bId],
                'verdict' => self::PARALLEL_FORBIDDEN,
                'allowed' => false,
                'reasons' => ['unknown_meta_id'],
                'auditable' => true,
            ];
        }

        if ($aId === $bId) {
            $reasons[] = 'same_meta_one_owner_per_window';
        }

        // Order-independent low/high for both the never-set and group checks.
        $low = min($aId, $bId);
        $high = max($aId, $bId);

        if ($this->isNeverParallel($low, $high)) {
            $reasons[] = 'documented_never_parallel';
        }

        if ($aId !== $bId && ! $this->shareParallelGroup($aId, $bId)) {
            $reasons[] = 'not_in_a_shared_parallel_group';
        }

        $boundaryOverlap = $aId !== $bId
            && self::FILE_BOUNDARY[$aId] === self::FILE_BOUNDARY[$bId];
        if ($boundaryOverlap) {
            $reasons[] = 'file_boundary_overlap';
        }

        $allowed = $reasons === [];

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'question' => 'can_parallelize',
            'pair' => [$aId, $bId],
            'pair_names' => [self::META_ORDER[$aId], self::META_ORDER[$bId]],
            'verdict' => $allowed ? self::PARALLEL_ALLOWED : self::PARALLEL_FORBIDDEN,
            'allowed' => $allowed,
            'file_boundaries' => [self::FILE_BOUNDARY[$aId], self::FILE_BOUNDARY[$bId]],
            'reasons' => $reasons,
            'auditable' => true,
        ];
    }

    /**
     * The id of the meta that follows `$metaId` in canonical order, or null when
     * `$metaId` is the final meta (14).
     *
     * @param int|string|null $meta
     */
    public function nextMeta(int|string|null $meta): ?int
    {
        $id = $this->normalizeMetaId($meta);
        if ($id === null) {
            return null;
        }

        return array_key_exists($id + 1, self::META_ORDER) ? $id + 1 : null;
    }

    /**
     * Build the ordered start plan for a target meta: the prerequisite metas
     * that must be opened first (in canonical order) plus the target. When the
     * ready set already satisfies the dependencies the plan is just the target.
     *
     * @param int|string|null $meta
     * @param list<int|string> $readyMetas
     * @return array<string,mixed>
     */
    public function startPlan(int|string|null $meta, array $readyMetas = []): array
    {
        $decision = $this->canStart(['meta' => $meta, 'ready_metas' => $readyMetas]);
        if ($decision['verdict'] === self::START_BLOCKED && $decision['meta'] === null) {
            return $decision;
        }

        $metaId = $decision['meta'];
        if (! is_int($metaId)) {
            return $decision;
        }

        // Open missing prerequisites first, in canonical order, then the target.
        $missing = $decision['missing_prerequisites'];
        $sequence = $missing;
        $sequence[] = $metaId;

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'question' => 'start_plan',
            'meta' => $metaId,
            'meta_name' => self::META_ORDER[$metaId],
            'ready_to_start_now' => $decision['can_start'],
            'open_first' => $missing,
            'open_first_names' => $decision['missing_prerequisite_names'],
            'sequence' => $sequence,
            'auditable' => true,
        ];
    }

    /**
     * The full canonical meta order as an ordered list of
     * { meta, name, depends_on, file_boundary }. For the driver to render.
     *
     * @return list<array<string,mixed>>
     */
    public function metaOrder(): array
    {
        $out = [];
        foreach (self::META_ORDER as $id => $name) {
            $out[] = [
                'meta' => $id,
                'name' => $name,
                'depends_on' => self::DEPENDS_ON[$id],
                'file_boundary' => self::FILE_BOUNDARY[$id],
            ];
        }

        return $out;
    }

    /**
     * The seven canonical contracts the doc cites (introduces none of its own).
     *
     * @return list<string>
     */
    public function citedContracts(): array
    {
        return self::CITED_CONTRACTS;
    }

    /**
     * Is the {low,high} meta pair in the documented NEVER-parallel set?
     */
    private function isNeverParallel(int $low, int $high): bool
    {
        // The 14-with-any-runtime-it-does-not-govern rule: 14 never parallelizes
        // with 1..13. Expressed here since 14 is always the high id.
        if ($high === 14 && $low >= 1 && $low <= 13) {
            return true;
        }

        return in_array($high, self::NEVER_PARALLEL[$low] ?? [], true);
    }

    /**
     * Do the two distinct metas share at least one documented co-runnable group?
     */
    private function shareParallelGroup(int $a, int $b): bool
    {
        foreach (self::PARALLEL_GROUPS as $group) {
            if (in_array($a, $group, true) && in_array($b, $group, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize a meta id to its canonical int key, or null if unknown.
     * Accepts 1, "1", "7", " 14 ", etc. Out-of-range or non-numeric => null.
     *
     * @param int|string|float|null $value
     */
    private function normalizeMetaId(int|string|float|null $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '' || ! ctype_digit($trimmed)) {
                return null;
            }
            $id = (int) $trimmed;
        } elseif (is_float($value)) {
            if ($value !== floor($value)) {
                return null;
            }
            $id = (int) $value;
        } else {
            $id = $value;
        }

        return array_key_exists($id, self::META_ORDER) ? $id : null;
    }

    /**
     * Normalize the ready set to a list of known canonical meta ids (unknown
     * entries are dropped — only real metas can be "ready").
     *
     * @param mixed $value
     * @return list<int>
     */
    private function normalizeReadySet(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $entry) {
            if (! is_int($entry) && ! is_string($entry) && ! is_float($entry)) {
                continue;
            }
            $id = $this->normalizeMetaId($entry);
            if ($id !== null && ! in_array($id, $out, true)) {
                $out[] = $id;
            }
        }
        sort($out);

        return $out;
    }

    /**
     * Build the rejection envelope for an unknown meta id.
     *
     * @param mixed $raw
     * @return array<string,mixed>
     */
    private function rejectUnknownMeta(mixed $raw): array
    {
        return [
            'schema' => self::RECEIPT_SCHEMA,
            'question' => 'can_start',
            'meta' => null,
            'raw_meta' => is_scalar($raw) ? $raw : null,
            'verdict' => self::START_BLOCKED,
            'can_start' => false,
            'missing_prerequisites' => [],
            'required_action' => self::ACTION_UNKNOWN,
            'declares_done' => false,
            'auditable' => true,
        ];
    }
}
