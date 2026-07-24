<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Control;

/**
 * P4 REAL_OPERATION gauntlet coordinator (path + env honesty).
 *
 * Attempts mode journeys via real CLI entry points when env allows; never
 * fabricates real_operation_completed without producer proof. Missing
 * ATLAS_P4_PG_* is residual-open honesty, not fake GREEN journey.
 */
final class AaeosP4RealOperationGauntlet
{
    public const SCHEMA = 'atlas.aaeos.p4.real_operation_gauntlet.v1';

    public const STATUS_REAL_OPERATION_COMPLETED = 'real_operation_completed';

    public const STATUS_BLOCKED_OPS_PARTIAL = 'blocked_ops_partial';

    public const STATUS_PREFLIGHT_REFUSED = 'preflight_refused';

    /**
     * @param  array<string,mixed>  $env
     * @return array<string,mixed>
     */
    public static function preflight(array $env = []): array
    {
        $producer = trim((string) ($env['ATLAS_P4_PG_PRODUCER_URL'] ?? getenv('ATLAS_P4_PG_PRODUCER_URL') ?: ''));
        $verifier = trim((string) ($env['ATLAS_P4_PG_VERIFIER_URL'] ?? getenv('ATLAS_P4_PG_VERIFIER_URL') ?: ''));
        $blockers = [];
        if ($producer === '') {
            $blockers[] = 'atlas_p4_pg_producer_url_missing';
        }
        if ($verifier === '') {
            $blockers[] = 'atlas_p4_pg_verifier_url_missing';
        }
        if ($producer !== '' && $verifier !== '' && $producer === $verifier) {
            $blockers[] = 'producer_verifier_must_be_distinct';
        }
        if ($producer !== '' && ! str_contains($producer, 'atlas_p4_')) {
            $blockers[] = 'producer_url_missing_atlas_p4_prefix_hint';
        }

        return [
            'schema' => self::SCHEMA.'.preflight',
            'durable_pg_ready' => $blockers === [],
            'producer_configured' => $producer !== '',
            'verifier_configured' => $verifier !== '',
            'blockers' => $blockers,
            'phpunit_alone_never_qualifies' => true,
            'secrets_in_receipt_forbidden' => true,
        ];
    }

