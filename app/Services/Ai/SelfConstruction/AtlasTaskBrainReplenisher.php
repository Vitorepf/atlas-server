<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModelBuilder;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionQuery;
use Throwable;

/**
 * PART 2 — the BRAIN → QUEUE bridge: the Atlas STRUCTURES THE TASK LIST ITSELF from its complete comprehension
 * of a scope, and keeps the serving queue full so the AIs (pulling with the fixed worker prompt) never run dry.
 *
 * This is the heart of "o próprio Atlas estrutura a lista vasta para nunca esgotar". It does NOT ask the
 * operator to describe tasks — it reads the omnipresent comprehension (Part 1) of a defined scope and, for each
 * REAL evolution the comprehension surfaces, mints a self-sufficient task packet a cold AI can implement:
 *
 *   - ORPHAN: a class that is BUILT but no caller uses it — a real capability sitting unused. Task: make it
 *     genuinely used/complete. allowed_files = the orphan's own file (+ known callers).
 *   - DOC-STATED GAP: a capability the canonical docs DEMAND but no symbol provides. Task: implement it as a
 *     new file. allowed_files = the proposed new file (greenfield, conflict-free by construction).
 *
 * ANTI-GOODHART (pétreo): it NEVER mints proxy/faxina tasks — the comprehension's `has_test` / `gate_clean`
 * transitions (coverage, the measurability trap) are EXCLUDED. Only real-capability evolution (orphan, doc-gap)
 * is structured. Every task is grounded in a REAL inventory symbol (the comprehension is the oracle), so a task
 * can never cite a hallucinated target. Honest limit: the list is bounded by the real material the scope
 * contains at a snapshot — vast for a real scope, re-derived as the code evolves, but not literally infinite
 * without the model-bound originator (a separate booster) or a wider scope.
 */
final class AtlasTaskBrainReplenisher
{
    public const DEFAULT_TARGET_MIN_CLAIMABLE = 20;

    public const DEFAULT_MAX_PER_RUN = 40;

    /** @var list<array<string,true>>|null memoized token-sets of every class file under the repo's app/ tree */
    private ?array $repoClassTokenSets = null;

    public function __construct(
        private readonly AgentControlPlaneTaskQueueOrchestrator $orchestrator,
        private readonly ?AtlasTaskPacketQualityInspector $inspector = null,
        private readonly ?AtlasLoopScopeComprehensionQuery $query = null,
        private readonly ?string $repoRootOverride = null,
        private readonly ?string $servingDisk = null,
    ) {}

    /**
     * Top up the serving queue from the comprehension of $scopeRoot until it holds $targetMin claimable tasks
     * (or the scope's real material is exhausted, honestly reported).
     *
     * @param  array{docs_roots?:list<string>, max_files?:int}  $opts  comprehension build options for the scope
     * @return array<string, mixed>
     */
    public function replenish(string $scopeRoot, int $targetMin = self::DEFAULT_TARGET_MIN_CLAIMABLE, int $maxPerRun = self::DEFAULT_MAX_PER_RUN, array $opts = [], bool $includeOrphans = false): array
    {
        $targetMin = max(1, $targetMin);
        $maxPerRun = max(1, $maxPerRun);
        $inspector = $this->inspector ?? new AtlasTaskPacketQualityInspector;

        $before = $this->claimableDepth();
        if ($before >= $targetMin) {
            return $this->summary($scopeRoot, $before, $before, [], 'queue_above_watermark');
        }

        try {
            $query = $this->query ?? $this->buildQuery($opts);
            $model = $query->model($scopeRoot);
        } catch (Throwable $e) {
            return array_merge($this->summary($scopeRoot, $before, $before, [], 'comprehension_failed'), ['error' => $e->getMessage()]);
        }

        return $this->replenishFromModel($model, $scopeRoot, $targetMin, $maxPerRun, $before, $inspector, $includeOrphans);
    }

