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
    public function replenish(string $scopeRoot, int $targetMin = self::DEFAULT_TARGET_MIN_CLAIMABLE, int $maxPerRun = self::DEFAULT_MAX_PER_RUN, array $opts = []): array
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

        return $this->replenishFromModel($model, $scopeRoot, $targetMin, $maxPerRun, $before, $inspector);
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
    ): array {
        $inspector ??= new AtlasTaskPacketQualityInspector;
        $before ??= $this->claimableDepth();
        $candidates = $this->structureTasks($model);

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
    public function structureTasks(\App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel $model): array
    {
        $out = [];

        // ORPHANS — built-but-unwired capabilities. Real evolution: make them genuinely used/complete.
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
                objective: "Make the built-but-unused class {$short} ({$fqcn}) genuinely used. It exists in {$relPath} but no caller invokes it. Wire it into the right call site so the capability is actually exercised; if the only correct wiring is in a file outside your allowed_files, hand the task back (give_back) noting that file.",
                allowed: $allowed,
                accept: ["{$short} is invoked by a real caller (no longer an orphan)", 'the scope test suite passes'],
            );
        }

        // DOC-STATED GAPS — capabilities the canonical docs demand but no symbol provides. Real evolution: build it.
        foreach ($model->docStatedGaps as $gap) {
            $gap = trim((string) $gap);
            if ($gap === '') {
                continue;
            }
            $newFile = $this->proposedPathForGap($gap);
            $out[] = $this->packet(
                id: 'brain-docgap-'.substr(md5($gap), 0, 12),
                objective: "Implement the capability the canonical docs require but no symbol provides yet: \"{$gap}\". Create it as a new, tested class.",
                allowed: [$newFile],
                accept: ["a new class implementing \"{$gap}\" exists at {$newFile}", 'it has a passing test'],
            );
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

    private function proposedPathForGap(string $gap): string
    {
        // Derive a plausible new-file path from the gap text (best-effort; the AI confirms the final location).
        $name = preg_replace('/[^A-Za-z0-9]+/', ' ', $gap) ?? $gap;
        $studly = str_replace(' ', '', ucwords(trim((string) $name)));
        $studly = $studly === '' ? 'Capability'.substr(md5($gap), 0, 6) : substr($studly, 0, 60);

        return 'app/Services/Ai/AutonomousEvolution/Generated/'.$studly.'.php';
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
