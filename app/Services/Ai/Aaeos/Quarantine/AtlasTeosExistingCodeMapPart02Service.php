<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Runtime for the "Atlas TEOS Existing Code Map · Parte 2" doc.
 *
 * The doc is an anti-duplication code map: before any TEOS-I1 slice creates a
 * new class / table / command, it must check the existing inventory and emit a
 * verdict (reuse / extend / greenfield) — or be BLOCKED. The load-bearing
 * contract this service enforces:
 *
 *   - The 10 numbered "Anti-Duplication Rules": certain components are FORBIDDEN
 *     to recreate; each maps to the existing component plus the prescribed
 *     extend point (e.g. a parallel `atlas_ledger_events` table is blocked;
 *     `LongHorizonCompactionEngine` is blocked -> extend
 *     `AiCompactionService::compactForScope`).
 *   - Naming proliferation is forbidden: "TemporalState", "ContinuityEngine",
 *     "WorkstreamMachine" are banned tokens; any new `*temporal*` migration
 *     outside the declared `ai_long_horizon_*` family is blocked.
 *   - The four inherited Quality Gates are hard:
 *       * `must_keep_coverage == 1.0` is required on every emitted
 *         `compaction_receipt.v1`; coverage < 1.0 is REJECTED (S5 throws).
 *       * the readiness-harness claim policy must stay "not run" on every
 *         console / control plane envelope; declaring a readiness-harness run is
 *         blocked by contract (this doc is a reuse map, not an execution).
 *       * `completed` requires a certification.
 *       * promotion of memory scope `obra|long_horizon` requires operator
 *         review (refusal otherwise).
 *   - Risk mitigation as a cap: the Recovery Planner has
 *     `max_recovery_attempts = 3`, then it escalates to the operator (Risk §9).
 *   - The 14-slice order table is a fixed inventory: exactly 7 greenfield, 6
 *     extend, 1 doc-only.
 *
 * This service is PURE and deterministic: no DB, no I/O. It decides verdicts and
 * gate outcomes from declared inputs. It never authorizes runtime, never writes
 * to any ledger, never triggers the readiness harness.
 *
 * @see docs/engineering-knowledge-base/atlas-teos-existing-code-map-part-02.md
 */
final class AtlasTeosExistingCodeMapPart02Service
{
    public const SCHEMA_VERSION = 'atlas.aaeos.teos_existing_code_map_part_02.v1';

    public const MODE = 'read_only_anti_duplication_decision';

    /** Verdicts a proposed component can receive. */
    public const VERDICT_REUSE = 'reuse';

    public const VERDICT_EXTEND = 'extend';

    public const VERDICT_GREENFIELD = 'greenfield';

    public const VERDICT_BLOCKED = 'blocked';

    /** Doc §"Risk Map" #9: Recovery Planner cap before operator escalation. */
    public const MAX_RECOVERY_ATTEMPTS = 3;

    /** Doc greenfield invariant: every emitted compaction_receipt must keep all. */
    public const REQUIRED_MUST_KEEP_COVERAGE = 1.0;