    /**
     * The enqueue loop over a comprehension model (split out so the brain→queue wiring is testable without a
     * multi-second comprehension build).
     *
     * @return array<string, mixed>
     */
    public function replenishFromModel(
        \App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel $model,
        string $scopeRoot,
        int $targetMin,
        int $maxPerRun,
        ?int $before = null,
        ?AtlasTaskPacketQualityInspector $inspector = null,
        bool $includeOrphans = false,
    ): array {
        $inspector ??= new AtlasTaskPacketQualityInspector;
        $before ??= $this->claimableDepth();
        $candidates = $this->structureTasks($model, $includeOrphans);

        $enqueued = [];
        $skippedExisting = 0;
        $skippedDeficient = 0;
        $depth = $before;

        foreach ($candidates as $packet) {
            if ($depth >= $targetMin || count($enqueued) >= $maxPerRun) {
                break;
            }
            $id = (string) $packet['task_packet_id'];

            // Dedup: never re-mint a task the queue already holds (claimable, in-flight, or completed).
            if ($this->queueHas($id)) {
                $skippedExisting++;

                continue;
            }
            if (! (bool) $inspector->inspect($packet)['self_sufficient']) {
                $skippedDeficient++;

                continue;
            }

            try {
                $res = $this->orchestrator->prepareAndEnqueue(['task_packet' => $packet]);
            } catch (Throwable) {
                continue;
            }
            if ((string) ($res['event'] ?? '') === 'prepared_and_enqueued') {
                $enqueued[] = $id;
                $depth++;
            }
        }

        $exhausted = $depth < $targetMin && count($enqueued) < $maxPerRun;

        return array_merge(
            $this->summary($scopeRoot, $before, $depth, $enqueued, $exhausted ? 'material_exhausted' : 'topped_up'),
            ['skipped_existing' => $skippedExisting, 'skipped_deficient' => $skippedDeficient, 'candidates_considered' => count($candidates)],
        );
    }

