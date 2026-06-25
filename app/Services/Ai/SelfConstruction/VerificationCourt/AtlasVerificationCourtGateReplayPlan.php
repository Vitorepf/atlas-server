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
 *
 * INVARIANTS:
 *   - BLOCKED when evidence_contract_result.accepted !== true or no replayable gate can be derived.
 *   - DETERMINISTIC: identical input ⇒ identical command list; command ids are stable
 *     (sha256(category+payload)[0:12]).
 */
final class AtlasVerificationCourtGateReplayPlan
{
    public const SCHEMA = 'atlas.verificationcourt.gate_replay_plan.v1';

    public const STATUS_READY = 'ready';

    public const STATUS_BLOCKED = 'blocked';

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

        $commands = [];

        // docs-health
        $hasDocs = $this->anyMatches($changed, static fn (string $p): bool => str_starts_with($p, 'docs/') || str_ends_with(strtolower($p), '.md'));
        if ($hasDocs) {
            $commands[] = $this->command('docs-health', 'docs_health_check', 'changed_files include docs/ or .md');
        }

        // phpunit-scoped — when changed PHP under app/
        $hasImpl = $this->anyMatches($changed, static fn (string $p): bool => str_starts_with($p, 'app/') && str_ends_with($p, '.php'));
        if ($hasImpl) {
            $commands[] = $this->command('phpunit-scoped', 'phpunit_scoped', 'changed_files include app/*.php');
        }

        // declared gates
        foreach ($declared as $g) {
            $commands[] = $this->command('declared:'.$g, $g, 'declared by packet');
        }

        // diff-check — always when something changed
        if ($changed !== []) {
            $commands[] = $this->command('diff-check', 'diff_style_check', 'changed_files present');
        }

        // lane-freshness — when project_lane carries a project_id
        if ($lane !== null && (string) ($lane['project_id'] ?? '') !== '') {
            $commands[] = $this->command('lane-freshness:'.$lane['project_id'], 'lane_freshness_check', 'project_lane attached');
        }

        if ($commands === [] && $blockers === []) {
            $blockers[] = 'no_replayable_gate_derivable';
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
     * @return array{id:string, name:string, reason:string}
     */
    private function command(string $category, string $name, string $reason): array
    {
        return [
            'id' => substr(hash('sha256', $category.'|'.$name), 0, 12),
            'name' => $name,
            'reason' => $reason,
        ];
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
