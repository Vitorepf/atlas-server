<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\FindingSlicePlannerService;

/**
 * Software Company Stewardship Stack · Area Focus Loop · Plan Execution ·
 * Build Plan Decomposer (Pilar 1 / decomposer).
 *
 * Turns a canonical build-plan document (frontmatter + section 6 ordered-slice
 * table + section 10 sequencing text) into an executable decomposed plan that
 * the 24h loop can drive slice by slice.
 *
 * It does NOT re-implement slicing. For every parsed section-6 row it builds a
 * synthetic finding and delegates to {@see FindingSlicePlannerService::plan()}
 * exactly once, embedding the planner's slices verbatim as `executable_slices`
 * and its decomposition status as `planner_status`.
 *
 * Honest-status floor (real-or-blocked):
 *   - `complete` ONLY when section 10 is present AND every parsed section
 *     yielded planner_status === STATUS_SLICED.
 *   - `partial` when slices parsed but section 10 absent (no invented N-1
 *     chain) OR any section planner-blocked OR any empty Aceite column.
 *   - `blocked` when section 6 is missing/unparseable or there is no source.
 *
 * Pure planner: no provider, no branch, no git, no merge, no mutation. Reads the
 * doc from disk only to obtain its text; everything else is in-memory.
 *
 * NOTE: {@see FindingSlicePlannerService} is `final`, so a test cannot subclass
 * it. To assert composition (plan() called once per section, slices embedded
 * verbatim) tests inject a closure via
 * {@see self::setSlicePlannerCallableForTesting()}. The typed
 * {@see self::setFindingSlicePlannerForTesting()} seam is kept for runtime
 * wiring against the real planner.
 */
final class BuildPlanDecomposerService
{
    public const PLAN_SCHEMA = 'atlas.plan_execution.decomposed_plan.v1';

    public const STATUS_COMPLETE = 'complete';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_BLOCKED = 'blocked';

    public const MODE_DRY_RUN = 'dry_run';

    public const MODE_RECORD = 'record';

    /** Canonical blockers. */
    public const BLOCKER_NO_SOURCE = 'no_build_plan_source';

    public const BLOCKER_SECTION_6_MISSING = 'decomposition_section_not_found';

    public const BLOCKER_SECTION_10_MISSING = 'sequencing_section_not_found';

    public const BLOCKER_ACCEPTANCE_MISSING = 'acceptance_criteria_missing';

    public const BLOCKER_PLANNER_BLOCKED = 'slice_planner_blocked';

    public const BLOCKER_DANGLING_DEPENDENCY = 'dependency_target_unknown';

    public const BLOCKER_DEPENDENCY_CYCLE = 'dependency_cycle_detected';

    public const BLOCKER_DUPLICATE_SLICE_LABEL = 'duplicate_slice_label_detected';

    private BuildPlanDocumentParser $parser;

    private FindingSlicePlannerService $slicePlanner;

    /** @var (callable(array<string,mixed>):array<string,mixed>)|null */
    private $slicePlannerCallable = null;

    public function __construct(
        ?BuildPlanDocumentParser $parser = null,
        ?FindingSlicePlannerService $slicePlanner = null,
    ) {
        $this->parser = $parser ?? new BuildPlanDocumentParser;
        $this->slicePlanner = $slicePlanner ?? new FindingSlicePlannerService;
    }

    /**
     * Test seam: inject a fake planner that records / shapes plan() calls.
     */
    public function setFindingSlicePlannerForTesting(?FindingSlicePlannerService $p): void
    {
        $this->slicePlanner = $p ?? new FindingSlicePlannerService;
        $this->slicePlannerCallable = null;
    }