    /**
     * Build a mode journey receipt from a real CLI invocation result.
     *
     * @param  array<string,mixed>  $cliResult  {exit_code, stdout, stderr, command}
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public static function journeyReceipt(string $mode, array $cliResult, array $context = []): array
    {
        $mode = strtolower(trim($mode));
        $preflight = self::preflight($context['env'] ?? []);
        $exit = (int) ($cliResult['exit_code'] ?? -1);
        $stdout = (string) ($cliResult['stdout'] ?? '');
        $command = (string) ($cliResult['command'] ?? '');
        $planOnly = (bool) ($context['plan_only'] ?? true);
        $aaeosInitiated = (bool) ($context['aaeos_initiated'] ?? false);

        $blockers = $preflight['blockers'];
        if ($mode === 'autonomos' && $aaeosInitiated) {
            $blockers[] = 'autonomos_must_be_direct_daemon_first';
        }
        if ((int) ($context['operator_task_causal_count'] ?? 0) > 0) {
            $blockers[] = 'operator_task_causal_forbidden';
        }

        // R84: capability proofs must be derived receipts/hashes — never free caller bools.
        if (array_key_exists('provider_spawn_attested', $context)
            || array_key_exists('authority_lineage_present', $context)) {
            $blockers[] = 'caller_set_capability_flags_forbidden';
        }

        $producerTerminal = self::deriveProducerTerminalFromStdout($stdout);
        $effectHonesty = self::effectHonestyFromPayload($producerTerminal['payload'], $mode);
        // R84: dry-run / non-executed missions never complete eng for REAL_OPERATION.
        if ($effectHonesty['blockers'] !== [] && $producerTerminal['completed']) {
            $producerTerminal['completed'] = false;
            if ($producerTerminal['status'] === 'passed') {
                $producerTerminal['status'] = 'blocked';
            }
            $producerTerminal['error_codes'] = array_values(array_unique(array_merge(
                $producerTerminal['error_codes'],
                $effectHonesty['blockers'],
            )));
        }

        $structuredResidual = self::structuredResidualFromProducerPayload(
            $producerTerminal['payload'],
            $producerTerminal['status'],
            $producerTerminal['error_codes'],
        );

        // Prefer context proofs; otherwise try derive from stdout payload (never invent).
        $providerProof = self::deriveProviderSpawnProof(
            $context['provider_spawn_proof'] ?? self::tryDeriveProviderSpawnFromPayload($producerTerminal['payload']),
        );
        $authorityProof = self::deriveAuthorityLineageProof(
            $context['authority_lineage_proof'] ?? self::tryDeriveAuthorityFromPayload($producerTerminal['payload']),
        );
        $blockers = array_merge($blockers, $providerProof['blockers'], $authorityProof['blockers']);
        $blockers = array_merge($blockers, $structuredResidual['blockers'], $effectHonesty['blockers']);

        $hardBlockers = array_values(array_unique($blockers));
        $terminal = self::STATUS_BLOCKED_OPS_PARTIAL;

        if ($planOnly) {
            $hardBlockers[] = 'plan_only_not_real_operation';
            if (! $preflight['durable_pg_ready']) {
                $hardBlockers[] = 'durable_pg_roles_required_for_real_operation';
            }
        } elseif ($exit !== 0) {
            $hardBlockers[] = 'producer_exit_nonzero';
        } elseif (! $producerTerminal['completed']) {
            // Exit 0 with status=blocked must never qualify REAL_OPERATION.
            $hardBlockers[] = 'producer_status_not_completed';
            if ($producerTerminal['status'] !== '') {
                $hardBlockers[] = 'producer_status:'.$producerTerminal['status'];
            }
        } elseif (! $preflight['durable_pg_ready']) {
            $hardBlockers[] = 'durable_pg_roles_required_for_real_operation';
        } elseif (! $providerProof['ok'] || ! $authorityProof['ok']) {
            $hardBlockers[] = 'derived_capability_proofs_incomplete';
        } elseif ($hardBlockers === [] && $exit === 0 && $command !== '' && $producerTerminal['completed']) {
            $terminal = self::STATUS_REAL_OPERATION_COMPLETED;
        } else {
            $hardBlockers[] = 'real_operation_predicates_incomplete';
        }

        $blockers = array_values(array_unique($hardBlockers));
        $qualified = $terminal === self::STATUS_REAL_OPERATION_COMPLETED;

        // Residual honesty must not contradict journey qualification (skeptic: hardcoded false).
        $residual = $structuredResidual['residual'];
        $residual['covered_provider_spawn_proven'] = $providerProof['ok'] && is_array($providerProof['proof'])
            && ($providerProof['proof']['spawned'] ?? false) === true;
        $residual['real_operation_completed'] = $qualified;
        $residual['honesty'] = $qualified
            ? 'real_operation_completed_derived'
            : 'residual_honest_partial_not_fabricated';
        $residual['effect_honesty'] = $effectHonesty['summary'];

        return [
            'schema' => self::SCHEMA.'.journey',
            'mode' => $mode,
            'journey_terminal_status' => $terminal,
            'real_operation_qualified' => $qualified,
            'exit_code' => $exit,
            'exit_zero_alone_never_qualifies' => true,
            'blocked_status_exit_zero_never_qualifies' => true,
            'capability_proof_derived_not_caller_set' => true,
            'command' => self::redactSecrets($command),
            'stdout_fingerprint' => hash('sha256', $stdout),
            'plan_only' => $planOnly,
            'aaeos_initiated' => $aaeosInitiated,
            'preflight' => $preflight,
            'blockers' => $blockers,
            'structured_residual' => $residual,
            'producer_terminal' => [
                'status' => $producerTerminal['status'],
                'completed' => $producerTerminal['completed'],
                'error_codes' => $producerTerminal['error_codes'],
            ],
            'provider_spawn_proof' => $providerProof['proof'],
            'authority_lineage_proof' => $authorityProof['proof'],
            'operator_task_causal_count' => (int) ($context['operator_task_causal_count'] ?? 0),
            'r104_transport_open' => (bool) ($context['r104_transport_open'] ?? true),
            'code_sha' => (string) ($context['code_sha'] ?? ''),
        ];
    }

    /**
     * Parse producer JSON stdout (senior-loop / forge / task next) into terminal truth.
     * Exit code is NOT used here — blocked journeys often exit 0.
     *
     * @return array{status:string,completed:bool,error_codes:list<string>,payload:array<string,mixed>|null}
     */
    public static function deriveProducerTerminalFromStdout(string $stdout): array
    {
        $trimmed = strtolower(trim($stdout));
        // Bare terminal tokens used by unit fixtures — never treat help text as completed.
        if (in_array($trimmed, ['completed', 'released', 'passed', 'success', 'real_operation_completed'], true)) {
            return ['status' => $trimmed, 'completed' => true, 'error_codes' => [], 'payload' => null];
        }

        $payload = self::extractJsonObject($stdout);
        if ($payload === null) {
            // Non-JSON help/version paths are never eng completion.
            $lower = strtolower($stdout);
            if (str_contains($lower, 'usage:') || str_contains($lower, 'options:') || str_contains($lower, 'plan-only')) {
                return ['status' => 'help_or_plan_surface', 'completed' => false, 'error_codes' => [], 'payload' => null];
            }

            return ['status' => 'unparsed', 'completed' => false, 'error_codes' => [], 'payload' => null];
        }

        // Prefer producer-owned terminal fields. forge_live_execution_status must
        // beat nested aemor_outcome.status ("recorded"/"succeeded") which is spine
        // telemetry, not eng completion. status alone on task envelopes still wins.
        $status = strtolower(trim((string) (
            $payload['status']
            ?? $payload['forge_live_execution_status']
            ?? data_get($payload, 'run_summary.completion_state')
            ?? data_get($payload, 'journey_terminal_status')
            ?? data_get($payload, 'daemon_status')
            ?? data_get($payload, 'aemor_outcome.status')
            ?? data_get($payload, 'aemor_outcome.outcome.status')
            ?? ''
        )));
        // Normalize producer synonyms onto the completed set ONLY when effect honesty
        // allows it. Bare "executed" with no_executable_capabilities must not complete.
        $effect = self::effectHonestyFromPayload($payload, '');
        if (in_array($status, ['succeeded', 'served_and_landed'], true)) {
            $status = 'passed';
        }
        if ($status === 'executed') {
            $status = $effect['blockers'] === [] ? 'passed' : 'blocked';
        }
        $errorCodes = [];
        foreach ([
            data_get($payload, 'run_summary.provider_call.error_codes'),
            data_get($payload, 'remaining_blockers'),
            data_get($payload, 'blockers'),
            // Task-serving / envelope producers surface residual as status+reason.
            $payload['reason'] ?? null,
            $payload['status'] ?? null,
            data_get($payload, 'escalation.reason'),
            data_get($payload, 'aemor_outcome.reason'),
            data_get($payload, 'orchestrator_event'),
            data_get($payload, 'result.event'),
        ] as $bucket) {
            if (is_array($bucket)) {
                foreach ($bucket as $item) {
                    if (is_array($item)) {
                        $code = trim((string) ($item['code'] ?? $item['blocker'] ?? ''));
                        if ($code !== '') {
                            $errorCodes[] = $code;
                        }
                        continue;
                    }
                    $item = trim((string) $item);
                    if ($item !== '') {
                        $errorCodes[] = $item;
                    }
                }
            } else {
                $item = trim((string) $bucket);
                if ($item !== '') {
                    $errorCodes[] = $item;
                }
            }
        }
        $errorCodes = array_values(array_unique(array_merge($errorCodes, $effect['blockers'])));
        $completed = in_array($status, ['passed', 'released', 'completed', 'completed_read_only', 'success', 'real_operation_completed'], true)
            && $effect['blockers'] === [];

        return [
            'status' => $status,
            'completed' => $completed,
            'error_codes' => $errorCodes,
            'payload' => $payload,
        ];
    }

