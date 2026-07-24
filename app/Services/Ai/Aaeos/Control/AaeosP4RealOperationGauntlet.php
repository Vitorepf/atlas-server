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

        $providerProof = self::deriveProviderSpawnProof($context['provider_spawn_proof'] ?? null);
        $authorityProof = self::deriveAuthorityLineageProof($context['authority_lineage_proof'] ?? null);
        $blockers = array_merge($blockers, $providerProof['blockers'], $authorityProof['blockers']);

        $hardBlockers = array_values(array_unique($blockers));
        $terminal = self::STATUS_BLOCKED_OPS_PARTIAL;

        if ($planOnly) {
            $hardBlockers[] = 'plan_only_not_real_operation';
            if (! $preflight['durable_pg_ready']) {
                $hardBlockers[] = 'durable_pg_roles_required_for_real_operation';
            }
        } elseif ($exit !== 0) {
            $hardBlockers[] = 'producer_exit_nonzero';
        } elseif (! $preflight['durable_pg_ready']) {
            $hardBlockers[] = 'durable_pg_roles_required_for_real_operation';
        } elseif (! $providerProof['ok'] || ! $authorityProof['ok']) {
            $hardBlockers[] = 'derived_capability_proofs_incomplete';
        } elseif ($hardBlockers === [] && $exit === 0 && $command !== '') {
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
            'capability_proof_derived_not_caller_set' => true,
            'command' => self::redactSecrets($command),
            'stdout_fingerprint' => hash('sha256', $stdout),
            'plan_only' => $planOnly,
            'aaeos_initiated' => $aaeosInitiated,
            'preflight' => $preflight,
            'blockers' => $blockers,
            'provider_spawn_proof' => $providerProof['proof'],
            'authority_lineage_proof' => $authorityProof['proof'],
            'operator_task_causal_count' => (int) ($context['operator_task_causal_count'] ?? 0),
            'r104_transport_open' => (bool) ($context['r104_transport_open'] ?? true),
            'code_sha' => (string) ($context['code_sha'] ?? ''),
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
