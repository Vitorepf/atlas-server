<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas Agentic Engineering OS Contracts decider.
 *
 * Pure, deterministic runtime for the operational contracts of the Atlas
 * Agentic Engineering OS. It turns four documented contracts into executable
 * checks, all with NO I/O (no DB, no provider, no command execution):
 *
 *   1. "Gates Universais" — the ordered 15-gate spine every delivery must
 *      walk. Gates 11 (security) and 12 (release/rollback) are conditional
 *      ("when relevant"); the rest are mandatory. The doc's "Regras para IA"
 *      states: "Nao declare delivery sem verification, evidence e
 *      certification." This service enforces exactly that — it will NEVER
 *      report a deliverable-ready state while gate 9 (test or blocker),
 *      gate 13 (evidence pack) or gate 15 (certification) are unmet, and it
 *      blocks any gate claimed out of order.
 *
 *   2. "Autonomy Ladder" — the L0..L7 ladder mapping each level to its
 *      autonomy summary and the human role at that level (L0 human does all
 *      .. L7 human governs sovereignty).
 *
 *   3. "Evidencias" — the minimum enterprise evidence pack (10 items). A pack
 *      missing any item is not enterprise-complete.
 *
 *   4. "Completion Criteria" — the OS is "complete" for a request only when
 *      the ambiguous intent has become goal, spec, plan, execution, tests,
 *      review, security, release, docs, cartography, learning and
 *      certification WITHOUT depending on chat memory.
 *
 * The service consumes already-collected facts about a delivery and emits a
 * verdict; it never performs the work itself. Callers decide what to do with
 * the blockers.
 *
 * @see docs/engineering-knowledge-base/atlas-agentic-engineering-os-contracts.md
 */
final class AtlasAgenticEngineeringOsContractsService
{
    /** Stable receipt schema id for the verdicts this service emits. */
    public const SCHEMA = 'atlas.aaeos.contracts.v1';

    /**
     * The 15 universal gates, in canonical order. `key` is the stable slug a
     * caller asserts; `conditional` marks gates that only apply "when
     * relevant" (security, release/rollback) per the doc.
     *
     * @var array<int,array{n:int,key:string,label:string,conditional:bool}>
     */
    private const UNIVERSAL_GATES = [
        ['n' => 1,  'key' => 'intent_classified',        'label' => 'intent classified',                       'conditional' => false],
        ['n' => 2,  'key' => 'owner_docs_loaded',        'label' => 'owner docs loaded',                        'conditional' => false],
        ['n' => 3,  'key' => 'duplicate_check',          'label' => 'duplicate check',                          'conditional' => false],
        ['n' => 4,  'key' => 'placement_decided',        'label' => 'placement decided',                        'conditional' => false],
        ['n' => 5,  'key' => 'context_fresh',            'label' => 'context/code intelligence fresh enough',   'conditional' => false],
        ['n' => 6,  'key' => 'spec_proportional',        'label' => 'spec/design proportional to risk',         'conditional' => false],
        ['n' => 7,  'key' => 'task_contract',            'label' => 'task contract with allowed scope',         'conditional' => false],
        ['n' => 8,  'key' => 'execution_receipts',       'label' => 'execution receipts',                       'conditional' => false],
        ['n' => 9,  'key' => 'test_or_blocker',          'label' => 'test or blocker',                          'conditional' => false],
        ['n' => 10, 'key' => 'review_or_risk_accept',    'label' => 'review or explicit risk acceptance',       'conditional' => false],
        ['n' => 11, 'key' => 'security_check',           'label' => 'security check when relevant',             'conditional' => true],
        ['n' => 12, 'key' => 'release_plan',             'label' => 'release/rollback plan when relevant',      'conditional' => true],
        ['n' => 13, 'key' => 'evidence_pack',            'label' => 'evidence pack',                            'conditional' => false],
        ['n' => 14, 'key' => 'docs_cartography_impact',  'label' => 'docs/cartography impact decision',         'conditional' => false],
        ['n' => 15, 'key' => 'certification',            'label' => 'certification',                            'conditional' => false],
    ];

    /**
     * Gates the doc's "Regras para IA" forbids skipping when claiming delivery:
     * "Nao declare delivery sem verification, evidence e certification."
     *
     * @var array<int,string>
     */
    private const DELIVERY_BLOCKING_GATES = [
        'test_or_blocker',   // verification
        'evidence_pack',     // evidence
        'certification',     // certification
    ];