    /**
     * R84 effect honesty: dry-run / non-executed missions / non-real completion
     * never qualify REAL_OPERATION even when status tokens look green.
     *
     * @param  array<string,mixed>|null  $payload
     * @return array{blockers:list<string>,summary:array<string,mixed>}
     */
    public static function effectHonestyFromPayload(?array $payload, string $mode = ''): array
    {
        if ($payload === null) {
            return [
                'blockers' => [],
                'summary' => ['payload_present' => false],
            ];
        }

        $blockers = [];
        $blob = strtolower(json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '');

        $orchestratorEvent = strtolower(trim((string) (
            $payload['orchestrator_event']
            ?? data_get($payload, 'result.event', '')
        )));
        $commitSha = strtolower(trim((string) (
            $payload['commit_sha']
            ?? data_get($payload, 'result.commit_sha', '')
        )));
        $filesCommitted = array_values(array_filter(array_map(
            'strval',
            (array) ($payload['files_committed'] ?? data_get($payload, 'result.files_committed', [])),
        )));
        // Real scoped land: task_resolved + non-empty commit sha + files_committed.
        // Orchestrator envelopes always stamp completion_real_allowed=false /
        // non_execution_guarantees about the QUEUE organ itself — those are not
        // evidence that a worker commit was fake.
        $realLand = $orchestratorEvent === 'task_resolved'
            && preg_match('/^[a-f0-9]{7,64}$/', $commitSha) === 1
            && $filesCommitted !== [];

        if (! $realLand && (str_contains($orchestratorEvent, 'dry_run') || $orchestratorEvent === 'completed_dry_run')) {
            $blockers[] = 'dry_run_completion_not_real_operation';
        }

        $completionReal = data_get($payload, 'result.completion_real_allowed', data_get($payload, 'completion_real_allowed'));
        if (! $realLand && $completionReal === false) {
            $blockers[] = 'completion_real_not_allowed';
        }

        $providerCallAllowed = data_get($payload, 'result.provider_call_allowed', data_get($payload, 'provider_call_allowed'));
        $runtimeExecAllowed = data_get($payload, 'result.runtime_execution_allowed', data_get($payload, 'runtime_execution_allowed'));
        if (! $realLand && $providerCallAllowed === false && $runtimeExecAllowed === false
            && (str_contains($blob, 'non_execution_guarantees') || str_contains($blob, 'does_not_call_provider'))) {
            $blockers[] = 'producer_non_execution_guarantees';
        }

        $verified = $payload['verified'] ?? null;
        if (! $realLand && $verified === false && (
            str_contains($orchestratorEvent, 'dry_run')
            || ($completionReal === false)
        )) {
            $blockers[] = 'producer_not_verified_real';
        }

        $excerpt = $payload['output_excerpt'] ?? null;
        $excerptText = '';
        $excerptChanged = null;
        $excerptBlockers = [];
        if (is_string($excerpt)) {
            $excerptText = strtolower($excerpt);
            $decoded = json_decode($excerpt, true);
            if (is_array($decoded)) {
                $excerpt = $decoded;
            }
        }
        if (is_array($excerpt)) {
            $excerptText = strtolower(json_encode($excerpt, JSON_UNESCAPED_SLASHES) ?: '');
            $excerptChanged = $excerpt['changed_files'] ?? null;
            foreach ((array) ($excerpt['blockers'] ?? []) as $b) {
                if (is_array($b)) {
                    $code = trim((string) ($b['code'] ?? ''));
                    if ($code !== '') {
                        $excerptBlockers[] = $code;
                    }
                } else {
                    $code = trim((string) $b);
                    if ($code !== '') {
                        $excerptBlockers[] = $code;
                    }
                }
            }
        }

        if (str_contains($excerptText, 'was not executed')
            || str_contains($excerptText, 'not executed')
            || str_contains($excerptText, 'no_executable_capabilities')
            || in_array('no_executable_capabilities', $excerptBlockers, true)) {
            $blockers[] = 'provider_mission_not_executed';
        }
        if (is_array($excerptChanged) && $excerptChanged === []
            && str_contains($excerptText, 'no executable')) {
            $blockers[] = 'provider_mission_empty_effect';
        }

        // Simulate-only forge live-execute path is never REAL_OPERATION.
        if (($payload['external_provider_call'] ?? null) === false
            && str_contains((string) ($payload['schema_version'] ?? ''), 'forge_live_execution')) {
            $blockers[] = 'simulate_only_forge_live_execute';
        }
        if (str_contains((string) ($payload['note'] ?? ''), 'sem provider externo')
            && str_contains((string) ($payload['schema_version'] ?? ''), 'forge_live_execution')) {
            $blockers[] = 'simulate_only_forge_live_execute';
        }

        $provenReal = data_get($payload, 'outcome_spine.spine.ai_run_outcome.proven_real')
            ?? data_get($payload, 'outcome_spine.outcome_contract_v2.verified')
            ?? data_get($payload, 'aemor_outcome.spine.ai_run_outcome.proven_real');
        $outcomeStatus = strtolower(trim((string) (
            data_get($payload, 'outcome_spine.outcome.status')
            ?? data_get($payload, 'aemor_outcome.outcome.status')
            ?? ''
        )));
        if (! $realLand && $provenReal === false && $outcomeStatus === 'blocked'
            && (str_contains($orchestratorEvent, 'dry_run') || $completionReal === false)) {
            $blockers[] = 'outcome_spine_not_proven_real';
        }

        $blockers = array_values(array_unique($blockers));

        return [
            'blockers' => $blockers,
            'summary' => [
                'payload_present' => true,
                'mode' => $mode,
                'orchestrator_event' => $orchestratorEvent !== '' ? $orchestratorEvent : null,
                'completion_real_allowed' => $completionReal,
                'provider_call_allowed' => $providerCallAllowed,
                'runtime_execution_allowed' => $runtimeExecAllowed,
                'verified' => $verified,
                'real_land' => $realLand,
                'commit_sha' => $commitSha !== '' ? $commitSha : null,
                'files_committed_count' => count($filesCommitted),
                'excerpt_blocker_codes' => $excerptBlockers,
                'honest' => $blockers === [],
            ],
        ];
    }