    /**
     * The 10 Anti-Duplication Rules. Each forbidden target carries the canonical
     * existing component it must defer to and the prescribed extend point. A
     * proposal that matches a forbidden target is BLOCKED; the verdict the
     * proposer should re-submit under is `reuse` or `extend` per `defer_verdict`.
     *
     * @var array<int, array{
     *   rule:int, banned:array<int,string>, existing:string,
     *   defer_verdict:string, extend_point:string
     * }>
     */
    private const ANTI_DUP_RULES = [
        [
            'rule' => 1,
            'banned' => ['parallel_ledger_table', 'ai_long_horizon_audit_events', 'long_horizon_ledger'],
            'existing' => 'atlas_ledger_events',
            'defer_verdict' => self::VERDICT_REUSE,
            'extend_point' => "write event_type=long_horizon.* via AtlasLedgerEvent (append-only inherited from save())",
        ],
        [
            'rule' => 2,
            'banned' => ['LongHorizonCompactionEngine', 'ai_long_horizon_compactions'],
            'existing' => 'AiCompactionService',
            'defer_verdict' => self::VERDICT_EXTEND,
            'extend_point' => 'AiCompactionService::compactForScope() + additive migration on ai_compactions (same lock)',
        ],
        [
            'rule' => 3,
            'banned' => ['LongHorizonForgeState'],
            'existing' => 'ForgeLongHorizonStateService',
            'defer_verdict' => self::VERDICT_EXTEND,
            'extend_point' => 'ForgeLongHorizonStateService::emitContinuationPack() delegating to the new builder',
        ],
        [
            'rule' => 4,
            'banned' => ['AtlasLongHorizonMemoryEntry', 'parallel_memory_store'],
            'existing' => 'AtlasMemoryEntry',
            'defer_verdict' => self::VERDICT_EXTEND,
            'extend_point' => 'AtlasMemoryEntry::SCOPES += obra|long_horizon; promotion gate operator_review_required',
        ],
        [
            'rule' => 5,
            'banned' => ['LongHorizonReadinessRunner', 'parallel_readiness_harness'],
            'existing' => 'ReadinessHarness',
            'defer_verdict' => self::VERDICT_REUSE,
            'extend_point' => 'the existing readiness harness is the only surface; TEOS never recreates the runner',
        ],
        [
            'rule' => 6,
            'banned' => ['parallel_programming_console_command'],
            'existing' => 'ProgrammingConsoleCanon',
            'defer_verdict' => self::VERDICT_EXTEND,
            'extend_point' => 'add long-horizon:* actions on the existing Canon',
        ],
        [
            'rule' => 7,
            'banned' => ['ProgrammingResumeServiceV2'],
            'existing' => 'ProgrammingResumeService',
            'defer_verdict' => self::VERDICT_EXTEND,
            'extend_point' => 'extend state() + continuationPacket() with new keys; keep backwards-compat',
        ],
        [
            'rule' => 8,
            'banned' => ['parallel_evidence_ledger_service'],
            'existing' => 'AtlasEvidenceLedger',
            'defer_verdict' => self::VERDICT_REUSE,
            'extend_point' => 'use record() with new LedgerEventType values',
        ],
        [
            'rule' => 9,
            'banned' => ['ai_long_horizon_certifications'],
            'existing' => 'ai_mission_certifications',
            'defer_verdict' => self::VERDICT_REUSE,
            'extend_point' => 'use ai_mission_certifications with kind=long_horizon_continuity',
        ],
        [
            'rule' => 10,
            'banned' => ['TemporalState', 'ContinuityEngine', 'WorkstreamMachine'],
            'existing' => 'long_horizon.* namespace',
            'defer_verdict' => self::VERDICT_BLOCKED,
            'extend_point' => 'use long_horizon.* or nothing; no naming proliferation',
        ],
    ];

    /**
     * Tokens that, if they appear in any proposed class / table / event name,
     * are naming proliferation and are blocked outright (rule 10 + anti-pattern
     * list). Matched case-insensitively as substrings.
     *
     * @var array<int, string>
     */
    private const BANNED_NAME_TOKENS = [
        'TemporalState',
        'ContinuityEngine',
        'WorkstreamMachine',
        'WorkstreamPack',
        'TemporalCertification',
    ];

    /**
     * The S1..S14 recommended implementation order, with the doc's classification
     * for each slice. This is a fixed inventory the proposer cannot silently
     * reclassify.
     *
     * @var array<int, array{slice:string,classification:string}>
     */
    private const SLICES = [
        ['slice' => 'S1', 'classification' => 'doc-only'],
        ['slice' => 'S2', 'classification' => self::VERDICT_GREENFIELD],
        ['slice' => 'S3', 'classification' => self::VERDICT_GREENFIELD],
        ['slice' => 'S4', 'classification' => self::VERDICT_GREENFIELD],
        ['slice' => 'S5', 'classification' => self::VERDICT_EXTEND],
        ['slice' => 'S6', 'classification' => self::VERDICT_EXTEND],
        ['slice' => 'S7', 'classification' => self::VERDICT_EXTEND],
        ['slice' => 'S8', 'classification' => self::VERDICT_EXTEND],
        ['slice' => 'S9', 'classification' => self::VERDICT_GREENFIELD],
        ['slice' => 'S10', 'classification' => self::VERDICT_EXTEND],
        ['slice' => 'S11', 'classification' => self::VERDICT_GREENFIELD],
        ['slice' => 'S12', 'classification' => self::VERDICT_GREENFIELD],
        ['slice' => 'S13', 'classification' => self::VERDICT_GREENFIELD],
        ['slice' => 'S14', 'classification' => self::VERDICT_EXTEND],
    ];

