<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * AP-810 / LHL-18 — L2 Process Isolation Integration (owner AP-793/809).
 *
 * This service reports the AP-793 process-isolation level HONESTLY. It is an
 * integration CONTRACT/STATUS surface only — it does NOT build a container, does
 * NOT spawn a sandbox, does NOT run the loop, merge, delete branches or call a
 * provider. It is read-only / deterministic / input-seam driven.
 *
 * Today the loop runs at L1 (git worktree only): agents execute on the host with
 * no real filesystem / env / network confinement and full secrets visibility. A
 * true L2 process-isolated sandbox (per-cycle provider process boxed with FS/env/
 * network limits and secrets hidden) is NOT yet wired — so this service must NEVER
 * claim L2 unless an explicit input seam asserts a real isolation level.
 *
 * Honesty rule (operator does not accept false claims): a long horizon
 * (7d / 14d / 30d / months) demands process isolation. Under L1 the service emits
 * `blocks_long_horizon = true` and lists the blocked horizons — a multi-week
 * autonomous run on the host is BLOCKED, never dressed as ready. AP-807/808/809
 * reports embed this isolation level via the input seam.
 *
 * Contract: AP-793 process isolation; AP-810 build contract slice LHL-18.
 */
final class LoopProcessIsolationStatusService
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.loop_process_isolation.v1';

    /** L1 = git worktree only; agents run on the host (current real state). */
    public const STATUS_L1 = 'l1_worktree_only';

    /** L2 = process-isolated sandbox (FS/env/network limited, secrets hidden). */
    public const STATUS_L2 = 'l2_process_isolated';

    /** Horizons that REQUIRE process isolation; under L1 each is blocked. */
    private const LONG_HORIZONS = ['7d', '14d', '30d', 'months'];

    /**
     * Single entrypoint. Every key is optional; the diagnostic default reports the
     * real current state (L1 worktree only) and never crashes.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function status(array $input = []): array
    {
        // A wiring-phase `fixture` may carry the whole isolation record; fold it
        // under the explicit input so direct keys still win (input-seam compose).
        $input = $this->mergeFixture($input);

        $area = trim((string) ($input['area'] ?? 'agentic_engineering_os')) ?: 'agentic_engineering_os';
        $focus = trim((string) ($input['focus'] ?? 'dev_forge')) ?: 'dev_forge';

        // Isolation level: HONEST default is L1. L2 is claimed ONLY when an explicit
        // seam asserts it — a synthetic/blank shape never yields L2.
        $isolationLevel = $this->normalizeLevel($input['isolation_level'] ?? null);
        $isL2 = $isolationLevel === self::STATUS_L2;

        $blockers = [];
        $warnings = [];

        // Provider sandbox status — under L1 there is no real sandbox; the provider
        // process runs directly on the host.
        $sandboxProviderStatus = $this->resolveSandboxStatus($input, $isL2);
        if (! $isL2 && $sandboxProviderStatus !== 'none') {
            // A sandbox is claimed without a real L2 level — refuse the inflated claim.
            $warnings[] = 'sandbox_claimed_without_l2_isolation';
            $sandboxProviderStatus = 'none';
        }

        // Limits. Under L1 there is no filesystem/env/network confinement and
        // secrets are fully visible to the agent. Each is overridable via seam.
        $limits = $this->resolveLimits($input, $isL2);

        // Secrets visibility: under L1 secrets ARE visible by default (host env).
        // The boolean is "are secrets hidden from the agent?" — false under L1.
        $secretsHidden = (bool) $limits['secrets'];

        $horizon = $this->normalizeHorizon($input['horizon'] ?? null);

        // Long-horizon gate: any long horizon under L1 is blocked.
        $blockedHorizons = [];
        $blocksLongHorizon = false;
        if (! $isL2) {
            // Without process isolation, every long horizon is unreachable.
            $blockedHorizons = self::LONG_HORIZONS;
            // The run only *blocks* when a long horizon is actually requested.
            if ($horizon !== null && in_array($horizon, self::LONG_HORIZONS, true)) {
                $blocksLongHorizon = true;
                $blockers[] = 'long_horizon_requires_l2_process_isolation';
            }
        }

        // Status mirrors the isolation level exactly: L2 when truly isolated,
        // otherwise the honest L1.
        $status = $isL2 ? self::STATUS_L2 : self::STATUS_L1;

        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-793',
            'slice_id' => 'LHL-18',
            'status' => $status,
            'isolation_id' => 'iso_'.substr(MissionCanonicalHash::sha256([
                $area,
                $focus,
                $status,
                $horizon ?? '',
            ]), 0, 16),
            'area' => $area,
            'focus' => $focus,
            'checked_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'isolation_level' => $status,
            'horizon' => $horizon,
            'sandbox_provider_status' => $sandboxProviderStatus,
            'limits' => [
                'filesystem' => $limits['filesystem'],
                'env' => $limits['env'],
                'network' => $limits['network'],
                'secrets' => $secretsHidden,
            ],
            'secrets_visible' => ! $secretsHidden,
            'blocks_long_horizon' => $blocksLongHorizon,
            'blocked_horizons' => array_values($blockedHorizons),
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => array_values(array_unique($warnings)),
            'next_action' => $blocksLongHorizon ? 'stop_long_horizon_requires_l2' : 'continue',
            'claim_policy' => [
                'read_only' => true,
                'runs_provider' => false,
                'runs_loop' => false,
                'runs_merge' => false,
                'deletes_branches' => false,
                'builds_container' => false,
                'blocked_never_dressed_as_ready' => true,
            ],
        ];

        $payload['report_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->withoutVolatile($payload));

        return $payload;
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Resolve provider sandbox status. Default `none` under L1 (host process).
     *
     * @param  array<string,mixed>  $input
     */
    private function resolveSandboxStatus(array $input, bool $isL2): string
    {
        if (array_key_exists('sandbox_provider_status', $input)) {
            $raw = strtolower(trim((string) $input['sandbox_provider_status']));

            return $raw === '' ? 'none' : $raw;
        }

        // No seam: under L1 there is no sandbox; under an asserted L2 it is active.
        return $isL2 ? 'active' : 'none';
    }

    /**
     * Resolve the confinement limits. Under L1 nothing is confined (all false) and
     * secrets are visible (secrets-hidden = false). Each is overridable via seam.
     *
     * @param  array<string,mixed>  $input
     * @return array{filesystem:bool,env:bool,network:bool,secrets:bool}
     */
    private function resolveLimits(array $input, bool $isL2): array
    {
        $seam = is_array($input['limits'] ?? null) ? $input['limits'] : [];

        // Default per level: L2 confines everything + hides secrets; L1 confines
        // nothing + exposes secrets.
        $default = $isL2;

        return [
            'filesystem' => array_key_exists('filesystem', $seam) ? (bool) $seam['filesystem'] : $default,
            'env' => array_key_exists('env', $seam) ? (bool) $seam['env'] : $default,
            'network' => array_key_exists('network', $seam) ? (bool) $seam['network'] : $default,
            'secrets' => array_key_exists('secrets', $seam) ? (bool) $seam['secrets'] : $default,
        ];
    }

    private function normalizeLevel(mixed $value): string
    {
        $value = strtolower(trim((string) $value));

        return match ($value) {
            self::STATUS_L2, 'l2', 'l2_process_isolated', 'process_isolated' => self::STATUS_L2,
            // Anything else (blank, l1, unknown) is honestly L1 — never inflate.
            default => self::STATUS_L1,
        };
    }

    private function normalizeHorizon(mixed $value): ?string
    {
        $value = strtolower(trim((string) $value));
        if ($value === '') {
            return null;
        }

        return match ($value) {
            '10c', '10_cycles', 'ten_cycles' => '10c',
            '1000c', '1000_cycles' => '1000c',
            '1h', '2h', '8h', '24h' => $value,
            '7d', '14d', '30d' => $value,
            'months', 'month', 'multi_month' => 'months',
            default => $value,
        };
    }

    /**
     * A wiring-phase `fixture` may be a single isolation record; fold it under the
     * explicit input so direct keys still take precedence (input-seam composition).
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function mergeFixture(array $input): array
    {
        $fixture = $input['fixture'] ?? null;
        if (! is_array($fixture) || $fixture === []) {
            return $input;
        }
        unset($input['fixture']);

        return array_merge($fixture, $input);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function withoutVolatile(array $payload): array
    {
        unset($payload['checked_at'], $payload['report_hash']);

        return $payload;
    }
}
