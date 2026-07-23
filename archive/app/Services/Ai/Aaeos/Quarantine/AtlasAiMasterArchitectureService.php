<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas AI Master Architecture — canonical-flow + plane-authority gate.
 *
 * Pure, deterministic enforcement of the two concrete contracts the master
 * architecture doc declares for every request that enters Atlas AI:
 *
 *  1. The "Canonical Flow": every surface must enter THE SAME ordered pipeline
 *
 *       surface -> input -> envelope -> intent -> business_context
 *       -> domain_profile_flow -> context -> policy -> decide -> receipt
 *       -> runtime -> gates -> repair -> evidence -> learning -> output
 *
 *     The doc says "Every surface must enter the same Atlas AI pipeline", so a
 *     trace that runs the stages out of order, or that jumps straight to
 *     `runtime`/`decide` skipping the upstream stages, is a violation. `repair`
 *     is optional (it only runs when a gate fails); every other stage is
 *     mandatory and ordered.
 *
 *  2. The "Non-Negotiable Rules" (11): authority rules that say WHO may do WHAT.
 *     Each rule is reduced to a checkable invariant over a normalized trace:
 *
 *       1.  Surface does not decide          -> decide actor kind != surface
 *       2.  Provider does not decide         -> decide actor kind != provider
 *       3.  Tool does not decide             -> decide actor kind != tool
 *       4.  Domain does not bypass Policy    -> `policy` stage present before `decide`
 *       5.  Runtime needs Decision Receipt   -> `receipt` stage present before `runtime`
 *       6.  AtlasVault is curated, not raw   -> vault not flagged operational_truth
 *       7.  Obras Workspace coordinates only -> workspace not the decide actor
 *       8.  Business context is not a domain -> business_context not typed cognitive_domain
 *       9.  Repeated -> Core                 -> a repeated signal must be promoted to core
 *       10. Important -> Evidence            -> an important event must carry evidence
 *       11. Curator no auto-apply critical   -> critical curator proposal must be reviewed
 *
 * The verdict is the controlled outcome the architecture demands: a request is
 * `admitted` only when it traverses the canonical flow in order AND breaches no
 * non-negotiable; otherwise it is `rejected` with the machine reasons.
 *
 * The service NEVER calls a provider, runs a tool, mutates a codebase, walks the
 * vault or touches the database. It is a pure judgement over a description of
 * what a request/trace did, returning the gate decision plus an audit map.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-master-architecture.md
 */
final class AtlasAiMasterArchitectureService
{
    /** Stable schema id for the decision this service emits. */
    public const SCHEMA = 'atlas.ai.master_architecture.gate.v1';

    /** Canonical verdicts (closed set). */
    public const VERDICT_ADMITTED = 'admitted';
    public const VERDICT_REJECTED = 'rejected';

    /**
     * The Canonical Flow, in the exact order the doc declares it. Index = the
     * stage's required position. A trace must visit these stages in this order.
     *
     * @var list<string>
     */
    public const CANONICAL_FLOW = [
        'surface',
        'input',
        'envelope',
        'intent',
        'business_context',
        'domain_profile_flow',
        'context',
        'policy',
        'decide',
        'receipt',
        'runtime',
        'gates',
        'repair',
        'evidence',
        'learning',
        'output',
    ];

    /**
     * Stages that are mandatory in every trace. `repair` is the only optional
     * stage — it runs only when a gate fails — so it is excluded here.
     *
     * @var list<string>
     */
    private const OPTIONAL_STAGES = ['repair'];

    /**
     * Actor kinds that the doc forbids from performing the `decide` stage.
     * "Surface does not decide / Provider does not decide / Tool does not
     * decide" + "Obras Shared Workspace ... does not decide".
     *
     * @var array<string,string>
     */
    private const FORBIDDEN_DECIDERS = [
        'surface' => 'surface_decided',     // rule 1
        'provider' => 'provider_decided',   // rule 2
        'tool' => 'tool_decided',           // rule 3
        'workspace' => 'workspace_decided', // rule 7
    ];

