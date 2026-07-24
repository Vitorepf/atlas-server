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
        $blockers = array_merge($blockers, $structuredResidual['blockers']);

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

        return [
            'schema' => self::SCHEMA.'.journey',
            'mode' => $mode,
            'journey_terminal_status' => $terminal,
            'real_operation_qualified' => $terminal === self::STATUS_REAL_OPERATION_COMPLETED,
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
            'structured_residual' => $structuredResidual['residual'],
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

        $status = strtolower(trim((string) (
            $payload['status']
            ?? data_get($payload, 'run_summary.completion_state')
            ?? data_get($payload, 'aemor_outcome.status')
            ?? data_get($payload, 'journey_terminal_status')
            ?? ''
        )));
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
        ] as $bucket) {
            if (is_array($bucket)) {
                foreach ($bucket as $item) {
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
        $errorCodes = array_values(array_unique($errorCodes));
        $completed = in_array($status, ['passed', 'released', 'completed', 'completed_read_only', 'success', 'real_operation_completed'], true);

        return [
            'status' => $status,
            'completed' => $completed,
            'error_codes' => $errorCodes,
            'payload' => $payload,
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
        $providerCalls = (int) data_get($payload ?? [], 'run_summary.provider_call.provider_calls', 0);

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
            'real_operation_completed', 'help_or_plan_surface', 'unparsed', '',
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
        $provider = trim((string) data_get($payload, 'run_summary.provider_call.provider', ''));
        $hash = strtolower(trim((string) data_get($payload, 'run_summary.verification_receipt_hash', data_get($payload, 'execution_hash', ''))));
        $calls = (int) data_get($payload, 'run_summary.provider_call.provider_calls', 0);
        $errors = (array) data_get($payload, 'run_summary.provider_call.error_codes', []);
        // Successful COVERED spawn requires calls>0, valid hash, and no governor/court residual errors alone is not enough —
        // never mark spawned=true when eng status blocked or hash missing.
        if ($provider === '' || preg_match('/^[a-f0-9]{64}$/', $hash) !== 1 || $calls < 1) {
            return null;
        }
        // Only treat as spawn proof when provider exit was 0 and no residual error codes.
        $exit = data_get($payload, 'run_summary.provider_call.exit_code');
        if ($exit !== 0 && $exit !== '0') {
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
        // never invent from run_id alone.
        $ref = trim((string) data_get($payload, 'authority_lineage.authority_ref', data_get($payload, 'authority_ref', '')));
        $hash = strtolower(trim((string) data_get($payload, 'authority_lineage.authority_hash', data_get($payload, 'authority_hash', ''))));
        $revision = (int) data_get($payload, 'authority_lineage.authority_revision', data_get($payload, 'authority_revision', 0));
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