    /**
     * Decide the verdict for a proposed component. The proposer declares an
     * intended name and an intended verdict; the service either confirms it or
     * BLOCKS it against the anti-duplication rules and naming bans.
     *
     * @param  array{name?:string,intended_verdict?:string,is_new_class?:bool,
     *   is_new_table?:bool,is_new_migration?:bool}  $proposal
     * @return array{
     *   schema_version:string, mode:string, name:string,
     *   intended_verdict:string, verdict:string, blocked:bool,
     *   rule:int|null, existing:string|null, defer_verdict:string|null,
     *   extend_point:string|null, reasons:array<int,string>,
     *   runtime_authorized:bool
     * }
     */
    public function decideComponent(array $proposal): array
    {
        $name = trim((string) ($proposal['name'] ?? ''));
        $intended = (string) ($proposal['intended_verdict'] ?? self::VERDICT_GREENFIELD);
        $reasons = [];

        $base = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'name' => $name,
            'intended_verdict' => $intended,
            'runtime_authorized' => false,
        ];

        // (a) Naming proliferation: a banned token anywhere in the name is a hard
        //     block regardless of intended verdict (rule 10 + anti-pattern list).
        $bannedToken = $this->matchBannedToken($name);
        if ($bannedToken !== null) {
            $reasons[] = "naming proliferation: token '{$bannedToken}' is banned; use long_horizon.* or nothing.";

            return $base + [
                'verdict' => self::VERDICT_BLOCKED,
                'blocked' => true,
                'rule' => 10,
                'existing' => 'long_horizon.* namespace',
                'defer_verdict' => self::VERDICT_BLOCKED,
                'extend_point' => 'rename under long_horizon.* or do not create the component',
                'reasons' => $reasons,
            ];
        }

        // (b) New `*temporal*` migration outside the ai_long_horizon_* family is
        //     an explicitly blocked anti-pattern.
        $isMigration = (bool) ($proposal['is_new_migration'] ?? false) || (bool) ($proposal['is_new_table'] ?? false);
        if ($isMigration && $this->isForbiddenTemporalMigration($name)) {
            $reasons[] = "forbidden migration: '{$name}' is a temporal_* table outside the declared ai_long_horizon_* family.";

            return $base + [
                'verdict' => self::VERDICT_BLOCKED,
                'blocked' => true,
                'rule' => 10,
                'existing' => 'ai_long_horizon_* migration family',
                'defer_verdict' => self::VERDICT_BLOCKED,
                'extend_point' => 'create the table only inside the declared ai_long_horizon_* family',
                'reasons' => $reasons,
            ];
        }

        // (c) Anti-duplication rules: a proposal matching a banned target defers
        //     to the existing component. It is blocked unless the proposer is
        //     already asking for the prescribed reuse/extend verdict.
        $rule = $this->matchAntiDupRule($name);
        if ($rule !== null) {
            $defer = $rule['defer_verdict'];
            $aligned = $intended === $defer && $defer !== self::VERDICT_BLOCKED;

            if ($aligned) {
                $reasons[] = "rule {$rule['rule']}: proposal correctly defers to {$rule['existing']} via {$defer}.";

                return $base + [
                    'verdict' => $defer,
                    'blocked' => false,
                    'rule' => $rule['rule'],
                    'existing' => $rule['existing'],
                    'defer_verdict' => $defer,
                    'extend_point' => $rule['extend_point'],
                    'reasons' => $reasons,
                ];
            }

            $reasons[] = "rule {$rule['rule']}: creating '{$name}' is forbidden; {$rule['existing']} already serves. Re-submit as {$defer}: {$rule['extend_point']}.";

            return $base + [
                'verdict' => self::VERDICT_BLOCKED,
                'blocked' => true,
                'rule' => $rule['rule'],
                'existing' => $rule['existing'],
                'defer_verdict' => $defer,
                'extend_point' => $rule['extend_point'],
                'reasons' => $reasons,
            ];
        }

