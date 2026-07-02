<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Governance;

/**
 * v3 Policy Plane for the task lane: governance policy as inspectable DATA
 * (config/atlas_task_governance.php) instead of scattered env reads and hard-codes.
 *
 * SMALL, pure, read-only, side-effect free. Every method has a safe fallback that reproduces
 * today's hard-coded behavior exactly when the config is absent/empty:
 *   - modeFor()            defaults to 'observe'
 *   - releaseWindow()      defaults to ['low', 'medium']  (AtlasTaskCommitGovernanceChain::releaseWindow())
 *   - verifierEnabledDefault() defaults to true            (AtlasTaskCommitVerificationGate::enabled())
 *   - isolationContract()  defaults to 'shared_local_main_with_scope_lock'
 *
 * PRECEDENCE — the existing env vars remain emergency overrides that beat config when set:
 *   ATLAS_MERGE_GOVERNANCE_MODE          beats config in modeFor()
 *   ATLAS_MERGE_GOVERNANCE_RISK_WINDOW   beats config in releaseWindow()
 *   (ATLAS_TASK_SERVING_VERIFY_BEFORE_COMMIT is read by the verification gate itself, not here,
 *    since only the gate knows whether the caller explicitly set it vs relying on the default.)
 */
final class AtlasTaskGovernancePolicyPlane
{
    private const VALID_MODES = ['off', 'observe', 'enforce'];

    private const DEFAULT_MODE = 'observe';

    private const DEFAULT_EVIDENCE_CONTRACT_MODE = 'observe';

    private const DEFAULT_RELEASE_WINDOW = ['low', 'medium'];

    private const DEFAULT_VERIFIER_ENABLED = true;

    private const DEFAULT_ISOLATION_CONTRACT = 'shared_local_main_with_scope_lock';

    private const KNOWN_CHECKS = ['syntax', 'boot', 'task_tests', 'required_test'];

    private const DEFAULT_MODEL_TIER = 'frontier';

    private const VALID_MODEL_TIERS = ['small', 'medium', 'frontier', 'split'];

    /**
     * @param  array<string,mixed>|null  $configOverride  injectable for pure unit tests; null reads
     *                                                     config('atlas_task_governance') at call time
     */
    public function __construct(private readonly ?array $configOverride = null) {}

    /** Governance mode for one risk level. Env override beats config; config beats the 'observe' default. */
    public function modeFor(string $riskLevel): string
    {
        $envRaw = env('ATLAS_MERGE_GOVERNANCE_MODE');
        if ($envRaw !== null && trim((string) $envRaw) !== '') {
            $raw = strtolower(trim((string) $envRaw));

            return in_array($raw, self::VALID_MODES, true) ? $raw : self::DEFAULT_MODE;
        }

        $level = $this->riskLevelConfig($riskLevel);
        $mode = strtolower(trim((string) ($level['mode'] ?? '')));

        return in_array($mode, self::VALID_MODES, true) ? $mode : self::DEFAULT_MODE;
    }

    /** @return list<string> subset of syntax|boot|task_tests|required_test declared for this risk level. */
    public function requiredChecksFor(string $riskLevel): array
    {
        $level = $this->riskLevelConfig($riskLevel);
        $checks = array_values(array_map('strval', (array) ($level['required_checks'] ?? [])));

        return array_values(array_intersect($checks, self::KNOWN_CHECKS));
    }

    /** @return list<string> risk levels admissible for release. Env override beats config. */
    public function releaseWindow(): array
    {
        $envRaw = env('ATLAS_MERGE_GOVERNANCE_RISK_WINDOW');
        if ($envRaw !== null && trim((string) $envRaw) !== '') {
            $levels = array_values(array_filter(
                array_map('trim', explode(',', (string) $envRaw)),
                static fn (string $s): bool => $s !== '',
            ));

            return $levels === [] ? self::DEFAULT_RELEASE_WINDOW : $levels;
        }

        $riskLevels = (array) ($this->config()['risk_levels'] ?? []);
        if ($riskLevels === []) {
            return self::DEFAULT_RELEASE_WINDOW;
        }

        $window = [];
        foreach ($riskLevels as $name => $level) {
            $level = (array) $level;
            if ((bool) ($level['in_release_window'] ?? false)) {
                $window[] = (string) $name;
            }
        }

        return $window === [] ? self::DEFAULT_RELEASE_WINDOW : $window;
    }