    /**
     * The 8-level Autonomy Ladder (level => autonomy summary + human role).
     *
     * @var array<int,array{level:string,name:string,autonomy:string,human:string}>
     */
    private const AUTONOMY_LADDER = [
        ['level' => 'L0', 'name' => 'Assist',        'autonomy' => 'Sugere e explica',                'human' => 'Faz tudo'],
        ['level' => 'L1', 'name' => 'Patch',         'autonomy' => 'Altera pequeno escopo',           'human' => 'Aprova e valida'],
        ['level' => 'L2', 'name' => 'Flow',          'autonomy' => 'Executa fluxo Dev com gates',     'human' => 'Decide risco'],
        ['level' => 'L3', 'name' => 'WorkItem',      'autonomy' => 'Entrega tarefa completa',         'human' => 'Supervisiona'],
        ['level' => 'L4', 'name' => 'Obra',          'autonomy' => 'Conduz trabalho longo no Forge',  'human' => 'Aprova milestones'],
        ['level' => 'L5', 'name' => 'Department',    'autonomy' => 'Opera departamentos de engenharia', 'human' => 'Define prioridade'],
        ['level' => 'L6', 'name' => 'Company',       'autonomy' => 'Opera area tech completa',        'human' => 'Atua como gestor'],
        ['level' => 'L7', 'name' => 'Self-Evolving', 'autonomy' => 'Melhora o proprio sistema com safety', 'human' => 'Governa soberania'],
    ];

    /**
     * Minimum enterprise evidence pack (doc "Evidencias").
     *
     * @var array<int,string>
     */
    private const ENTERPRISE_EVIDENCE = [
        'goal_id',
        'spec_design_task_hashes',
        'provider_decision_receipt',
        'context_code_intelligence_receipt',
        'execution_receipts',
        'test_results_or_blocker',
        'review_security_findings',
        'release_rollback_decision',
        'docs_cartography_decision',
        'certification',
    ];

    /**
     * The full completion chain (doc "Completion Criteria"): an ambiguous
     * request must become each of these, without depending on chat memory.
     *
     * @var array<int,string>
     */
    private const COMPLETION_CHAIN = [
        'goal',
        'spec',
        'plan',
        'execution',
        'tests',
        'review',
        'security',
        'release',
        'docs',
        'cartography',
        'learning',
        'certification',
    ];

    // ---------------------------------------------------------------------
    // 1. Universal gates
    // ---------------------------------------------------------------------

    /**
     * Evaluate a delivery against the 15 universal gates.
     *
     * @param  array<string,bool>  $passed  gate key => true if that gate passed
     * @param  array<int,string>  $relevant  keys of conditional gates that ARE
     *                                        relevant for this delivery (e.g.
     *                                        ['security_check','release_plan'])
     * @return array{
     *   schema:string,
     *   total_gates:int,
     *   required_keys:array<int,string>,
     *   unmet:array<int,string>,
     *   out_of_order:array<int,string>,
     *   delivery_blocking_unmet:array<int,string>,
     *   delivery_ready:bool,
     *   highest_contiguous_gate:int,
     *   status:string
     * }
     */
    public function evaluateUniversalGates(array $passed, array $relevant = []): array
    {
        $relevantSet = array_fill_keys($relevant, true);

        $requiredKeys = [];
        foreach (self::UNIVERSAL_GATES as $gate) {
            if (! $gate['conditional'] || isset($relevantSet[$gate['key']])) {
                $requiredKeys[] = $gate['key'];
            }
        }

        $unmet = [];
        foreach ($requiredKeys as $key) {
            if (($passed[$key] ?? false) !== true) {
                $unmet[] = $key;
            }
        }

        // Out-of-order: a required gate is claimed passed while an earlier
        // required gate is not. The spine must be walked in order.
        $outOfOrder = [];
        $sawUnmet = false;
        $sawUnmetKey = null;
        foreach (self::UNIVERSAL_GATES as $gate) {
            $key = $gate['key'];
            $isRequired = ! $gate['conditional'] || isset($relevantSet[$key]);
            if (! $isRequired) {
                continue;
            }
            $isPassed = ($passed[$key] ?? false) === true;
            if (! $isPassed) {
                $sawUnmet = true;
                $sawUnmetKey ??= $key;
            } elseif ($sawUnmet) {
                $outOfOrder[] = $key.':after:'.$sawUnmetKey;
            }
        }

        // Highest gate number reached with no gaps before it.
        $highestContiguous = 0;
        foreach (self::UNIVERSAL_GATES as $gate) {
            $key = $gate['key'];
            $isRequired = ! $gate['conditional'] || isset($relevantSet[$key]);
            if (! $isRequired) {
                continue;
            }
            if (($passed[$key] ?? false) === true) {
                $highestContiguous = $gate['n'];
            } else {
                break;
            }
        }

        $deliveryBlockingUnmet = array_values(array_intersect(self::DELIVERY_BLOCKING_GATES, $unmet));

        $deliveryReady = $unmet === [] && $outOfOrder === [];

        if ($deliveryReady) {
            $status = 'delivery_ready';
        } elseif ($deliveryBlockingUnmet !== []) {
            $status = 'delivery_forbidden';
        } else {
            $status = 'incomplete';
        }

        return [
            'schema' => self::SCHEMA,
            'total_gates' => count(self::UNIVERSAL_GATES),
            'required_keys' => $requiredKeys,
            'unmet' => $unmet,
            'out_of_order' => $outOfOrder,
            'delivery_blocking_unmet' => $deliveryBlockingUnmet,
            'delivery_ready' => $deliveryReady,
            'highest_contiguous_gate' => $highestContiguous,
            'status' => $status,
        ];
    }

    /**
     * The canonical ordered gate spine (manifest form).
     *
     * @return array<int,array{n:int,key:string,label:string,conditional:bool}>
     */
    public function universalGates(): array
    {
        return self::UNIVERSAL_GATES;
    }