        // (d) Not a banned target and not a banned name: the proposer's intended
        //     verdict stands (genuine greenfield/extend per the slice table).
        $reasons[] = "no anti-duplication rule matches '{$name}'; intended verdict stands.";

        return $base + [
            'verdict' => $intended,
            'blocked' => false,
            'rule' => null,
            'existing' => null,
            'defer_verdict' => null,
            'extend_point' => null,
            'reasons' => $reasons,
        ];
    }

    /**
     * Evaluate the four inherited Quality Gates for an emitted artifact /
     * envelope. Any failing gate blocks the artifact.
     *
     * @param  array{
     *   must_keep_coverage?:float|int, readiness_eval_not_run?:bool,
     *   readiness_eval_status?:string, status?:string, certified?:bool,
     *   promotion_scope?:string, operator_reviewed?:bool
     * }  $artifact
     * @return array{
     *   schema_version:string, mode:string, gates:array<int,array<string,mixed>>,
     *   passed:bool, failed_gates:array<int,string>, reasons:array<int,string>
     * }
     */
    public function evaluateQualityGates(array $artifact): array
    {
        $gates = [];
        $failed = [];
        $reasons = [];

        // Gate 1: must_keep_coverage == 1.0 on every compaction_receipt.
        $coverage = (float) ($artifact['must_keep_coverage'] ?? 0.0);
        $coverageOk = $coverage === self::REQUIRED_MUST_KEEP_COVERAGE;
        if (! $coverageOk) {
            $failed[] = 'must_keep_coverage';
            $reasons[] = "must_keep_coverage={$coverage} != 1.0; compaction_receipt rejected (greenfield invariant, S5 throws).";
        }
        $gates[] = ['gate' => 'must_keep_coverage', 'required' => '== 1.0', 'value' => $coverage, 'passed' => $coverageOk];

        // Gate 2: readiness-harness claim policy must stay "not run"; declaring a
        // run is blocked by the harness contract (this doc is a reuse map, not an
        // execution).
        $declaredRunning = strtolower((string) ($artifact['readiness_eval_status'] ?? '')) === 'running';
        $evalNotRun = (bool) ($artifact['readiness_eval_not_run'] ?? true) && ! $declaredRunning;
        if (! $evalNotRun) {
            $failed[] = 'readiness_eval_not_run';
            $reasons[] = 'claim_policy must keep the readiness eval "not run"; declaring a readiness-harness run is forbidden by harness contract.';
        }
        $gates[] = ['gate' => 'readiness_eval_not_run', 'required' => 'true', 'value' => $evalNotRun, 'passed' => $evalNotRun];

        // Gate 3: completed requires certification.
        $isCompleted = strtolower((string) ($artifact['status'] ?? '')) === 'completed';
        $certified = (bool) ($artifact['certified'] ?? false);
        $certOk = ! $isCompleted || $certified;
        if (! $certOk) {
            $failed[] = 'completed_requires_certification';
            $reasons[] = "status=completed without certification; MissionLifecycleService requires certification.";
        }
        $gates[] = ['gate' => 'completed_requires_certification', 'required' => 'certified when completed', 'value' => $certOk, 'passed' => $certOk];

        // Gate 4: promotion of scope obra|long_horizon requires operator review.
        $scope = strtolower((string) ($artifact['promotion_scope'] ?? ''));
        $needsReview = in_array($scope, ['obra', 'long_horizon'], true);
        $reviewed = (bool) ($artifact['operator_reviewed'] ?? false);
        $reviewOk = ! $needsReview || $reviewed;
        if (! $reviewOk) {
            $failed[] = 'operator_review_required';
            $reasons[] = "promotion scope '{$scope}' requires operator review (greenfield invariant); refused.";
        }
        $gates[] = ['gate' => 'operator_review_required', 'required' => 'operator review for obra|long_horizon', 'value' => $reviewOk, 'passed' => $reviewOk];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'gates' => $gates,
            'passed' => $failed === [],
            'failed_gates' => $failed,
            'reasons' => $reasons,
        ];
    }

    /**
     * Recovery Planner cap (Risk #9): a recovery loop is allowed at most
     * MAX_RECOVERY_ATTEMPTS times; on the next attempt it must escalate to the
     * operator instead of looping forever.
     *
     * @return array{
     *   schema_version:string, attempt:int, max_attempts:int,
     *   may_retry:bool, escalate_to_operator:bool, action:string
     * }
     */
    public function planRecovery(int $attempt): array
    {
        $attempt = max(0, $attempt);
        $mayRetry = $attempt < self::MAX_RECOVERY_ATTEMPTS;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'attempt' => $attempt,
            'max_attempts' => self::MAX_RECOVERY_ATTEMPTS,
            'may_retry' => $mayRetry,
            'escalate_to_operator' => ! $mayRetry,
            'action' => $mayRetry ? 'retry_with_next_best_action' : 'escalate_to_operator',
        ];
    }

    /**
     * The fixed slice inventory with classification counts (7 greenfield, 6
     * extend, 1 doc-only). Used to detect silent reclassification.
     *
     * @return array{
     *   schema_version:string, slices:array<int,array{slice:string,classification:string}>,
     *   total:int, greenfield:int, extend:int, doc_only:int
     * }
     */
    public function sliceInventory(): array
    {
        $greenfield = 0;
        $extend = 0;
        $docOnly = 0;

        foreach (self::SLICES as $s) {
            match ($s['classification']) {
                self::VERDICT_GREENFIELD => $greenfield++,
                self::VERDICT_EXTEND => $extend++,
                default => $docOnly++,
            };
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'slices' => self::SLICES,
            'total' => count(self::SLICES),
            'greenfield' => $greenfield,
            'extend' => $extend,
            'doc_only' => $docOnly,
        ];
    }

    /** @return array<int, array{rule:int,banned:array<int,string>,existing:string,defer_verdict:string,extend_point:string}> */
    public function antiDuplicationRules(): array
    {
        return self::ANTI_DUP_RULES;
    }

    /**
     * Self-describing snapshot for the CLI: the rule catalogue, the slice
     * inventory, a worked blocked decision and a worked all-green gate pass.
     *
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'rule_count' => count(self::ANTI_DUP_RULES),
            'banned_name_tokens' => self::BANNED_NAME_TOKENS,
            'max_recovery_attempts' => self::MAX_RECOVERY_ATTEMPTS,
            'slice_inventory' => $this->sliceInventory(),
            'sample_blocked' => $this->decideComponent([
                'name' => 'LongHorizonCompactionEngine',
                'intended_verdict' => self::VERDICT_GREENFIELD,
                'is_new_class' => true,
            ]),
            'sample_quality_gates_green' => $this->evaluateQualityGates([
                'must_keep_coverage' => 1.0,
                'readiness_eval_not_run' => true,
                'status' => 'completed',
                'certified' => true,
                'promotion_scope' => 'long_horizon',
                'operator_reviewed' => true,
            ]),
            'sample_recovery_escalation' => $this->planRecovery(self::MAX_RECOVERY_ATTEMPTS),
            'runtime_authorized' => false,
        ];
    }

    private function matchBannedToken(string $name): ?string
    {
        $haystack = strtolower($name);

        foreach (self::BANNED_NAME_TOKENS as $token) {
            if ($haystack !== '' && str_contains($haystack, strtolower($token))) {
                return $token;
            }
        }

        return null;
    }

    private function isForbiddenTemporalMigration(string $name): bool
    {
        $lower = strtolower($name);

        if (! str_contains($lower, 'temporal')) {
            return false;
        }

        // Inside the declared long-horizon family it would be allowed; a
        // temporal_* table is only forbidden when it sits OUTSIDE that family.
        return ! str_contains($lower, 'ai_long_horizon');
    }

    /**
     * @return array{rule:int,banned:array<int,string>,existing:string,defer_verdict:string,extend_point:string}|null
     */
    private function matchAntiDupRule(string $name): ?array
    {
        if ($name === '') {
            return null;
        }

        $lower = strtolower($name);

        foreach (self::ANTI_DUP_RULES as $rule) {
            foreach ($rule['banned'] as $banned) {
                if (str_contains($lower, strtolower($banned))) {
                    return $rule;
                }
            }
        }

        return null;
    }
}
