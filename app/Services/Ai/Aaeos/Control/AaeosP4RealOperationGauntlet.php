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

        $terminal = self::STATUS_PREFLIGHT_REFUSED;
        if ($blockers !== [] && ! $planOnly) {
            $terminal = self::STATUS_BLOCKED_OPS_PARTIAL;
        } elseif ($planOnly && $exit === 0 && $command !== '') {
            // Plan-only proves entry point; not REAL_OPERATION qualification.
            $terminal = self::STATUS_BLOCKED_OPS_PARTIAL;
            $blockers[] = 'plan_only_not_real_operation';
            if (! $preflight['durable_pg_ready']) {
                $blockers[] = 'durable_pg_roles_required_for_real_operation';
            }
        } elseif (! $planOnly && $exit === 0 && $preflight['durable_pg_ready']
            && (bool) ($context['provider_spawn_attested'] ?? false)
            && (bool) ($context['authority_lineage_present'] ?? false)) {
            $terminal = self::STATUS_REAL_OPERATION_COMPLETED;
            $blockers = [];
        } elseif ($exit !== 0) {
            $terminal = self::STATUS_BLOCKED_OPS_PARTIAL;
            $blockers[] = 'producer_exit_nonzero';
        } else {
            $terminal = self::STATUS_BLOCKED_OPS_PARTIAL;
            $blockers[] = 'real_operation_predicates_incomplete';
        }

        return [
            'schema' => self::SCHEMA.'.journey',
            'mode' => $mode,
            'journey_terminal_status' => $terminal,
            'real_operation_qualified' => $terminal === self::STATUS_REAL_OPERATION_COMPLETED,
            'exit_code' => $exit,
            'exit_zero_alone_never_qualifies' => true,
            'command' => self::redactSecrets($command),
            'stdout_fingerprint' => hash('sha256', $stdout),
            'plan_only' => $planOnly,
            'aaeos_initiated' => $aaeosInitiated,
            'preflight' => $preflight,
            'blockers' => array_values(array_unique($blockers)),
            'authority_lineage_present' => (bool) ($context['authority_lineage_present'] ?? false),
            'provider_spawn_attested' => (bool) ($context['provider_spawn_attested'] ?? false),
            'operator_task_causal_count' => (int) ($context['operator_task_causal_count'] ?? 0),
            'r104_transport_open' => (bool) ($context['r104_transport_open'] ?? true),
            'code_sha' => (string) ($context['code_sha'] ?? ''),
        ];
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