    public function verifierEnabledDefault(): bool
    {
        $cfg = $this->config();

        return array_key_exists('server_verifier_enabled_default', $cfg)
            ? (bool) $cfg['server_verifier_enabled_default']
            : self::DEFAULT_VERIFIER_ENABLED;
    }

    /**
     * Default OFF. Gates {@see \App\Services\Ai\SelfConstruction\Governance\AtlasTaskPostLandCanarySentinel}
     * in the serving report commit path — absent config key reproduces today's behavior exactly
     * (no canary observation, zero receipts).
     */
    public function canaryEnabled(): bool
    {
        return (bool) ($this->config()['canary_enabled'] ?? false);
    }

    /**
     * Default OFF. When a give_back report quarantines a packet (doomed after repeated
     * give-backs), run the atlas:task:repair-blocked pass automatically instead of leaving
     * the packet blocked until a human runs it — queue self-healing: a bad spec stops
     * burning worker muscle. Absent config key reproduces today's behavior exactly.
     */
    public function autoRespecOnQuarantineEnabled(): bool
    {
        return (bool) ($this->config()['auto_respec_on_quarantine'] ?? false);
    }

    /**
     * off|observe|enforce, default observe. Gates
     * {@see \App\Services\Ai\SelfConstruction\VerificationCourt\AtlasVerificationCourtEvidenceContract}
     * on the serving report commit path: off skips evaluation entirely (byte-identical legacy
     * behavior), observe records the verdict without blocking, enforce refuses the commit on a
     * failed verdict. An invalid/unknown config value safely falls back to observe.
     */
    public function evidenceContractMode(): string
    {
        $raw = strtolower(trim((string) ($this->config()['evidence_contract_mode'] ?? '')));

        return in_array($raw, self::VALID_MODES, true) ? $raw : self::DEFAULT_EVIDENCE_CONTRACT_MODE;
    }

    public function isolationContract(): string
    {
        $val = trim((string) ($this->config()['isolation_contract'] ?? ''));

        return $val !== '' ? $val : self::DEFAULT_ISOLATION_CONTRACT;
    }

    /**
     * Dev model-tier policy: (task_kind, risk_level, workcell_size_class) -> small|medium|frontier|split.
     * Safe fallback to 'frontier' (today's behavior) when the config is absent or the combination is
     * undeclared — a missing policy entry is never silently interpreted as "use a cheaper model".
     */
    public function modelTierFor(string $taskKind, string $riskLevel, string $sizeClass): string
    {
        $policy = (array) ($this->config()['dev_model_tier_policy'] ?? []);
        $byTaskKind = (array) ($policy[$taskKind] ?? []);
        $byRiskLevel = (array) ($byTaskKind[$riskLevel] ?? []);
        $tier = strtolower(trim((string) ($byRiskLevel[$sizeClass] ?? '')));

        return in_array($tier, self::VALID_MODEL_TIERS, true) ? $tier : self::DEFAULT_MODEL_TIER;
    }

    /** @return array<string,mixed> */
    private function riskLevelConfig(string $riskLevel): array
    {
        $riskLevels = (array) ($this->config()['risk_levels'] ?? []);

        return (array) ($riskLevels[$riskLevel] ?? []);
    }

    /**
     * @return array<string,mixed>
     *
     * Defensive: some callers (pure PHPUnit\Framework\TestCase unit tests, no Laravel bootstrap) invoke
     * this without a container bound, where the config() helper throws — degrade to "no config" (safe
     * defaults) instead of propagating, matching the fail-open ethos of the rest of the governance chain.
     */
    private function config(): array
    {
        if ($this->configOverride !== null) {
            return $this->configOverride;
        }

        try {
            $cfg = config('atlas_task_governance');
        } catch (\Throwable) {
            return [];
        }

        return is_array($cfg) ? $cfg : [];
    }
}