    /**
     * Test seam for asserting composition without subclassing the `final`
     * planner: route every per-section plan() call through the given callable.
     *
     * @param  (callable(array<string,mixed>):array<string,mixed>)|null  $callable
     */
    public function setSlicePlannerCallableForTesting(?callable $callable): void
    {
        $this->slicePlannerCallable = $callable;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    protected function runSlicePlanner(array $input): array
    {
        if ($this->slicePlannerCallable !== null) {
            return ($this->slicePlannerCallable)($input);
        }

        return $this->slicePlanner->plan($input);
    }

    /**
     * @param  array{doc_path?:string,build_plan_md?:string,mode?:string,scope_profile?:string}  $input
     * @return array<string,mixed>
     */
    public function decompose(array $input): array
    {
        $mode = $this->mode((string) ($input['mode'] ?? self::MODE_DRY_RUN));
        $scopeProfile = $this->scopeProfile((string) ($input['scope_profile'] ?? FindingSlicePlannerService::SCOPE_BALANCED));
        $docPath = trim((string) ($input['doc_path'] ?? ''));

        $markdown = $this->resolveMarkdown($input, $docPath);
        if ($markdown === null) {
            return $this->blockedPlan('', '', $docPath, [self::BLOCKER_NO_SOURCE]);
        }

        $sourceDocHash = 'sha256:'.MissionCanonicalHash::sha256($markdown);
        $parsed = $this->parser->parse($markdown);

        $planId = (string) $parsed['plan_id'];
        $planTitle = (string) $parsed['plan_title'];

        // No parsable section 6 => blocked, with the source hash still recorded.
        if ($parsed['slices'] === []) {
            return $this->blockedPlan(
                $planId,
                $planTitle,
                $docPath,
                [self::BLOCKER_SECTION_6_MISSING],
                $sourceDocHash,
            );
        }

        $blockers = [];
        $section10Present = (bool) $parsed['section_10_found'];
        if (! $section10Present) {
            $blockers[] = self::BLOCKER_SECTION_10_MISSING;
        }

        // Known-slice map computed ONCE at the top: the single source of truth for
        // which labels are real slices. Preserves table order for tie-breaking.
        $known = [];
        $tableOrder = [];
        $order = 0;
        foreach ($parsed['slices'] as $row) {
            $label = (string) $row['label'];
            if (! isset($known[$label])) {
                $known[$label] = true;
                $tableOrder[$label] = $order++;
            }
        }

        // (a) Resolve edges against KNOWN slices BEFORE building depends_on so that
        // depends_on always matches the dependency_graph node set. Edges that point
        // at an unknown slice (either endpoint) are dropped and force PARTIAL.
        $rawEdges = $section10Present ? $this->normalizeEdges($parsed['dependency_edges']) : [];
        $edges = [];
        $danglingSeen = false;
        foreach ($rawEdges as $edge) {
            if (! isset($known[$edge['from']]) || ! isset($known[$edge['to']])) {
                $danglingSeen = true;

                continue;
            }
            $edges[] = $edge;
        }
        if ($danglingSeen) {
            $blockers[] = self::BLOCKER_DANGLING_DEPENDENCY;
        }

        // (b) Topo check over the slice-restricted edge set. A back-edge means the
        // plan is structurally impossible to order; record the offending slice ids
        // and force PARTIAL. The order is also reused for deterministic sequencing.
        $topo = $this->topoOrder($known, $tableOrder, $edges);
        if ($topo['cycle'] !== []) {
            $blockers[] = self::BLOCKER_DEPENDENCY_CYCLE.':'.implode(',', $topo['cycle']);
        }

        // (c) Sequence is derived from the topo order (numeric S-label then table
        // order as deterministic tie-breakers); when section 10 is absent there are
        // no edges, so topo order collapses to label order.
        $sequenceMap = [];
        $seq = 0;
        foreach ($topo['order'] as $label) {
            $sequenceMap[$label] = ++$seq;
        }

        // (d) Consume the parser's duplicate_slice_labels: a label appearing twice
        // makes every dependency edge to that label ambiguous, so force PARTIAL.
        $duplicateLabels = array_values(array_filter(array_map(
            static fn ($l): string => (string) $l,
            (array) ($parsed['duplicate_slice_labels'] ?? []),
        ), static fn (string $l): bool => $l !== ''));
        if ($duplicateLabels !== []) {
            $blockers[] = self::BLOCKER_DUPLICATE_SLICE_LABEL.':'.implode(',', $duplicateLabels);
        }

        $slices = [];
        $allSliced = true;
        foreach ($parsed['slices'] as $row) {
            $sliceLabel = (string) $row['label'];
            $sequence = $sequenceMap[$sliceLabel] ?? ($tableOrder[$sliceLabel] + 1);
            $built = $this->buildSlice($row, $sequence, $scopeProfile, $mode, $edges);
            $slices[] = $built['slice'];

            if ($built['acceptance_empty']) {
                $blockers[] = self::BLOCKER_ACCEPTANCE_MISSING;
            }
            if ($built['planner_status'] !== FindingSlicePlannerService::STATUS_SLICED) {
                $allSliced = false;
                $blockers[] = self::BLOCKER_PLANNER_BLOCKED;
            }
        }

        // Sort slices into the derived sequence order so the iterable plan presents
        // a dependency-respecting order; sequence field is the stable join key.
        usort($slices, static fn (array $a, array $b): int => $a['sequence'] <=> $b['sequence']);

        $acceptanceComplete = ! in_array(self::BLOCKER_ACCEPTANCE_MISSING, $blockers, true);
        $structureSound = ! $danglingSeen && $topo['cycle'] === [] && $duplicateLabels === [];
        $status = ($section10Present && $allSliced && $acceptanceComplete && $structureSound)
            ? self::STATUS_COMPLETE
            : self::STATUS_PARTIAL;

        return $this->finalize(
            $planId,
            $planTitle,
            $docPath,
            $sourceDocHash,
            $status,
            $slices,
            $this->dependencyGraph($edges, $slices),
            array_values(array_unique($blockers)),
        );
    }

    /**
     * @param  array{label:string,delivery:string,acceptance_criteria:list<string>,authority_guard:string}  $row
     * @param  list<array{from:string,to:string}>  $edges
     * @return array{slice:array<string,mixed>,planner_status:string,acceptance_empty:bool}
     */
    private function buildSlice(array $row, int $sequence, string $scopeProfile, string $mode, array $edges): array
    {
        $sliceId = $row['label'];
        $acceptance = $row['acceptance_criteria'];
        $authorityGuard = $row['authority_guard'];
        $delivery = $row['delivery'];

        $dependsOn = [];
        foreach ($edges as $edge) {
            if ($edge['to'] === $sliceId) {
                $dependsOn[] = $edge['from'];
            }
        }
        $dependsOn = array_values(array_unique($dependsOn));

        // finding_id is SET EQUAL to slice_id so the completion tracker can join
        // on a single unambiguous key.
        $findingHash = 'sha256:'.MissionCanonicalHash::sha256([$sliceId, $delivery, $acceptance, $authorityGuard]);

        $synthetic = $this->syntheticFinding($sliceId, $delivery, $acceptance, $authorityGuard, $findingHash);

        // Delegate slicing to the canonical planner EXACTLY once per section.
        $plannerPlan = $this->runSlicePlanner([
            'finding' => $synthetic,
            'mode' => $mode === self::MODE_RECORD ? FindingSlicePlannerService::MODE_RECORD : FindingSlicePlannerService::MODE_DRY_RUN,
            'scope_profile' => $scopeProfile,
        ]);

        $plannerStatus = (string) ($plannerPlan['decomposition_status'] ?? FindingSlicePlannerService::STATUS_BLOCKED);
        $executableSlices = is_array($plannerPlan['slices'] ?? null) ? $plannerPlan['slices'] : [];

        $owner = $this->ownerFor($authorityGuard, $delivery);

        $slice = [
            'slice_id' => $sliceId,
            'sequence' => $sequence,
            'label' => $sliceId,
            'objective' => $this->objective($sliceId, $delivery),
            'delivery' => $delivery,
            'acceptance_criteria' => $acceptance,
            'authority_guard' => $authorityGuard,
            'depends_on' => $dependsOn,
            'allowed_files' => $this->allowedFilesFromPlanner($executableSlices),
            'owner' => $owner,
            'finding' => $synthetic,
            'executable_slices' => $executableSlices,
            'planner_status' => $plannerStatus,
        ];

        return [
            'slice' => $slice,
            'planner_status' => $plannerStatus,
            'acceptance_empty' => $acceptance === [],
        ];
    }

    /**
     * @param  list<string>  $acceptance
     * @return array<string,mixed>
     */
    private function syntheticFinding(string $sliceId, string $delivery, array $acceptance, string $authorityGuard, string $findingHash): array
    {
        return [
            'title' => $delivery !== '' ? $delivery : $sliceId,
            'detail' => trim($delivery.($acceptance !== [] ? ' Acceptance: '.implode('; ', $acceptance) : '')),
            'affected_files' => [],
            'owner_candidate' => $this->ownerCandidate($authorityGuard, $delivery),
            'finding_id' => $sliceId,
            'finding_hash' => $findingHash,
            'severity' => 'high',
            'kind' => 'build_plan_slice',
            'origin_type' => 'build_plan_decomposition',
            'spec_seed' => [
                'tests_required' => [],
            ],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $executableSlices
     * @return list<string>
     */
    private function allowedFilesFromPlanner(array $executableSlices): array
    {
        $files = [];
        foreach ($executableSlices as $slice) {
            foreach ((array) ($slice['allowed_files'] ?? []) as $file) {
                if (is_string($file) && $file !== '') {
                    $files[] = $file;
                }
            }
        }

        return array_values(array_unique($files));
    }

    private function objective(string $sliceId, string $delivery): string
    {
        return sprintf('Deliver build-plan slice %s: %s', $sliceId, $delivery !== '' ? $delivery : 'see delivery');
    }

    private function ownerCandidate(string $authorityGuard, string $delivery): string
    {
        $text = strtolower($authorityGuard.' '.$delivery);
        if (str_contains($text, 'forge') || str_contains($text, 'obra')) {
            return 'forge';
        }
        if (str_contains($text, 'doc') || str_contains($text, 'canon')) {
            return 'self_directed_evolution';
        }

        return 'atlas_dev';
    }

    private function ownerFor(string $authorityGuard, string $delivery): string
    {
        return match ($this->ownerCandidate($authorityGuard, $delivery)) {
            'forge' => FindingSlicePlannerService::OWNER_FORGE,
            'self_directed_evolution' => FindingSlicePlannerService::OWNER_STEWARDSHIP,
            default => FindingSlicePlannerService::OWNER_ATLAS_DEV,
        };
    }

    /**
     * Kahn topological sort over the slice-restricted edge set with deterministic
     * tie-breakers: numeric S-label first, then table order. Returns the ordered
     * labels and, when a cycle exists, the sorted list of slice ids still trapped
     * in the cycle (the offending nodes). When a cycle is present the returned
     * order still contains every node (cycle nodes appended in tie-break order) so
     * sequencing remains total/deterministic.
     *
     * @param  array<string,bool>  $known
     * @param  array<string,int>  $tableOrder
     * @param  list<array{from:string,to:string}>  $edges
     * @return array{order:list<string>,cycle:list<string>}
     */
    private function topoOrder(array $known, array $tableOrder, array $edges): array
    {
        $indegree = [];
        $adjacency = [];
        foreach (array_keys($known) as $label) {
            $indegree[$label] = 0;
            $adjacency[$label] = [];
        }
        foreach ($edges as $edge) {
            $adjacency[$edge['from']][] = $edge['to'];
            $indegree[$edge['to']]++;
        }

        $tieBreak = function (array $labels) use ($tableOrder): array {
            usort($labels, function (string $a, string $b) use ($tableOrder): int {
                $na = (int) (preg_match('/^S(\d+)$/', $a, $m) === 1 ? $m[1] : PHP_INT_MAX);
                $nb = (int) (preg_match('/^S(\d+)$/', $b, $m) === 1 ? $m[1] : PHP_INT_MAX);
                if ($na !== $nb) {
                    return $na <=> $nb;
                }

                return ($tableOrder[$a] ?? PHP_INT_MAX) <=> ($tableOrder[$b] ?? PHP_INT_MAX);
            });

            return $labels;
        };

        $order = [];
        $resolved = [];
        while (true) {
            $ready = [];
            foreach ($indegree as $label => $deg) {
                if ($deg === 0 && ! isset($resolved[$label])) {
                    $ready[] = $label;
                }
            }
            if ($ready === []) {
                break;
            }
            $ready = $tieBreak($ready);
            $next = $ready[0];
            $order[] = $next;
            $resolved[$next] = true;
            foreach ($adjacency[$next] as $neighbour) {
                $indegree[$neighbour]--;
            }
        }

        $cycle = [];
        foreach (array_keys($known) as $label) {
            if (! isset($resolved[$label])) {
                $cycle[] = $label;
            }
        }
        if ($cycle !== []) {
            // Append remaining (cycle) nodes deterministically so order stays total.
            foreach ($tieBreak($cycle) as $label) {
                $order[] = $label;
            }
            $cycle = $tieBreak($cycle);
        }

        return ['order' => $order, 'cycle' => $cycle];
    }

    /**
     * @param  list<array{from:string,to:string}>  $edges
     * @return list<array{from:string,to:string}>
     */
    private function normalizeEdges(array $edges): array
    {
        $out = [];
        $seen = [];
        foreach ($edges as $edge) {
            $from = (string) ($edge['from'] ?? '');
            $to = (string) ($edge['to'] ?? '');
            if ($from === '' || $to === '' || $from === $to) {
                continue;
            }
            $key = $from.'>'.$to;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = ['from' => $from, 'to' => $to];
        }

        return $out;
    }

    /**
     * @param  list<array{from:string,to:string}>  $edges
     * @param  list<array<string,mixed>>  $slices
     * @return list<array{from_slice_id:string,to_slice_id:string,reason:string}>
     */
    private function dependencyGraph(array $edges, array $slices): array
    {
        $known = [];
        foreach ($slices as $slice) {
            $known[(string) $slice['slice_id']] = true;
        }

        $graph = [];
        foreach ($edges as $edge) {
            if (! isset($known[$edge['from']]) || ! isset($known[$edge['to']])) {
                continue;
            }
            $graph[] = [
                'from_slice_id' => $edge['from'],
                'to_slice_id' => $edge['to'],
                'reason' => 'sequencing_section_arrow',
            ];
        }

        return $graph;
    }

    /**
     * @param  array{doc_path?:string,build_plan_md?:string}  $input
     */
    private function resolveMarkdown(array $input, string $docPath): ?string
    {
        $inline = $input['build_plan_md'] ?? null;
        if (is_string($inline) && trim($inline) !== '') {
            return $inline;
        }

        if ($docPath !== '' && is_file($docPath) && is_readable($docPath)) {
            $contents = file_get_contents($docPath);
            if (is_string($contents) && trim($contents) !== '') {
                return $contents;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $blockers
     * @return array<string,mixed>
     */
    private function blockedPlan(string $planId, string $planTitle, string $docPath, array $blockers, string $sourceDocHash = ''): array
    {
        if ($sourceDocHash === '') {
            $sourceDocHash = 'sha256:'.MissionCanonicalHash::sha256(['blocked', $blockers]);
        }

        return $this->finalize(
            $planId,
            $planTitle,
            $docPath,
            $sourceDocHash,
            self::STATUS_BLOCKED,
            [],
            [],
            array_values(array_unique($blockers)),
        );
    }

    /**
     * @param  list<array<string,mixed>>  $slices
     * @param  list<array{from_slice_id:string,to_slice_id:string,reason:string}>  $dependencyGraph
     * @param  list<string>  $blockers
     * @return array<string,mixed>
     */
    private function finalize(
        string $planId,
        string $planTitle,
        string $docPath,
        string $sourceDocHash,
        string $status,
        array $slices,
        array $dependencyGraph,
        array $blockers,
    ): array {
        $plan = [
            'schema_version' => self::PLAN_SCHEMA,
            'plan_id' => $planId,
            'plan_title' => $planTitle,
            'doc_path' => $docPath,
            'source_doc_hash' => $sourceDocHash,
            'decomposition_status' => $status,
            'slices' => $slices,
            'dependency_graph' => $dependencyGraph,
            'blockers' => $blockers,
        ];
        $plan['plan_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->hashable($plan));

        return $plan;
    }

    /**
     * Canonical, order-stable projection for the deterministic plan_hash.
     *
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>
     */
    private function hashable(array $plan): array
    {
        $slices = [];
        foreach ($plan['slices'] as $slice) {
            $slices[] = [
                'slice_id' => $slice['slice_id'],
                'sequence' => $slice['sequence'],
                'delivery' => $slice['delivery'],
                'acceptance_criteria' => $slice['acceptance_criteria'],
                'authority_guard' => $slice['authority_guard'],
                'depends_on' => $slice['depends_on'],
                'owner' => $slice['owner'],
                'planner_status' => $slice['planner_status'],
            ];
        }

        return [
            'plan_id' => $plan['plan_id'],
            'decomposition_status' => $plan['decomposition_status'],
            'slices' => $slices,
            'dependency_graph' => $plan['dependency_graph'],
            'blockers' => $plan['blockers'],
        ];
    }

    private function mode(string $value): string
    {
        return strtolower(trim($value)) === self::MODE_RECORD ? self::MODE_RECORD : self::MODE_DRY_RUN;
    }

    private function scopeProfile(string $value): string
    {
        return strtolower(trim($value)) === FindingSlicePlannerService::SCOPE_FACTORY_MAX
            ? FindingSlicePlannerService::SCOPE_FACTORY_MAX
            : FindingSlicePlannerService::SCOPE_BALANCED;
    }
}
