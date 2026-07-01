<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\VerificationCourt;

/**
 * Pure PLANNER. Derives the ORDERED list of server-side gates to RERUN from task evidence + risk facts.
 * NEVER executes anything — no shell, no git, no provider call, no queue write.
 *
 * INPUT FACTS:
 *   { packet_facts:{declared_gates:list<string>}, evidence_contract_result:{accepted:bool},
 *     changed_files:list<string>, risk_level:string,
 *     project_lane?:{project_id:string, allowed_scope_roots:list<string>} }
 *
 * OUTPUT:
 *   { schema, plan_status ∈ {ready,blocked}, blockers:list<string>, commands:list<{id,name,reason}> }
 *
 * COMMAND CATEGORIES (always included when applicable):
 *   - docs-health           — when changed files include docs/ or *.md
 *   - phpunit-scoped        — when changed files include impl PHP under app/
 *   - declared-gates        — every entry from packet_facts.declared_gates
 *   - diff-check            — diff-style validation (lint / static analysis hint)
 *   - lane-freshness        — only when project_lane.project_id is supplied
 *   - lane-evidence-isolation — only when project_lane.project_id is supplied (alongside
 *     lane-freshness): proves the replay evidence itself never crossed a project-lane boundary.
 *   - false-green-guard, receipt-quorum, freshness-replay — together, whenever risk_level=high
 *     OR the change is broad-scope: a single false_green_guard is not enough proof for a
 *     high-risk/multi-project change — a QUORUM of independent replay receipts and a freshness
 *     check on those receipts are required too.
 *
 * INVARIANTS:
 *   - BLOCKED when evidence_contract_result.accepted !== true or no replayable gate can be derived.
 *   - BLOCKED when every declared gate is vague (names no concrete file/test path) AND changed
 *     files are present — UNLESS at least one declared gate concretely binds to a changed file.
 *     A plan built entirely from unfalsifiable declared gates is not real proof.
 *   - DETERMINISTIC: identical input ⇒ identical command list; command ids are stable
 *     (sha256(category+payload)[0:12]).
 */
final class AtlasVerificationCourtGateReplayPlan
{
    public const SCHEMA = 'atlas.verificationcourt.gate_replay_plan.v1';

    public const STATUS_READY = 'ready';

    public const STATUS_BLOCKED = 'blocked';

    public const BROAD_SCOPE_THRESHOLD = 5;

