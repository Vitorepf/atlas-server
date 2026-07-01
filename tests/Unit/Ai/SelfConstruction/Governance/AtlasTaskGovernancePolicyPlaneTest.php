<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Governance;

use App\Services\Ai\SelfConstruction\Governance\AtlasTaskGovernancePolicyPlane;
use PHPUnit\Framework\TestCase;

final class AtlasTaskGovernancePolicyPlaneTest extends TestCase
{
    private const MODE_ENV = 'ATLAS_MERGE_GOVERNANCE_MODE';

    private const WINDOW_ENV = 'ATLAS_MERGE_GOVERNANCE_RISK_WINDOW';

    protected function tearDown(): void
    {
        $this->unsetEnv(self::MODE_ENV);
        $this->unsetEnv(self::WINDOW_ENV);
        parent::tearDown();
    }

    private function setEnv(string $key, string $value): void
    {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    private function unsetEnv(string $key): void
    {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }

    // ── (a) env unset: modeFor() returns the config-declared mode per risk level ──

    public function test_mode_for_returns_config_declared_mode_per_risk_level_when_env_unset(): void
    {
        $plane = new AtlasTaskGovernancePolicyPlane([
            'risk_levels' => [
                'low' => ['mode' => 'observe'],
                'high' => ['mode' => 'enforce'],
            ],
        ]);

        $this->assertSame('observe', $plane->modeFor('low'));
        $this->assertSame('enforce', $plane->modeFor('high'));
    }

    public function test_mode_for_unknown_risk_level_falls_back_to_observe(): void
    {
        $plane = new AtlasTaskGovernancePolicyPlane(['risk_levels' => ['low' => ['mode' => 'enforce']]]);

        $this->assertSame('observe', $plane->modeFor('critical'));
    }

    // ── (b) env override beats config ───────────────────────────────────────

    public function test_env_mode_override_beats_config_declared_mode(): void
    {
        $this->setEnv(self::MODE_ENV, 'off');

        $plane = new AtlasTaskGovernancePolicyPlane(['risk_levels' => ['high' => ['mode' => 'enforce']]]);

        $this->assertSame('off', $plane->modeFor('high'));
    }

    public function test_env_mode_override_beats_config_across_all_risk_levels(): void
    {
        $this->setEnv(self::MODE_ENV, 'enforce');

        $plane = new AtlasTaskGovernancePolicyPlane(['risk_levels' => ['low' => ['mode' => 'observe']]]);

        $this->assertSame('enforce', $plane->modeFor('low'));
        $this->assertSame('enforce', $plane->modeFor('unknown'));
    }

    public function test_invalid_env_mode_value_falls_back_to_observe(): void
    {
        $this->setEnv(self::MODE_ENV, 'garbage');

        $plane = new AtlasTaskGovernancePolicyPlane(['risk_levels' => ['low' => ['mode' => 'enforce']]]);

        $this->assertSame('observe', $plane->modeFor('low'));
    }

    // ── (c) empty config reproduces today's behavior ────────────────────────

    public function test_empty_config_defaults_mode_to_observe(): void
    {
        $plane = new AtlasTaskGovernancePolicyPlane([]);

        $this->assertSame('observe', $plane->modeFor('low'));
        $this->assertSame('observe', $plane->modeFor('high'));
    }

    public function test_empty_config_defaults_release_window_to_low_and_medium(): void
    {
        $plane = new AtlasTaskGovernancePolicyPlane([]);

        $this->assertSame(['low', 'medium'], $plane->releaseWindow());
    }

    public function test_empty_config_defaults_verifier_enabled_to_true(): void
    {
        $plane = new AtlasTaskGovernancePolicyPlane([]);

        $this->assertTrue($plane->verifierEnabledDefault());
    }

    public function test_empty_config_defaults_isolation_contract(): void
    {
        $plane = new AtlasTaskGovernancePolicyPlane([]);

        $this->assertSame('shared_local_main_with_scope_lock', $plane->isolationContract());
    }

    // ── releaseWindow(): config-declared + env override ─────────────────────

    public function test_release_window_reflects_config_declared_in_release_window_flags(): void
    {
        $plane = new AtlasTaskGovernancePolicyPlane([
            'risk_levels' => [
                'low' => ['in_release_window' => true],
                'medium' => ['in_release_window' => true],
                'high' => ['in_release_window' => false],
                'critical' => ['in_release_window' => false],
            ],
        ]);

        $this->assertSame(['low', 'medium'], $plane->releaseWindow());
    }

    public function test_env_release_window_override_beats_config(): void
    {
        $this->setEnv(self::WINDOW_ENV, 'high,critical');

        $plane = new AtlasTaskGovernancePolicyPlane([
            'risk_levels' => ['low' => ['in_release_window' => true]],
        ]);

        $this->assertSame(['high', 'critical'], $plane->releaseWindow());
    }

    // ── requiredChecksFor(): subset of syntax|boot|task_tests|required_test ─

    public function test_required_checks_for_returns_declared_subset(): void
    {
        $plane = new AtlasTaskGovernancePolicyPlane([
            'risk_levels' => ['high' => ['required_checks' => ['syntax', 'boot', 'task_tests', 'required_test']]],
        ]);

        $this->assertSame(['syntax', 'boot', 'task_tests', 'required_test'], $plane->requiredChecksFor('high'));
    }

    public function test_required_checks_for_unknown_risk_level_is_empty(): void
    {
        $plane = new AtlasTaskGovernancePolicyPlane(['risk_levels' => []]);

        $this->assertSame([], $plane->requiredChecksFor('low'));
    }

    public function test_required_checks_for_filters_out_unknown_check_names(): void
    {
        $plane = new AtlasTaskGovernancePolicyPlane([
            'risk_levels' => ['low' => ['required_checks' => ['syntax', 'not_a_real_check']]],
        ]);

        $this->assertSame(['syntax'], $plane->requiredChecksFor('low'));
    }

    // ── verifierEnabledDefault() / isolationContract() from config ──────────

    public function test_verifier_enabled_default_reads_config_value(): void
    {
        $plane = new AtlasTaskGovernancePolicyPlane(['server_verifier_enabled_default' => false]);

        $this->assertFalse($plane->verifierEnabledDefault());
    }

    public function test_isolation_contract_reads_config_value(): void
    {
        $plane = new AtlasTaskGovernancePolicyPlane(['isolation_contract' => 'custom_topology']);

        $this->assertSame('custom_topology', $plane->isolationContract());
    }

    // ── real production config file loads and matches today's behavior ──────

    public function test_real_config_file_reproduces_current_defaults(): void
    {
        $config = require __DIR__.'/../../../../../config/atlas_task_governance.php';
        $plane = new AtlasTaskGovernancePolicyPlane($config);

        $this->assertSame('observe', $plane->modeFor('low'));
        $this->assertSame('observe', $plane->modeFor('medium'));
        $this->assertSame(['low', 'medium'], $plane->releaseWindow());
        $this->assertTrue($plane->verifierEnabledDefault());
        $this->assertSame('shared_local_main_with_scope_lock', $plane->isolationContract());
    }
}