    // ---------------------------------------------------------------------
    // 2. Autonomy ladder
    // ---------------------------------------------------------------------

    /**
     * Resolve one ladder level to its autonomy + human-role contract.
     *
     * @return array{
     *   known:bool,
     *   level:string,
     *   name:string,
     *   autonomy:string,
     *   human:string,
     *   index:int,
     *   is_self_evolving:bool
     * }
     */
    public function autonomyLevel(string $level): array
    {
        $needle = strtoupper(trim($level));
        foreach (self::AUTONOMY_LADDER as $i => $row) {
            if ($row['level'] === $needle) {
                return [
                    'known' => true,
                    'level' => $row['level'],
                    'name' => $row['name'],
                    'autonomy' => $row['autonomy'],
                    'human' => $row['human'],
                    'index' => $i,
                    'is_self_evolving' => $row['level'] === 'L7',
                ];
            }
        }

        return [
            'known' => false,
            'level' => $needle,
            'name' => '',
            'autonomy' => '',
            'human' => '',
            'index' => -1,
            'is_self_evolving' => false,
        ];
    }

    /**
     * The full ladder (manifest form).
     *
     * @return array<int,array{level:string,name:string,autonomy:string,human:string}>
     */
    public function autonomyLadder(): array
    {
        return self::AUTONOMY_LADDER;
    }

    // ---------------------------------------------------------------------
    // 3. Enterprise evidence pack
    // ---------------------------------------------------------------------

    /**
     * Check an enterprise evidence pack against the documented minimum.
     *
     * @param  array<int,string>|array<string,mixed>  $provided  evidence item
     *         keys present (list) or keyed map (presence by truthy value)
     * @return array{
     *   schema:string,
     *   required:array<int,string>,
     *   missing:array<int,string>,
     *   provided_count:int,
     *   complete:bool
     * }
     */
    public function evaluateEnterpriseEvidence(array $provided): array
    {
        $present = [];
        foreach ($provided as $k => $v) {
            if (is_int($k)) {
                $present[(string) $v] = true;
            } elseif ($v !== false && $v !== null && $v !== '' && $v !== []) {
                $present[(string) $k] = true;
            }
        }

        $missing = [];
        foreach (self::ENTERPRISE_EVIDENCE as $item) {
            if (! isset($present[$item])) {
                $missing[] = $item;
            }
        }

        return [
            'schema' => self::SCHEMA,
            'required' => self::ENTERPRISE_EVIDENCE,
            'missing' => $missing,
            'provided_count' => count(array_intersect(self::ENTERPRISE_EVIDENCE, array_keys($present))),
            'complete' => $missing === [],
        ];
    }

    // ---------------------------------------------------------------------
    // 4. Completion criteria
    // ---------------------------------------------------------------------

    /**
     * Decide whether a request can be considered OS-complete.
     *
     * Per the doc, completion ALSO requires that the chain does not depend on
     * chat memory; if `depends_on_chat_memory` is true the request can never
     * be complete, regardless of which artifacts exist.
     *
     * @param  array<int,string>|array<string,mixed>  $artifacts  produced chain
     *         stage keys (list) or keyed map (presence by truthy value)
     * @return array{
     *   schema:string,
     *   required_chain:array<int,string>,
     *   missing:array<int,string>,
     *   depends_on_chat_memory:bool,
     *   complete:bool,
     *   status:string
     * }
     */
    public function evaluateCompletion(array $artifacts, bool $dependsOnChatMemory = false): array
    {
        $present = [];
        foreach ($artifacts as $k => $v) {
            if (is_int($k)) {
                $present[(string) $v] = true;
            } elseif ($v !== false && $v !== null && $v !== '' && $v !== []) {
                $present[(string) $k] = true;
            }
        }

        $missing = [];
        foreach (self::COMPLETION_CHAIN as $stage) {
            if (! isset($present[$stage])) {
                $missing[] = $stage;
            }
        }

        $complete = $missing === [] && ! $dependsOnChatMemory;

        if ($complete) {
            $status = 'complete';
        } elseif ($dependsOnChatMemory) {
            $status = 'depends_on_chat_memory';
        } else {
            $status = 'incomplete';
        }

        return [
            'schema' => self::SCHEMA,
            'required_chain' => self::COMPLETION_CHAIN,
            'missing' => $missing,
            'depends_on_chat_memory' => $dependsOnChatMemory,
            'complete' => $complete,
            'status' => $status,
        ];
    }

    /**
     * Manifest of the whole contract surface (for the CLI default view).
     *
     * @return array<string,mixed>
     */
    public function manifest(): array
    {
        return [
            'schema' => self::SCHEMA,
            'universal_gates' => self::UNIVERSAL_GATES,
            'delivery_blocking_gates' => self::DELIVERY_BLOCKING_GATES,
            'autonomy_ladder' => self::AUTONOMY_LADDER,
            'enterprise_evidence_minimum' => self::ENTERPRISE_EVIDENCE,
            'completion_chain' => self::COMPLETION_CHAIN,
        ];
    }
}