    /**
     * Judge one request/trace against the master architecture contract.
     *
     * @param  array<string,mixed>  $trace  {
     *   stages              : list<string>  ordered stages the request visited,
     *   decide_actor_kind   : string        who performed `decide`
     *                         (one of surface|provider|tool|workspace|atlas|...),
     *   vault_operational_truth : bool       did the trace treat AtlasVault as
     *                         raw operational truth? (breach if true),
     *   business_context_type   : string     how business/project context was
     *                         typed (breach if 'cognitive_domain'),
     *   repeated_signals    : list<array{signal:string,promoted_to_core:bool}>,
     *   important_events    : list<array{event:string,has_evidence:bool}>,
     *   curator_proposals   : list<array{
     *                            id:string, critical:bool,
     *                            auto_applied:bool, reviewed:bool }>,
     * }
     *
     * @return array<string,mixed> the verdict + audit map
     */
    public function evaluate(array $trace): array
    {
        $stages = $this->stageList($trace['stages'] ?? []);

        $flow = $this->evaluateCanonicalFlow($stages);
        $rules = $this->evaluateNonNegotiables($trace, $stages);

        $breaches = array_merge($flow['breaches'], $rules['breaches']);
        $admitted = $breaches === [];

        return [
            'schema' => self::SCHEMA,
            'verdict' => $admitted ? self::VERDICT_ADMITTED : self::VERDICT_REJECTED,
            'admitted' => $admitted,
            'breaches' => $breaches,
            'canonical_flow' => $flow,
            'non_negotiables' => $rules['rules'],
        ];
    }

    /**
     * Validate the Canonical Flow: every mandatory stage present, no unknown
     * stage, and the visited stages strictly non-decreasing in canonical order
     * (no stage appears before a stage that should precede it).
     *
     * @param  list<string>  $stages
     *
     * @return array{ordered:bool,missing:list<string>,unknown:list<string>,
     *                out_of_order:list<array{stage:string,after:string}>,
     *                breaches:list<string>}
     */
    public function evaluateCanonicalFlow(array $stages): array
    {
        $position = array_flip(self::CANONICAL_FLOW);

        // Unknown stages — anything not in the canonical flow.
        $unknown = [];
        foreach ($stages as $stage) {
            if (! array_key_exists($stage, $position)) {
                $unknown[] = $stage;
            }
        }
        $unknown = array_values(array_unique($unknown));

        // Missing mandatory stages.
        $present = array_flip($stages);
        $missing = [];
        foreach (self::CANONICAL_FLOW as $stage) {
            if (in_array($stage, self::OPTIONAL_STAGES, true)) {
                continue;
            }
            if (! array_key_exists($stage, $present)) {
                $missing[] = $stage;
            }
        }

        // Order check: walk known stages; each must not have a canonical index
        // lower than a stage already seen.
        $outOfOrder = [];
        $maxSeen = -1;
        $maxStage = '';
        foreach ($stages as $stage) {
            if (! array_key_exists($stage, $position)) {
                continue; // unknown handled above
            }
            $idx = $position[$stage];
            if ($idx < $maxSeen) {
                $outOfOrder[] = ['stage' => $stage, 'after' => $maxStage];
            } else {
                $maxSeen = $idx;
                $maxStage = $stage;
            }
        }

        $breaches = [];
        if ($missing !== []) {
            $breaches[] = 'flow_incomplete';
        }
        if ($unknown !== []) {
            $breaches[] = 'flow_unknown_stage';
        }
        if ($outOfOrder !== []) {
            $breaches[] = 'flow_out_of_order';
        }

        return [
            'ordered' => $missing === [] && $unknown === [] && $outOfOrder === [],
            'missing' => $missing,
            'unknown' => $unknown,
            'out_of_order' => $outOfOrder,
            'breaches' => $breaches,
        ];
    }