    /**
     * Structure self-sufficient task packets from the comprehension model's REAL evolution surface
     * (orphans + doc-stated gaps). Deterministic + grounded — every task cites a real inventory member.
     *
     * @return list<array<string,mixed>>
     */
    public function structureTasks(\App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel $model, bool $includeOrphans = false): array
    {
        $out = [];

        // DOC-STATED GAPS — capabilities the canonical docs demand but no symbol provides. RESOLVABLE in-scope:
        // a NEW class + its NEW test, both inside allowed_files (conflict-free; nothing existing to wire).
        // FALSE-POSITIVE GUARD: skip a "gap" whose name already matches a real inventory symbol (the doc
        // mentions a capability that DOES exist) — so a worker never creates a redundant duplicate class.
        $existing = $this->inventoryShortNames($model);
        foreach ($model->docStatedGaps as $gap) {
            $gap = trim((string) $gap);
            if ($gap === '' || $this->gapAlreadyExists($gap, $existing)) {
                continue;
            }
            $className = $this->classNameForGap($gap);
            $newFile = 'app/Services/Ai/AutonomousEvolution/Generated/'.$className.'.php';
            $testFile = 'tests/Unit/Ai/AutonomousEvolution/Generated/'.$className.'Test.php';
            $out[] = $this->packet(
                id: 'brain-docgap-'.substr(md5($gap), 0, 12),
                objective: "Implement the capability the canonical docs require but no symbol provides yet: \"{$gap}\". "
                    ."Create the class `App\\Services\\Ai\\AutonomousEvolution\\Generated\\{$className}` at the EXACT path {$newFile} "
                    ."AND a passing PHPUnit test at the EXACT path {$testFile}. Edit ONLY those two files (do not rename the paths — "
                    ."they are your commit scope). If the capability already exists elsewhere, give_back.",
                allowed: [$newFile, $testFile],
                accept: ["the class {$className} exists at {$newFile}", "the PHPUnit test at {$testFile} passes"],
            );
        }

        // ORPHANS — built-but-unwired capabilities. The wiring target is a DIFFERENT file the comprehension can't
        // pin deterministically, so an in-scope task tends to give_back. OFF by default (opt-in) — surfaced only
        // when the operator wants the multi-file frontier. Honest: these are the model-bound hard ones.
        if ($includeOrphans) {
            foreach ($model->inventory as $item) {
                if ((bool) ($item['is_orphan'] ?? false) !== true) {
                    continue;
                }
                $relPath = (string) ($item['rel_path'] ?? '');
                $fqcn = (string) ($item['fqcn'] ?? '');
                if ($relPath === '' || $fqcn === '') {
                    continue;
                }
                $callers = array_values(array_filter((array) ($model->callerPathsFor($relPath) ?? []), 'is_string'));
                $allowed = array_values(array_unique(array_merge([$relPath], $callers)));
                $short = $this->shortName($fqcn);

                $out[] = $this->packet(
                    id: 'brain-orphan-'.substr(md5($fqcn), 0, 12),
                    objective: "Make the built-but-unused class {$short} ({$fqcn}) genuinely used. It exists in {$relPath} but no caller invokes it. Wire it into the right call site; if the only correct wiring is in a file outside your allowed_files, give_back noting that file.",
                    allowed: $allowed,
                    accept: ["{$short} is invoked by a real caller (no longer an orphan)", 'the scope test suite passes'],
                );
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $allowed
     * @param  list<string>  $accept
     * @return array<string, mixed>
     */
    private function packet(string $id, string $objective, array $allowed, array $accept): array
    {
        return [
            'task_packet_id' => $id,
            'objective' => $objective,
            'operator_id' => 'atlas-brain',
            'allowed_files' => $allowed,
            'scope_in' => $allowed,
            'acceptance_criteria' => $accept,
            'required_evidence' => ['tests_or_gates_result'],
            'tags' => ['brain-originated'],
        ];
    }

    private function claimableDepth(): int
    {
        return count($this->queueRepo()->list(['status' => 'claimable']));
    }

    private function queueHas(string $id): bool
    {
        return $this->queueRepo()->get($id) !== null;
    }

    private function queueRepo(): AgentControlPlaneTaskPacketQueueRepository
    {
        return new AgentControlPlaneTaskPacketQueueRepository($this->servingDisk);
    }

    private function buildQuery(array $opts): AtlasLoopScopeComprehensionQuery
    {
        return new AtlasLoopScopeComprehensionQuery(
            new AtlasLoopScopeComprehensionModelBuilder,
            $this->repoRootOverride ?? base_path(),
            $opts,
        );
    }

    private function shortName(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);

        return (string) end($parts);
    }

    /** A deterministic StudlyCase class name derived from the gap text. */
    private function classNameForGap(string $gap): string
    {
        $name = preg_replace('/[^A-Za-z0-9]+/', ' ', $gap) ?? $gap;
        $studly = str_replace(' ', '', ucwords(trim((string) $name)));
        $studly = $studly === '' ? 'Capability'.substr(md5($gap), 0, 6) : substr($studly, 0, 60);

        return $studly;
    }

    /**
     * Lowercased short class names already in the scope inventory — used to skip false-positive doc-gaps.
     *
     * @return array<string, true>
     */
    private function inventoryShortNames(\App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel $model): array
    {
        $names = [];
        foreach ($model->inventory as $item) {
            $short = strtolower($this->shortName((string) ($item['fqcn'] ?? '')));
            if ($short !== '') {
                $names[$short] = true;
            }
        }

        return $names;
    }

    /**
     * True when the gap names a capability that ALREADY EXISTS — so minting it would only yield a give-back.
     *
     * Two reality checks, because the comprehension's gap scraper emits doc-mentioned class names that are a
     * gap ONLY relative to the narrow comprehended scope:
     *   1. fast path — the gap's derived name matches a scope-inventory short name; and
     *   2. repo-wide — the gap's tokens are a subset of SOME class anywhere under app/. This catches the two
     *      live false-positive shapes the worker kept giving back: a class that exists OUTSIDE the scope
     *      (e.g. App\Models\AtlasLoopProposal), and a concept named in docs that exists under a FULLER name
     *      (AtlasLoopOrchestrator ⊆ AtlasUnifiedLoopOrchestrator). Bias-to-skip is deliberate: an honest
     *      smaller queue beats give-back-bait. A genuinely-missing capability keeps all its discriminating
     *      tokens, so it stays a task.
     */
    private function gapAlreadyExists(string $gap, array $existingShortNames): bool
    {
        if (isset($existingShortNames[strtolower($this->classNameForGap($gap))])) {
            return true;
        }
        $gapTokens = $this->tokens($this->gapShortName($gap));
        if ($gapTokens === []) {
            return false;
        }
        foreach ($this->repoClassTokenSets() as $classTokens) {
            if ($this->isSubsetOf($gapTokens, $classTokens)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The gap's class short-name. The comprehension scraper only emits class-name tokens (App\...\Name or
     * AtlasLoop*); strip any namespace, and fall back to the studly-derived name for free-prose gaps.
     */
    private function gapShortName(string $gap): string
    {
        $gap = trim($gap);
        if (($pos = strrpos($gap, '\\')) !== false) {
            $gap = substr($gap, $pos + 1);
        }

        return $gap !== '' ? $gap : $this->classNameForGap($gap);
    }

    /** Lowercased CamelCase / non-alnum word tokens of a name. */
    private function tokens(string $name): array
    {
        $spaced = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $name) ?? $name;
        $spaced = preg_replace('/[^A-Za-z0-9]+/', ' ', $spaced) ?? $spaced;
        $out = [];
        foreach (preg_split('/\s+/', strtolower(trim($spaced))) ?: [] as $t) {
            if ($t !== '') {
                $out[$t] = true;
            }
        }

        return $out;
    }

    /**
     * @param  array<string,true>  $needle
     * @param  array<string,true>  $haystack
     */
    private function isSubsetOf(array $needle, array $haystack): bool
    {
        foreach ($needle as $k => $_) {
            if (! isset($haystack[$k])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Token-sets of every PHP class file under the repo's app/ AND tests/ trees — the repo-wide existence
     * oracle for the doc-gap false-positive guard. tests/ is included because docs mention TEST class names
     * (e.g. AtlasLoopAutoMergeServiceTest) that exist only under tests/; without it the scraper would mint a
     * "create this test class" gap the worker just gives back. Memoized (one walk per replenisher instance).
     *
     * @return list<array<string,true>>
     */
    private function repoClassTokenSets(): array
    {
        if ($this->repoClassTokenSets !== null) {
            return $this->repoClassTokenSets;
        }
        $base = $this->repoRootOverride ?? base_path();
        $sets = [];
        foreach (['app', 'tests'] as $sub) {
            $root = $base.'/'.$sub;
            if (! is_dir($root)) {
                continue;
            }
            try {
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
                foreach ($it as $info) {
                    if (! $info->isFile() || $info->getExtension() !== 'php') {
                        continue;
                    }
                    $sets[] = $this->tokens(pathinfo((string) $info->getFilename(), PATHINFO_FILENAME));
                }
            } catch (Throwable) {
                // best-effort: a partial/empty oracle just means fewer skips, never a crash.
            }
        }

        return $this->repoClassTokenSets = $sets;
    }

    /**
     * @param  list<string>  $enqueued
     * @return array<string, mixed>
     */
    private function summary(string $scopeRoot, int $before, int $after, array $enqueued, string $status): array
    {
        return [
            'schema' => 'atlas.task_serving.brain_replenish.v1',
            'scope_root' => $scopeRoot,
            'status' => $status,
            'claimable_before' => $before,
            'claimable_after' => $after,
            'enqueued' => $enqueued,
            'enqueued_count' => count($enqueued),
        ];
    }
}