    /**
     * @param  array{
     *     packet_facts?:array{declared_gates?:list<string>},
     *     evidence_contract_result?:array{accepted?:bool},
     *     changed_files?:list<string>,
     *     risk_level?:string,
     *     project_lane?:array{project_id?:string, allowed_scope_roots?:list<string>}
     * }  $facts
     * @return array{schema:string, plan_status:string, blockers:list<string>, commands:list<array{id:string, name:string, reason:string}>}
     */
    public function derive(array $facts): array
    {
        $blockers = [];
        $accepted = (bool) ($facts['evidence_contract_result']['accepted'] ?? false);
        if (! $accepted) {
            $blockers[] = 'evidence_contract_not_accepted';
        }

        $changed = is_array($facts['changed_files'] ?? null) ? array_values(array_map('strval', $facts['changed_files'])) : [];
        $declared = is_array($facts['packet_facts']['declared_gates'] ?? null) ? array_values(array_map('strval', $facts['packet_facts']['declared_gates'])) : [];
        $lane = is_array($facts['project_lane'] ?? null) ? $facts['project_lane'] : null;

        $riskLevel = (string) ($facts['risk_level'] ?? 'low');
        $commands = [];

        // docs-health
        $docFiles = array_values(array_filter($changed, static fn (string $p): bool => str_starts_with($p, 'docs/') || str_ends_with(strtolower($p), '.md')));
        if ($docFiles !== []) {
            $commands[] = $this->command('docs-health', 'docs_health_check', 'changed_files include docs/ or .md', $docFiles);
        }

        // phpunit-scoped — when changed PHP under app/
        $implFiles = array_values(array_filter($changed, static fn (string $p): bool => str_starts_with($p, 'app/') && str_ends_with($p, '.php')));
        if ($implFiles !== []) {
            $commands[] = $this->command('phpunit-scoped', 'phpunit_scoped', 'changed_files include app/*.php', $implFiles);
        }

        // declared gates
        $vagueGates = [];
        foreach ($declared as $g) {
            $commands[] = $this->command('declared:'.$g, $g, 'declared by packet', $changed);
            if ($changed !== [] && $this->isVagueGate($g, $changed)) {
                $vagueGates[] = $g;
            }
        }

        // acceptance_specificity_check — a declared gate not bound to a specific test path or
        // changed file is unfalsifiable proof (it would pass regardless of what actually changed).
        if ($vagueGates !== []) {
            $commands[] = $this->command('acceptance-specificity', 'acceptance_specificity_check', 'declared gates not bound to changed files: '.implode(',', $vagueGates), $changed);
        }

        // diff-check — always when something changed
        if ($changed !== []) {
            $commands[] = $this->command('diff-check', 'diff_style_check', 'changed_files present', $changed);
        }

        // false_green_guard — high risk or broad scope. A single guard is not enough proof for a
        // high-risk/multi-project change: it must be joined by an independent receipt QUORUM
        // check and a freshness check on those receipts, so a stale or single-source replay
        // can never masquerade as sufficient proof.
        if ($riskLevel === 'high' || count($changed) >= self::BROAD_SCOPE_THRESHOLD) {
            $commands[] = $this->command('false-green-guard', 'false_green_guard', 'high_risk_or_broad_scope', $changed);
            $commands[] = $this->command('receipt-quorum', 'receipt_quorum_check', 'high_risk_or_broad_scope', $changed);
            $commands[] = $this->command('freshness-replay', 'freshness_replay_check', 'high_risk_or_broad_scope', $changed);
        }

        // lane-freshness / lane-evidence-isolation — when project_lane carries a project_id.
        // Freshness alone proves the evidence is recent; isolation proves it never crossed a
        // project-lane boundary — a multi-project change needs both.
        if ($lane !== null && (string) ($lane['project_id'] ?? '') !== '') {
            $commands[] = $this->command('lane-freshness:'.$lane['project_id'], 'lane_freshness_check', 'project_lane attached', $changed);
            $commands[] = $this->command('lane-evidence-isolation:'.$lane['project_id'], 'lane_evidence_isolation_check', 'project_lane attached', $changed);
        }

        // worker-floor replay steps — any task touching queue, Maestro, replenisher, or autonomous
        // completion claims must replay the worker-feed continuity checks, not just its own gates.
        // A task that LOOKS unrelated to worker feed can still silently regress it.
        $workerFloorTouchedFiles = array_values(array_filter($changed, [$this, 'touchesWorkerFloorConcern']));
        if ($workerFloorTouchedFiles !== []) {
            $reason = 'changed_files touch queue/maestro/replenisher/autonomous-completion concerns';
            $commands[] = $this->command('worker-floor-queue-health', 'worker_floor_queue_health_check', $reason, $workerFloorTouchedFiles);
            $commands[] = $this->command('worker-floor-queued-target-collision', 'worker_floor_queued_target_collision_check', $reason, $workerFloorTouchedFiles);
            $commands[] = $this->command('worker-floor-malformed-sweep', 'worker_floor_malformed_sweep', $reason, $workerFloorTouchedFiles);
            $commands[] = $this->command('worker-floor-check', 'worker_floor_check', $reason, $workerFloorTouchedFiles);
        }

        if ($commands === [] && $blockers === []) {
            $blockers[] = 'no_replayable_gate_derivable';
        }

        // A plan built entirely from vague declared gates is unfalsifiable proof — it would pass
        // or fail identically no matter what actually changed. Blocked UNLESS at least one
        // declared gate concretely binds to a changed file (mixing vague + concrete is fine).
        if ($changed !== [] && $declared !== [] && count($vagueGates) === count($declared)) {
            $blockers[] = 'vague_declared_gates_without_concrete_replay_binding';
        }

        sort($blockers, SORT_STRING);

        return [
            'schema' => self::SCHEMA,
            'plan_status' => $blockers === [] ? self::STATUS_READY : self::STATUS_BLOCKED,
            'blockers' => $blockers,
            'commands' => $commands,
        ];
    }

    /**
     * @param  list<string>  $filteredFiles
     * @return array{id:string, name:string, reason:string, changed_file_filter:list<string>, evidence_hash:string|null}
     */
    private function command(string $category, string $name, string $reason, array $filteredFiles = []): array
    {
        return [
            'id' => substr(hash('sha256', $category.'|'.$name), 0, 12),
            'name' => $name,
            'reason' => $reason,
            'changed_file_filter' => $filteredFiles,
            'evidence_hash' => $filteredFiles !== [] ? substr(hash('sha256', implode('|', $filteredFiles)), 0, 16) : null,
        ];
    }

    /** Path substrings that mark a changed file as touching a worker-floor-relevant concern. */
    private const WORKER_FLOOR_CONCERN_PATH_MARKERS = [
        'queue', 'maestro', 'replenish', 'completion', 'autonomy', 'autonomous',
    ];

    /**
     * A declared gate is "vague" when it names no concrete file/test path — it would pass or fail
     * identically no matter which files actually changed.
     *
     * @param  list<string>  $changedFiles
     */
    private function isVagueGate(string $gate, array $changedFiles): bool
    {
        if (str_contains($gate, '.php') || str_contains($gate, '::') || str_contains($gate, '--filter')) {
            return false;
        }
        foreach ($changedFiles as $file) {
            if ($file !== '' && str_contains($gate, $file)) {
                return false;
            }
            $basename = basename($file);
            if ($basename !== '' && str_contains($gate, $basename)) {
                return false;
            }
        }

        return true;
    }

    private function touchesWorkerFloorConcern(string $path): bool
    {
        $lower = strtolower($path);
        foreach (self::WORKER_FLOOR_CONCERN_PATH_MARKERS as $marker) {
            if (str_contains($lower, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $list
     * @param  callable(string):bool  $predicate
     */
    private function anyMatches(array $list, callable $predicate): bool
    {
        foreach ($list as $item) {
            if ($predicate($item)) {
                return true;
            }
        }

        return false;
    }
}