    /**
     * Evaluate the 11 non-negotiable rules. Returns a per-rule pass/fail map plus
     * the flat list of breach codes (one per failed rule).
     *
     * @param  array<string,mixed>  $trace
     * @param  list<string>  $stages
     *
     * @return array{rules:array<int,array{rule:int,name:string,passed:bool,reason:?string}>,
     *                breaches:list<string>}
     */
    public function evaluateNonNegotiables(array $trace, array $stages): array
    {
        $present = array_flip($stages);
        $firstIndex = $this->firstIndexMap($stages);

        $decideActor = $this->lower($trace['decide_actor_kind'] ?? 'atlas');

        $results = [];

        // Rules 1,2,3,7 — forbidden deciders.
        $decideBreach = self::FORBIDDEN_DECIDERS[$decideActor] ?? null;
        $results[] = $this->rule(1, 'surface_does_not_decide', $decideActor !== 'surface',
            $decideActor === 'surface' ? 'surface_decided' : null);
        $results[] = $this->rule(2, 'provider_does_not_decide', $decideActor !== 'provider',
            $decideActor === 'provider' ? 'provider_decided' : null);
        $results[] = $this->rule(3, 'tool_does_not_decide', $decideActor !== 'tool',
            $decideActor === 'tool' ? 'tool_decided' : null);

        // Rule 4 — Domain does not bypass Policy: `policy` must come before `decide`.
        $policyOk = $this->before($firstIndex, 'policy', 'decide', $present);
        $results[] = $this->rule(4, 'domain_does_not_bypass_policy', $policyOk,
            $policyOk ? null : 'policy_bypassed');

        // Rule 5 — Runtime does not execute without Decision Receipt.
        $receiptOk = $this->before($firstIndex, 'receipt', 'runtime', $present);
        $results[] = $this->rule(5, 'runtime_requires_decision_receipt', $receiptOk,
            $receiptOk ? null : 'runtime_without_receipt');

        // Rule 6 — AtlasVault is curated human knowledge, not raw operational truth.
        $vaultBreach = (bool) ($trace['vault_operational_truth'] ?? false);
        $results[] = $this->rule(6, 'vault_is_not_operational_truth', ! $vaultBreach,
            $vaultBreach ? 'vault_used_as_operational_truth' : null);

        // Rule 7 — Obras Shared Workspace coordinates; it does not decide.
        $results[] = $this->rule(7, 'workspace_does_not_decide', $decideActor !== 'workspace',
            $decideActor === 'workspace' ? 'workspace_decided' : null);

        // Rule 8 — Business/project context is not a cognitive domain.
        $bizType = $this->lower($trace['business_context_type'] ?? 'context');
        $bizBreach = $bizType === 'cognitive_domain';
        $results[] = $this->rule(8, 'business_context_is_not_a_domain', ! $bizBreach,
            $bizBreach ? 'business_context_typed_as_domain' : null);

        // Rule 9 — Everything repeated becomes Core.
        $unpromoted = $this->unpromotedRepeats($trace['repeated_signals'] ?? []);
        $results[] = $this->rule(9, 'repeated_becomes_core', $unpromoted === [],
            $unpromoted !== [] ? 'repeated_not_promoted_to_core' : null);

        // Rule 10 — Everything important becomes Evidence.
        $unevidenced = $this->unevidencedImportant($trace['important_events'] ?? []);
        $results[] = $this->rule(10, 'important_becomes_evidence', $unevidenced === [],
            $unevidenced !== [] ? 'important_without_evidence' : null);

        // Rule 11 — Curator does not auto-apply critical behavior without review.
        $unsafe = $this->unsafeCuratorProposals($trace['curator_proposals'] ?? []);
        $results[] = $this->rule(11, 'curator_no_auto_apply_critical', $unsafe === [],
            $unsafe !== [] ? 'curator_auto_applied_critical_without_review' : null);

        $breaches = [];
        foreach ($results as $r) {
            if (! $r['passed'] && $r['reason'] !== null) {
                $breaches[] = $r['reason'];
            }
        }

        // Keep the unused-variable analyzer honest: $decideBreach documents the
        // single consolidated decider breach; per-rule codes above are canonical.
        unset($decideBreach);

        return ['rules' => $results, 'breaches' => array_values(array_unique($breaches))];
    }

