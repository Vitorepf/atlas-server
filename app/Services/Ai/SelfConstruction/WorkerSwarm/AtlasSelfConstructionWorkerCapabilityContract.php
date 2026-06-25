<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\WorkerSwarm;

/**
 * Atlas-native WORKER capability + boundary contract. Workers are REPLACEABLE EXECUTION MUSCLES — they
 * are NEVER authority for verification, merge, or learning promotion. The contract evaluates a proposed
 * worker profile and emits pass / block FACTS.
 *
 * INPUT (worker profile):
 *   { worker_id, runtime_owner, scope_roots:list<string>, declared_capabilities:list<string>,
 *     declared_actions:list<string>, evidence_emits:list<string> }
 *
 * OUTPUT:
 *   { schema, accepted:bool, blockers:list<string>, profile:array<string,mixed> }
 *
 * INVARIANTS:
 *   - runtime_owner MUST equal 'atlas_native'.
 *   - declared_actions MUST be a subset of SAFE_ACTION_CLASSES; any FORBIDDEN_ACTION_CLASSES entry
 *     ⇒ blocker authority_overreach:<action>.
 *   - scope_roots MUST be non-empty AND none may equal '/' or '*' (broad-scope blocker).
 *   - declared_capabilities MUST be a subset of CAPABILITY_ALLOWLIST.
 *   - evidence_emits MUST cover REQUIRED_EVIDENCE; missing ⇒ missing_evidence:<key>.
 *   - DETERMINISTIC envelope.
 */
final class AtlasSelfConstructionWorkerCapabilityContract
{
    public const SCHEMA = 'atlas.workerswarm.capability_contract.v1';

    public const RUNTIME_OWNER_NATIVE = 'atlas_native';

    public const CAPABILITY_ALLOWLIST = [
        'inspect_task_packet',
        'prepare_patch_plan',
        'apply_scoped_patch',
        'run_gates',
        'write_evidence',
        'request_rollback',
        'learn_from_receipt',
    ];

    public const SAFE_ACTION_CLASSES = [
        'inspect',
        'apply_patch_in_scope',
        'run_gate_in_sandbox',
        'write_evidence_row',
        'request_rollback_for_own_attempt',
    ];

    public const FORBIDDEN_ACTION_CLASSES = [
        'grant_verification_pass',     // only the court does that
        'execute_main_merge',           // only the merge governor schedules
        'promote_learning_to_canonical',// only learning transfer (post-court) does
        'edit_constitution',            // forbidden core
        'force_disable_master_switch',  // operator-only
    ];

    public const REQUIRED_EVIDENCE = ['evidence_hash', 'test_run_id', 'commit_sha_or_diff_hash'];

    /**
     * @param  array{
     *     worker_id?:string,
     *     runtime_owner?:string,
     *     scope_roots?:list<string>,
     *     declared_capabilities?:list<string>,
     *     declared_actions?:list<string>,
     *     evidence_emits?:list<string>
     * }  $profile
     * @return array{schema:string, accepted:bool, blockers:list<string>, profile:array<string,mixed>}
     */
    public function evaluate(array $profile): array
    {
        $blockers = [];
        $workerId = (string) ($profile['worker_id'] ?? '');
        if ($workerId === '') {
            $blockers[] = 'missing_worker_id';
        }
        $runtimeOwner = (string) ($profile['runtime_owner'] ?? '');
        if ($runtimeOwner !== self::RUNTIME_OWNER_NATIVE) {
            $blockers[] = 'runtime_owner_not_atlas_native:'.($runtimeOwner === '' ? 'missing' : $runtimeOwner);
        }

        $scopeRoots = is_array($profile['scope_roots'] ?? null) ? array_values(array_map('strval', $profile['scope_roots'])) : [];
        if ($scopeRoots === []) {
            $blockers[] = 'empty_scope_roots';
        } else {
            foreach ($scopeRoots as $root) {
                if ($root === '/' || $root === '*' || $root === '.') {
                    $blockers[] = 'broad_scope_root:'.$root;
                }
            }
        }

        $caps = is_array($profile['declared_capabilities'] ?? null) ? array_values(array_map('strval', $profile['declared_capabilities'])) : [];
        foreach ($caps as $c) {
            if (! in_array($c, self::CAPABILITY_ALLOWLIST, true)) {
                $blockers[] = 'capability_not_in_allowlist:'.$c;
            }
        }

        $actions = is_array($profile['declared_actions'] ?? null) ? array_values(array_map('strval', $profile['declared_actions'])) : [];
        foreach ($actions as $a) {
            if (in_array($a, self::FORBIDDEN_ACTION_CLASSES, true)) {
                $blockers[] = 'authority_overreach:'.$a;

                continue;
            }
            if (! in_array($a, self::SAFE_ACTION_CLASSES, true)) {
                $blockers[] = 'action_not_in_safe_allowlist:'.$a;
            }
        }

        $evidence = is_array($profile['evidence_emits'] ?? null) ? array_values(array_map('strval', $profile['evidence_emits'])) : [];
        foreach (self::REQUIRED_EVIDENCE as $req) {
            if (! in_array($req, $evidence, true)) {
                $blockers[] = 'missing_evidence:'.$req;
            }
        }

        sort($blockers, SORT_STRING);

        return [
            'schema' => self::SCHEMA,
            'accepted' => $blockers === [],
            'blockers' => $blockers,
            'profile' => [
                'worker_id' => $workerId,
                'runtime_owner' => $runtimeOwner,
                'scope_roots' => $scopeRoots,
                'declared_capabilities' => $caps,
                'declared_actions' => $actions,
                'evidence_emits' => $evidence,
            ],
        ];
    }
}
