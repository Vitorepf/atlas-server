<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Evolution Implementation Handoff — runtime.
 *
 * Turns the evolution implementation-handoff doc into pure, deterministic
 * decision logic. The doc governs HOW an implementation agent picks up the
 * Atlas AI evolution roadmap: in what order, what it must declare on handoff,
 * and when a piece of work is actually "done". This service enforces those
 * three contracts instead of restating them as prose.
 *
 * Contract 1 — "Canonical Order" + decision "Close one DoD before opening the
 * next autonomy layer": the nine work items are strictly ordered. An agent must
 * take the FIRST not-done item; it may NOT open a later autonomy layer while an
 * earlier one is unfinished. A handoff that claims a later item done while an
 * earlier item is still open is an out-of-order violation and is blocked.
 *
 * Contract 2 — "Agent Handoff": every implementation agent must state seven
 * things (AP/child doc, contract extended, files owned, migrations/events,
 * tests, validation commands run, docs updated). A handoff missing any of the
 * seven is incomplete and is told exactly which fields are missing.
 *
 * Contract 3 — "Done Means" + "Validation Commands" + the forbidden_changes
 * invariant ("Do not declare runtime/readiness without verifiable evidence and
 * green gates"): work is "done" only when ALL five DoD criteria hold AND every
 * one of the five validation commands reported ok. Two DoD criteria are hard
 * structural blockers straight from the doc — a new `split_required` blocker
 * and a parallel subsystem each force not_done on their own. The decision
 * "Implement evolution as APs and focused contracts" is enforced as: a parallel
 * subsystem (new name for an existing function) is never "done".
 *
 * Stateless and DB-free: every method is a pure function of its arguments and
 * returns a typed, auditable array carrying a stable receipt schema.
 *
 * @see docs/engineering-knowledge-base/evolution/implementation-handoff.md
 */
final class AtlasEvolutionImplementationHandoffService
{
    public const SCHEMA_ORDER = 'atlas.evolution.implementation_handoff.order.v1';
    public const SCHEMA_HANDOFF = 'atlas.evolution.implementation_handoff.agent.v1';
    public const SCHEMA_DONE = 'atlas.evolution.implementation_handoff.done.v1';

    /** Verdicts for the canonical-order decision. */
    public const ORDER_PROCEED = 'proceed';
    public const ORDER_OUT_OF_ORDER = 'out_of_order';
    public const ORDER_COMPLETE = 'all_complete';

    /** Verdicts for the agent-handoff decision. */
    public const HANDOFF_COMPLETE = 'complete';
    public const HANDOFF_INCOMPLETE = 'incomplete';

    /** Verdicts for the Done-Means decision. */
    public const DONE = 'done';
    public const NOT_DONE = 'not_done';

    /**
     * The "Canonical Order" table, in documented order. The first not-done item
     * is the only work an agent may open next. Each item that follows an
     * autonomy layer must wait until its predecessor's DoD is closed.
     *
     * @var list<array{order:int,key:string,work:string}>
     */
    public const CANONICAL_ORDER = [
        ['order' => 1, 'key' => 'ap_99_provider_performance_contract', 'work' => 'AP-99 Provider Performance Contract'],
        ['order' => 2, 'key' => 'ap_100_context_pack_manifest_reflection', 'work' => 'AP-100 Context Pack Manifest Reflection'],
        ['order' => 3, 'key' => 'ap_101_retrieval_router', 'work' => 'AP-101 Retrieval Router'],
        ['order' => 4, 'key' => 'self_rag_self_reflection_gate', 'work' => 'Self-RAG / Self-Reflection Gate'],
        ['order' => 5, 'key' => 'graph_rag_relations', 'work' => 'Graph RAG explicit and observed relations'],
        ['order' => 6, 'key' => 'tool_synthesis_sandbox', 'work' => 'Tool Synthesis Sandbox'],
        ['order' => 7, 'key' => 'zero_click_shadow_mode', 'work' => 'Zero-Click shadow mode'],
        ['order' => 8, 'key' => 'personal_longitudinal_projections', 'work' => 'Personal longitudinal projections'],
        ['order' => 9, 'key' => 'proactive_curator_proposal_loops', 'work' => 'Proactive Curator proposal loops'],
    ];

    /**
     * The seven "Agent Handoff" statements, in documented order. Each maps to a
     * field the agent must fill (non-empty) before a handoff is complete.
     *
     * @var array<string,string>
     */
    public const HANDOFF_FIELDS = [
        'ap_or_child_doc' => 'AP or child doc being implemented',
        'contract_extended' => 'existing contract extended',
        'files_owned' => 'files owned',
        'migrations_or_events' => 'migrations or events added',
        'tests_added' => 'tests added',
        'validation_commands_run' => 'validation commands run',
        'docs_updated' => 'docs updated',
    ];

    /**
     * The five "Done Means" criteria, in documented order. ALL must hold for a
     * piece of work to be done. `no_split_required_blocker` and
     * `no_parallel_subsystem` are hard structural blockers.
     *
     * @var array<string,string>
     */
    public const DONE_CRITERIA = [
        'no_split_required_blocker' => 'No new split_required blocker.',
        'no_parallel_subsystem' => 'No parallel subsystem.',
        'canonical_index_points_to_child_doc' => 'Canonical index or README points to the new child doc.',
        'evidence_and_policy_explicit' => 'Evidence and Policy impact is explicit.',
        'override_and_autonomy_documented' => 'Manual override and autonomy boundaries are documented.',
    ];

    /**
     * The five "Validation Commands". Every command must report ok before a
     * piece of work may be declared done (forbidden_changes: no readiness claim
     * without green gates).
     *
     * @var array<string,string>
     */
    public const VALIDATION_COMMANDS = [
        'architecture_validate' => 'php artisan atlas:ai:architecture-validate --json',
        'docs_health' => 'atlas engineering knowledge docs-health --json',
        'knowledge_sync' => 'atlas engineering knowledge sync --prune --json',
        'index_code' => 'atlas engineering knowledge index-code --prune --workspace=... --json',
        'git_diff_check' => 'git diff --check',
    ];

    /**
     * Decide which canonical-order work an agent may open next, and whether a
     * claimed set of completed items respects the order.
     *
     * @param array<int,string>|array<string,bool> $completed
     *   Either a list of completed item keys, or a map key=>bool. Any key not in
     *   CANONICAL_ORDER is ignored. The order is violated when a later item is
     *   marked done while an earlier item is not (opening a later autonomy layer
     *   before closing the earlier DoD).
     *
     * @return array<string,mixed>
     */
    public function nextWork(array $completed): array
    {
        $done = $this->normalizeCompletedMap($completed);

        $next = null;
        $outOfOrder = [];
        $sawNotDone = false;

        foreach (self::CANONICAL_ORDER as $item) {
            $isDone = $done[$item['key']] ?? false;

            if (! $isDone) {
                // First not-done item is the work an agent may open next.
                if ($next === null) {
                    $next = $item;
                }
                $sawNotDone = true;

                continue;
            }

            // This item is marked done. If any EARLIER item is still open, this
            // is an out-of-order completion: a later layer was opened before the
            // earlier DoD closed.
            if ($sawNotDone) {
                $outOfOrder[] = $item['key'];
            }
        }

        if ($outOfOrder !== []) {
            $verdict = self::ORDER_OUT_OF_ORDER;
        } elseif ($next === null) {
            $verdict = self::ORDER_COMPLETE;
        } else {
            $verdict = self::ORDER_PROCEED;
        }

        $completedCount = count(array_filter($done));

        return [
            'schema' => self::SCHEMA_ORDER,
            'verdict' => $verdict,
            'may_proceed' => $verdict === self::ORDER_PROCEED,
            'next_work' => $next,
            'next_key' => $next['key'] ?? null,
            'out_of_order' => array_values($outOfOrder),
            'completed_count' => $completedCount,
            'total' => count(self::CANONICAL_ORDER),
            'all_complete' => $verdict === self::ORDER_COMPLETE,
            'auditable' => true,
        ];
    }

    /**
     * Evaluate an "Agent Handoff" statement: are all seven required fields
     * present and non-empty?
     *
     * @param array<string,mixed> $statement Map of HANDOFF_FIELDS keys to the
     *   value the agent declared. A missing key, null, empty string, or empty
     *   array counts as not stated.
     *
     * @return array<string,mixed>
     */
    public function evaluateHandoff(array $statement): array
    {
        $present = [];
        $missing = [];

        foreach (array_keys(self::HANDOFF_FIELDS) as $field) {
            if ($this->isStated($statement[$field] ?? null)) {
                $present[] = $field;
            } else {
                $missing[] = $field;
            }
        }

        $verdict = $missing === [] ? self::HANDOFF_COMPLETE : self::HANDOFF_INCOMPLETE;

        return [
            'schema' => self::SCHEMA_HANDOFF,
            'verdict' => $verdict,
            'complete' => $verdict === self::HANDOFF_COMPLETE,
            'present' => array_values($present),
            'missing' => array_values($missing),
            'present_count' => count($present),
            'total' => count(self::HANDOFF_FIELDS),
            'auditable' => true,
        ];
    }

    /**
     * Decide whether a piece of work meets "Done Means". ALL five DoD criteria
     * must hold AND every validation command must report ok. The two structural
     * blockers (`no_split_required_blocker`, `no_parallel_subsystem`) are called
     * out explicitly so callers can see a single blocker is enough to fail.
     *
     * @param array<string,mixed> $work
     *   criteria   : array<string,bool> the five DONE_CRITERIA keys; missing
     *                => not satisfied (a missing criterion never passes).
     *   validation : array<string,mixed> per-command result keyed by
     *                VALIDATION_COMMANDS keys. Each value may be a bool
     *                (true=ok) or an array { status:string }. A status other
     *                than ok/pass/green is a failure; missing => failure.
     *
     * @return array<string,mixed>
     */
    public function evaluateDoneMeans(array $work): array
    {
        $criteria = is_array($work['criteria'] ?? null) ? $work['criteria'] : [];
        $validation = is_array($work['validation'] ?? null) ? $work['validation'] : [];

        $criteriaResult = [];
        $unmetCriteria = [];
        foreach (array_keys(self::DONE_CRITERIA) as $key) {
            $met = (bool) ($criteria[$key] ?? false);
            $criteriaResult[$key] = $met;
            if (! $met) {
                $unmetCriteria[] = $key;
            }
        }

        $validationResult = [];
        $failingCommands = [];
        foreach (array_keys(self::VALIDATION_COMMANDS) as $key) {
            $ok = $this->commandReportedOk($validation[$key] ?? null);
            $validationResult[$key] = $ok;
            if (! $ok) {
                $failingCommands[] = $key;
            }
        }

        // Hard structural blockers from the doc: a new split_required blocker or
        // a parallel subsystem each force not_done by themselves.
        $structuralBlockers = [];
        if (! $criteriaResult['no_split_required_blocker']) {
            $structuralBlockers[] = 'split_required_blocker';
        }
        if (! $criteriaResult['no_parallel_subsystem']) {
            $structuralBlockers[] = 'parallel_subsystem';
        }

        $reasons = [];
        foreach ($unmetCriteria as $key) {
            $reasons[] = 'criterion_unmet:' . $key;
        }
        foreach ($failingCommands as $key) {
            $reasons[] = 'validation_not_ok:' . $key;
        }

        $verdict = ($unmetCriteria === [] && $failingCommands === [])
            ? self::DONE
            : self::NOT_DONE;

        return [
            'schema' => self::SCHEMA_DONE,
            'verdict' => $verdict,
            'is_done' => $verdict === self::DONE,
            'criteria' => $criteriaResult,
            'unmet_criteria' => array_values($unmetCriteria),
            'validation' => $validationResult,
            'failing_commands' => array_values($failingCommands),
            'structural_blockers' => array_values($structuralBlockers),
            'reasons' => $reasons,
            'auditable' => true,
        ];
    }

    /**
     * Convenience predicate: may this work be declared done?
     *
     * @param array<string,mixed> $work
     */
    public function isDone(array $work): bool
    {
        return $this->evaluateDoneMeans($work)['verdict'] === self::DONE;
    }

    /**
     * Normalize the completed argument into a key=>bool map restricted to known
     * canonical-order keys.
     *
     * @param array<int,string>|array<string,bool> $completed
     * @return array<string,bool>
     */
    private function normalizeCompletedMap(array $completed): array
    {
        $known = [];
        foreach (self::CANONICAL_ORDER as $item) {
            $known[$item['key']] = false;
        }

        foreach ($completed as $k => $v) {
            if (is_int($k)) {
                // List form: value is a key string.
                if (is_string($v) && array_key_exists($v, $known)) {
                    $known[$v] = true;
                }

                continue;
            }
            // Map form: key=>bool.
            if (array_key_exists($k, $known)) {
                $known[$k] = (bool) $v;
            }
        }

        return $known;
    }

    /**
     * A handoff field counts as stated when it is a non-empty string or a
     * non-empty array; null / '' / [] / false count as not stated.
     */
    private function isStated(mixed $value): bool
    {
        if (is_string($value)) {
            return trim($value) !== '';
        }
        if (is_array($value)) {
            return $value !== [];
        }
        if (is_bool($value)) {
            return $value;
        }

        return $value !== null;
    }

    /**
     * A validation entry is ok only when boolean true, or an array/string whose
     * status is ok/pass/passed/green. Missing / false / other => failure.
     */
    private function commandReportedOk(mixed $entry): bool
    {
        if (is_bool($entry)) {
            return $entry;
        }
        if (is_array($entry)) {
            $status = strtolower((string) ($entry['status'] ?? ''));

            return in_array($status, ['ok', 'pass', 'passed', 'green'], true);
        }
        if (is_string($entry)) {
            return in_array(strtolower($entry), ['ok', 'pass', 'passed', 'green'], true);
        }

        return false;
    }
}