    /**
     * Named residual for honest PARTIAL when eng REAL_OPERATION cannot complete.
     *
     * @param  array<string,mixed>|null  $payload
     * @param  list<string>  $errorCodes
     * @return array{blockers:list<string>,residual:array<string,mixed>}
     */
    public static function structuredResidualFromProducerPayload(?array $payload, string $status, array $errorCodes): array
    {
        $blockers = [];
        $codes = array_values(array_unique(array_map('strval', $errorCodes)));
        // Always fold envelope status/reason into the candidate set — Autonomos
        // task-serving returns residual as top-level status/reason with empty blockers.
        if ($payload !== null) {
            foreach (['status', 'reason'] as $key) {
                $v = trim((string) ($payload[$key] ?? ''));
                if ($v !== '') {
                    $codes[] = $v;
                }
            }
            foreach (['remaining_blockers', 'blockers'] as $listKey) {
                foreach ((array) ($payload[$listKey] ?? []) as $item) {
                    $item = trim((string) $item);
                    if ($item !== '') {
                        $codes[] = $item;
                    }
                }
            }
        }
        if ($status !== '') {
            $codes[] = $status;
        }
        $codes = array_values(array_unique(array_map(
            static fn (string $c): string => trim($c),
            $codes,
        )));

        $named = [];
        foreach ($codes as $code) {
            if ($code === '' || self::isBenignTerminalToken($code)) {
                continue;
            }
            if (self::isNamedResidualCode($code)) {
                $named[] = $code;
            }
        }
        if ($status === 'blocked' || $status === 'failed') {
            $blockers[] = 'producer_eng_not_released';
        }
        // Non-success producer statuses that are not help/unparsed are themselves residuals.
        if ($named === [] && $status !== '' && ! self::isBenignTerminalToken($status)
            && ! in_array($status, ['passed', 'released', 'completed', 'completed_read_only', 'success', 'real_operation_completed'], true)) {
            $named[] = $status;
        }
        if ($named === [] && ($status === 'blocked' || $status === 'failed')) {
            $named[] = 'producer_blocked_without_named_code';
        }
        foreach ($named as $n) {
            $blockers[] = 'residual:'.$n;
        }

        $provider = (string) data_get($payload ?? [], 'run_summary.provider_call.provider', data_get($payload ?? [], 'provider', ''));
        $providerCalls = (int) data_get($payload ?? [], 'run_summary.provider_call.provider_calls', data_get($payload ?? [], 'provider_called') === true ? 1 : 0);

        // Placeholder honesty fields — journeyReceipt overwrites these from derived proofs.
        return [
            'blockers' => array_values(array_unique($blockers)),
            'residual' => [
                'schema' => self::SCHEMA.'.structured_residual',
                'producer_status' => $status,
                'named_residuals' => array_values(array_unique($named)),
                'provider' => $provider !== '' ? $provider : null,
                'provider_calls' => $providerCalls,
                'court_authority_eligible' => in_array($status, ['passed', 'released', 'completed', 'success', 'real_operation_completed'], true)
                    && ! array_any(
                        $named,
                        static fn (string $c): bool => str_contains(strtolower($c), 'court_authority_not_eligible'),
                    ),
                'covered_provider_spawn_proven' => false,
                'real_operation_completed' => false,
                'honesty' => 'residual_honest_partial_not_fabricated',
            ],
        ];
    }