    /**
     * Convenience: the ordered canonical flow, for callers/CLIs that want to
     * render or document the pipeline.
     *
     * @return list<string>
     */
    public function canonicalFlow(): array
    {
        return self::CANONICAL_FLOW;
    }

    // ---- helpers -----------------------------------------------------------

    /**
     * @param  array<string,int>  $firstIndex
     * @param  array<string,int>  $present
     */
    private function before(array $firstIndex, string $earlier, string $later, array $present): bool
    {
        // If the dependent stage never ran, the dependency cannot be violated by
        // it. (The canonical-flow check separately flags the missing stage.)
        if (! array_key_exists($later, $present)) {
            return true;
        }
        // The required precondition must be present AND appear before the dependent.
        if (! array_key_exists($earlier, $firstIndex)) {
            return false;
        }

        return $firstIndex[$earlier] < $firstIndex[$later];
    }

    /**
     * First occurrence index of each stage in the visited order.
     *
     * @param  list<string>  $stages
     *
     * @return array<string,int>
     */
    private function firstIndexMap(array $stages): array
    {
        $map = [];
        foreach ($stages as $i => $stage) {
            if (! array_key_exists($stage, $map)) {
                $map[$stage] = $i;
            }
        }

        return $map;
    }

    /**
     * @param  mixed  $signals  list<array{signal:string,promoted_to_core:bool}>
     *
     * @return list<string>
     */
    private function unpromotedRepeats(mixed $signals): array
    {
        $out = [];
        if (! is_array($signals)) {
            return $out;
        }
        foreach ($signals as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (! (bool) ($row['promoted_to_core'] ?? false)) {
                $name = $this->str($row['signal'] ?? '');
                if ($name !== '') {
                    $out[] = $name;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  mixed  $events  list<array{event:string,has_evidence:bool}>
     *
     * @return list<string>
     */
    private function unevidencedImportant(mixed $events): array
    {
        $out = [];
        if (! is_array($events)) {
            return $out;
        }
        foreach ($events as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (! (bool) ($row['has_evidence'] ?? false)) {
                $name = $this->str($row['event'] ?? '');
                if ($name !== '') {
                    $out[] = $name;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * A critical curator proposal that was auto-applied without review is the
     * exact breach rule 11 forbids.
     *
     * @param  mixed  $proposals  list<array{id:string,critical:bool,auto_applied:bool,reviewed:bool}>
     *
     * @return list<string>
     */
    private function unsafeCuratorProposals(mixed $proposals): array
    {
        $out = [];
        if (! is_array($proposals)) {
            return $out;
        }
        foreach ($proposals as $row) {
            if (! is_array($row)) {
                continue;
            }
            $critical = (bool) ($row['critical'] ?? false);
            $autoApplied = (bool) ($row['auto_applied'] ?? false);
            $reviewed = (bool) ($row['reviewed'] ?? false);
            if ($critical && $autoApplied && ! $reviewed) {
                $id = $this->str($row['id'] ?? '');
                $out[] = $id !== '' ? $id : 'proposal';
            }
        }

        return array_values($out);
    }

    /**
     * @return array{rule:int,name:string,passed:bool,reason:?string}
     */
    private function rule(int $n, string $name, bool $passed, ?string $reason): array
    {
        return ['rule' => $n, 'name' => $name, 'passed' => $passed, 'reason' => $passed ? null : $reason];
    }

    /**
     * @param  mixed  $values
     *
     * @return list<string>
     */
    private function stageList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }
        $out = [];
        foreach ($values as $v) {
            $s = $this->lower($v);
            if ($s !== '') {
                $out[] = $s;
            }
        }

        return $out;
    }

    private function lower(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        return strtolower(trim($value));
    }

    private function str(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