    private static function isBenignTerminalToken(string $code): bool
    {
        $lower = strtolower(trim($code));

        return in_array($lower, [
            'ok', 'success', 'passed', 'released', 'completed', 'completed_read_only',
            'real_operation_completed', 'executed', 'succeeded', 'served_and_landed',
            'task_resolved', 'resolved', 'reported',
            'help_or_plan_surface', 'unparsed', '',
        ], true);
    }

    private static function isNamedResidualCode(string $code): bool
    {
        $lower = strtolower($code);
        if (str_contains($lower, 'court_authority_not_eligible')
            || str_contains($lower, 'verification_not_passed')
            || str_contains($lower, 'governor_authority_absent')
            || str_contains($lower, 'pre_effect_decision')
            || str_contains($lower, 'obra_required')
            || str_contains($lower, 'workspace_not_ready')
            || str_contains($lower, 'queue_scan_limit')
            || str_contains($lower, 'provider_')
            || str_contains($lower, 'risk:')
            || str_contains($lower, 'risk_blocked')
            || str_contains($lower, 'not_ready')
            || str_contains($lower, 'not_released')
            || str_contains($lower, 'limit_exceeded')
            || str_contains($lower, 'refused')
            || str_contains($lower, 'forbidden')
            || str_contains($lower, 'missing')
            || str_contains($lower, 'blocked')) {
            return true;
        }
        // Snake_case residual codes from task-serving / forge envelopes.
        if (preg_match('/^[a-z][a-z0-9_]{2,80}$/', $lower) === 1
            && ! self::isBenignTerminalToken($lower)
            && (str_contains($lower, '_') || str_ends_with($lower, 'ed'))) {
            return true;
        }

        return false;
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function extractJsonObject(string $stdout): ?array
    {
        $stdout = trim($stdout);
        if ($stdout === '') {
            return null;
        }
        $start = strpos($stdout, '{');
        if ($start === false) {
            return null;
        }
        $slice = substr($stdout, $start);
        try {
            $decoded = json_decode($slice, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            // Try last balanced-ish object from the end.
            $end = strrpos($stdout, '}');
            if ($end === false || $end <= $start) {
                return null;
            }
            try {
                $decoded = json_decode(substr($stdout, $start, $end - $start + 1), true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                return null;
            }
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string,mixed>|null  $payload
     * @return array<string,mixed>|null
     */
    private static function tryDeriveProviderSpawnFromPayload(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }
        // Senior-loop shape.
        $provider = trim((string) data_get($payload, 'run_summary.provider_call.provider', ''));
        $hash = strtolower(trim((string) data_get($payload, 'run_summary.verification_receipt_hash', data_get($payload, 'execution_hash', ''))));
        $calls = (int) data_get($payload, 'run_summary.provider_call.provider_calls', 0);
        $errors = (array) data_get($payload, 'run_summary.provider_call.error_codes', []);
        $exit = data_get($payload, 'run_summary.provider_call.exit_code');

        // Forge governed provider-invocation shape (non-simulate): provider + stdout_hash
        // only when provider_called is true (R84 — never launder dry_run/fixture).
        if ($provider === '' || preg_match('/^[a-f0-9]{64}$/', $hash) !== 1 || $calls < 1) {
            $forgeProvider = trim((string) ($payload['provider'] ?? ''));
            $forgeHash = strtolower(trim((string) ($payload['stdout_hash'] ?? $payload['provider_receipt_hash'] ?? '')));
            $forgeCalled = ($payload['provider_called'] ?? false) === true
                || ($payload['external_provider_call'] ?? false) === true;
            $forgeExit = $payload['exit_code'] ?? null;
            if ($forgeProvider !== '' && preg_match('/^[a-f0-9]{64}$/', $forgeHash) === 1 && $forgeCalled) {
                $provider = $forgeProvider;
                $hash = $forgeHash;
                $calls = 1;
                $exit = $forgeExit;
                $errors = array_values(array_map('strval', (array) ($payload['blockers'] ?? [])));
                // Only clear blockers when effect honesty accepts the mission as executed.
                $effect = self::effectHonestyFromPayload($payload, 'forge');
                if (strtolower((string) ($payload['status'] ?? '')) === 'executed' && $effect['blockers'] === []) {
                    $errors = [];
                } else {
                    $errors = array_values(array_unique(array_merge($errors, $effect['blockers'])));
                }
            }
        }

        // Daemon native_tick feedback with receipt hash (when present).
        if ($provider === '' || preg_match('/^[a-f0-9]{64}$/', $hash) !== 1 || $calls < 1) {
            foreach ((array) data_get($payload, 'ticks', []) as $tick) {
                if (! is_array($tick)) {
                    continue;
                }
                foreach ((array) ($tick['action_feedback'] ?? []) as $feedback) {
                    if (! is_array($feedback)) {
                        continue;
                    }
                    $fbProvider = trim((string) ($feedback['provider'] ?? data_get($tick, 'planned_actions.0.provider', '')));
                    $refs = (array) ($feedback['receipt_refs'] ?? []);
                    $fbHash = '';
                    foreach ($refs as $ref) {
                        $ref = strtolower(trim((string) $ref));
                        if (preg_match('/^[a-f0-9]{64}$/', $ref) === 1) {
                            $fbHash = $ref;
                            break;
                        }
                    }
                    if ($fbHash === '') {
                        $fbHash = strtolower(trim((string) ($tick['cycle_receipt_hash'] ?? '')));
                    }
                    if ($fbProvider !== ''
                        && preg_match('/^[a-f0-9]{64}$/', $fbHash) === 1
                        && ($feedback['outcome_class'] ?? '') === 'applied') {
                        $provider = $fbProvider;
                        $hash = $fbHash;
                        $calls = 1;
                        $exit = 0;
                        $errors = [];
                        break 2;
                    }
                }
            }
        }

        // Successful COVERED spawn requires calls>0, valid hash, and no governor/court residual errors alone is not enough —
        // never mark spawned=true when eng status blocked or hash missing.
        if ($provider === '' || preg_match('/^[a-f0-9]{64}$/', $hash) !== 1 || $calls < 1) {
            return null;
        }
        // Only treat as spawn proof when provider exit was 0 and no residual error codes.
        if ($exit !== 0 && $exit !== '0' && $exit !== null) {
            return [
                'provider' => $provider,
                'provider_receipt_hash' => $hash,
                'spawned' => false,
            ];
        }
        if ($errors !== []) {
            return [
                'provider' => $provider,
                'provider_receipt_hash' => $hash,
                'spawned' => false,
            ];
        }

        return [
            'provider' => $provider,
            'provider_receipt_hash' => $hash,
            'spawned' => true,
        ];
    }

    /**
     * @param  array<string,mixed>|null  $payload
     * @return array<string,mixed>|null
     */
    private static function tryDeriveAuthorityFromPayload(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }
        // Only accept explicit authority lineage fields already present in producer payload —
        // never invent from run_id alone. Prefer nested run_summary.authority_lineage
        // (senior-loop projects ConfirmedDevRun + sealed decision_event_id there).
        $lineage = data_get($payload, 'authority_lineage');
        if (! is_array($lineage)) {
            $lineage = data_get($payload, 'run_summary.authority_lineage');
        }
        if (! is_array($lineage)) {
            $lineage = [];
        }
        $ref = trim((string) ($lineage['authority_ref'] ?? data_get($payload, 'authority_ref', '')));
        $hash = strtolower(trim((string) ($lineage['authority_hash'] ?? data_get($payload, 'authority_hash', ''))));
        $revision = (int) ($lineage['authority_revision'] ?? data_get($payload, 'authority_revision', 0));

        // Forge provider-invocation: live decision receipt is the authority root when
        // both id and 64-hex content hash are present (never free-mint from invocation_id).
        if ($ref === '' || preg_match('/^[a-f0-9]{64}$/', $hash) !== 1 || $revision < 1) {
            $decisionId = trim((string) ($payload['decision_receipt_id'] ?? data_get($payload, 'receipt.decision_receipt_id', '')));
            $decisionHash = strtolower(trim((string) ($payload['decision_receipt_hash'] ?? data_get($payload, 'receipt.decision_receipt_hash', ''))));
            if ($decisionId !== '' && preg_match('/^[a-f0-9]{64}$/', $decisionHash) === 1) {
                $ref = $decisionId;
                $hash = $decisionHash;
                $revision = max(1, $revision);
            }
        }

        // Daemon cycle receipt as authority hash when mandate ref is explicit.
        if ($ref === '' || preg_match('/^[a-f0-9]{64}$/', $hash) !== 1 || $revision < 1) {
            $cycleHash = strtolower(trim((string) data_get($payload, 'ticks.0.cycle_receipt_hash', data_get($payload, 'daemon_cycle_hash', ''))));
            $mandate = trim((string) ($payload['authority_ref'] ?? data_get($payload, 'mandate_id', data_get($payload, 'native_journey_ref', ''))));
            if ($mandate !== '' && preg_match('/^[a-f0-9]{64}$/', $cycleHash) === 1) {
                $ref = $mandate;
                $hash = $cycleHash;
                $revision = max(1, $revision > 0 ? $revision : 1);
            }
        }

        if ($ref === '' || preg_match('/^[a-f0-9]{64}$/', $hash) !== 1 || $revision < 1) {
            return null;
        }

        return [
            'authority_ref' => $ref,
            'authority_hash' => $hash,
            'authority_revision' => $revision,
        ];
    }

    /**
     * Derived provider spawn proof — receipt hash + provider id, never a free bool.
     *
     * @param  mixed  $raw
     * @return array{ok:bool,blockers:list<string>,proof:array<string,mixed>|null}
     */
    public static function deriveProviderSpawnProof(mixed $raw): array
    {
        if ($raw === null) {
            return ['ok' => false, 'blockers' => ['provider_spawn_proof_missing'], 'proof' => null];
        }
        if (! is_array($raw)) {
            return ['ok' => false, 'blockers' => ['provider_spawn_proof_invalid'], 'proof' => null];
        }
        $provider = trim((string) ($raw['provider'] ?? ''));
        $receiptHash = strtolower(trim((string) ($raw['provider_receipt_hash'] ?? '')));
        $spawned = $raw['spawned'] ?? null;
        $blockers = [];
        if ($provider === '') {
            $blockers[] = 'provider_spawn_proof_provider_missing';
        }
        if (preg_match('/^[a-f0-9]{64}$/', $receiptHash) !== 1) {
            $blockers[] = 'provider_spawn_proof_receipt_hash_invalid';
        }
        // spawned must be derived from receipt presence, not an independent free flag alone.
        if ($spawned !== true || $receiptHash === '' || $provider === '') {
            if ($spawned === true && ($provider === '' || preg_match('/^[a-f0-9]{64}$/', $receiptHash) !== 1)) {
                $blockers[] = 'provider_spawn_proof_spawned_without_receipt';
            }
            if ($spawned !== true) {
                $blockers[] = 'provider_spawn_proof_not_spawned';
            }
        }
        $ok = $blockers === [];
        $proof = $ok ? [
            'provider' => $provider,
            'provider_receipt_hash' => $receiptHash,
            'spawned' => true,
            'derived' => true,
        ] : null;

        return ['ok' => $ok, 'blockers' => $blockers, 'proof' => $proof];
    }

    /**
     * Derived authority lineage proof — ref + content hash.
     *
     * @param  mixed  $raw
     * @return array{ok:bool,blockers:list<string>,proof:array<string,mixed>|null}
     */
    public static function deriveAuthorityLineageProof(mixed $raw): array
    {
        if ($raw === null) {
            return ['ok' => false, 'blockers' => ['authority_lineage_proof_missing'], 'proof' => null];
        }
        if (! is_array($raw)) {
            return ['ok' => false, 'blockers' => ['authority_lineage_proof_invalid'], 'proof' => null];
        }
        $ref = trim((string) ($raw['authority_ref'] ?? ''));
        $hash = strtolower(trim((string) ($raw['authority_hash'] ?? '')));
        $revision = (int) ($raw['authority_revision'] ?? 0);
        $blockers = [];
        if ($ref === '') {
            $blockers[] = 'authority_lineage_proof_ref_missing';
        }
        if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
            $blockers[] = 'authority_lineage_proof_hash_invalid';
        }
        if ($revision < 1) {
            $blockers[] = 'authority_lineage_proof_revision_invalid';
        }
        $ok = $blockers === [];
        $proof = $ok ? [
            'authority_ref' => $ref,
            'authority_hash' => $hash,
            'authority_revision' => $revision,
            'derived' => true,
        ] : null;

        return ['ok' => $ok, 'blockers' => $blockers, 'proof' => $proof];
    }

    /**
     * Freeze binder for P4-FREEZE.
     *
     * @param  array<string,array<string,mixed>>  $modeJourneys
     * @return array<string,mixed>
     */
    public static function freeze(array $modeJourneys, string $codeSha, string $ledgerCutoff = ''): array
    {
        $modes = [];
        foreach (['dev', 'forge', 'autonomos'] as $mode) {
            $j = $modeJourneys[$mode] ?? null;
            $modes[$mode] = [
                'present' => is_array($j),
                'journey_terminal_status' => is_array($j) ? ($j['journey_terminal_status'] ?? null) : null,
                'real_operation_qualified' => is_array($j) && ($j['real_operation_qualified'] ?? false) === true,
                'receipt_fingerprint' => is_array($j) ? hash('sha256', json_encode($j, JSON_THROW_ON_ERROR)) : null,
            ];
        }

        return [
            'schema' => self::SCHEMA.'.freeze',
            'code_sha' => $codeSha,
            'ledger_cutoff' => $ledgerCutoff !== '' ? $ledgerCutoff : null,
            'modes' => $modes,
            'all_three_modes_bound' => count(array_filter($modes, static fn (array $m): bool => $m['present'])) === 3,
            'full_real_operation_done' => array_reduce(
                $modes,
                static fn (bool $carry, array $m): bool => $carry && ($m['real_operation_qualified'] ?? false),
                true,
            ),
            'phpunit_alone_never_qualifies' => true,
        ];
    }

    /**
     * Certifier boundary: read-only profile must not append ledger or spawn provider.
     *
     * @param  array<string,mixed>  $certifyOptions
     * @return array{ok:bool,blockers:list<string>}
     */
    public static function certifyReadOnlyBoundary(array $certifyOptions): array
    {
        $blockers = [];
        if ((bool) ($certifyOptions['append_ledger'] ?? false)) {
            $blockers[] = 'certify_must_not_append_ledger';
        }
        if ((bool) ($certifyOptions['spawn_provider'] ?? false)) {
            $blockers[] = 'certify_must_not_spawn_provider';
        }
        if (($certifyOptions['profile'] ?? '') !== 'p4' && ($certifyOptions['profile'] ?? '') !== '') {
            // allow empty for unit; require p4 when set to other
            if (($certifyOptions['profile'] ?? null) !== null && $certifyOptions['profile'] !== 'p4') {
                $blockers[] = 'certify_profile_must_be_p4_for_gauntlet';
            }
        }

        return ['ok' => $blockers === [], 'blockers' => $blockers];
    }

    private static function redactSecrets(string $command): string
    {
        return (string) preg_replace('/(postgres(?:ql)?:\/\/)([^:\s]+):([^@\s]+)@/i', '$1***:***@', $command);
    }
}
