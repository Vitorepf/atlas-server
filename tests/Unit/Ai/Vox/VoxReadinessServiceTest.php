<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Vox;

use App\Services\Ai\Vox\Audit\VoxV3HardeningAuditService;
use App\Services\Ai\Vox\Gate\VoxV3PromotionGateService;
use App\Services\Ai\Vox\Metrics\VoxMetricsService;
use App\Services\Ai\Vox\Readiness\VoxReadinessService;
use App\Services\Ai\Vox\VoxSchema;
use Tests\TestCase;

/**
 * Aggregation contract for VoxReadinessService:
 *   - any blocking-blocked check → overall 'blocked'
 *   - any warning or unknown (no blocked) → 'partial'
 *   - all passed → 'ready'
 *   - unknown NEVER becomes a silent pass
 */
final class VoxReadinessServiceTest extends TestCase
{
    private function service(?VoxV3HardeningAuditService $hardening = null): VoxReadinessService
    {
        $metrics = new VoxMetricsService();
        $gate = new VoxV3PromotionGateService($metrics);

        return new VoxReadinessService($metrics, $gate, $hardening);
    }

    public function test_probe_returns_canonical_schema_and_check_set(): void
    {
        $out = $this->service()->probe();
        $this->assertSame(VoxSchema::READINESS, $out['schema']);
        $names = array_column($out['checks'], 'code');
        foreach (VoxReadinessService::CHECK_NAMES as $required) {
            $this->assertContains($required, $names, "missing check: {$required}");
        }
    }

    public function test_capabilities_match_canonical_contract(): void
    {
        $out = $this->service()->probe();
        $cap = $out['capabilities'];
        // Architectural NOs · these must be false regardless of state.
        $this->assertFalse($cap['terminal_execute']);
        $this->assertFalse($cap['cloud_stt']);
        $this->assertFalse($cap['paid_api_required']);
        // Textual modes are always supported in this build.
        $this->assertTrue($cap['dictation']);
        $this->assertTrue($cap['prompt_polish']);
        $this->assertTrue($cap['intent_compile']);
        $this->assertTrue($cap['governed_execute']);
        $this->assertTrue($cap['terminal_propose']);
    }

    public function test_status_is_partial_when_provider_clis_absent(): void
    {
        // No ATLAS_VOX_*_BIN env set in unit test → both providers will
        // report warning. No blocking-blocked. Overall: partial.
        config()->set('atlas.vox.executors.codex_cli.binary', null);
        config()->set('atlas.vox.executors.claude_cli.binary', null);

        $out = $this->service()->probe();
        $this->assertSame(VoxReadinessService::STATUS_PARTIAL, $out['status']);

        // Provider checks specifically must be warning, never blocked.
        $codexCheck = $this->findCheck($out, 'codex_cli_configured_or_unavailable');
        $claudeCheck = $this->findCheck($out, 'claude_cli_configured_or_unavailable');
        $this->assertSame(VoxReadinessService::CHECK_WARNING, $codexCheck['status']);
        $this->assertSame(VoxReadinessService::CHECK_WARNING, $claudeCheck['status']);
        $this->assertFalse($codexCheck['blocking']);
        $this->assertFalse($claudeCheck['blocking']);

        // governed_execute is still 'available' (capability) — provider
        // dispatch sub-capability is what flips to false.
        $this->assertTrue($out['capabilities']['governed_execute']);
        $this->assertFalse($out['capabilities']['governed_execute_provider_dispatch']);
    }

    public function test_unknown_hardening_does_not_become_silent_pass(): void
    {
        // No hardening service injected → check is 'unknown'.
        $out = $this->service(hardening: null)->probe();
        $hardening = $this->findCheck($out, 'hardening_audit_available');
        $this->assertSame(VoxReadinessService::CHECK_UNKNOWN, $hardening['status']);
        // Aggregation must lift the overall status above ready.
        $this->assertNotSame(VoxReadinessService::STATUS_READY, $out['status']);
    }

    public function test_next_actions_carry_unknown_and_warning_hints(): void
    {
        config()->set('atlas.vox.executors.codex_cli.binary', null);
        config()->set('atlas.vox.executors.claude_cli.binary', null);

        $out = $this->service()->probe();
        $hint = strtolower(implode(' | ', $out['next_actions']));
        $this->assertStringContainsString('codex_cli', $hint);
        $this->assertStringContainsString('claude_cli', $hint);
    }

    public function test_status_is_ready_when_both_cli_present_and_hardening_passes(): void
    {
        $tmp = sys_get_temp_dir().'/vox-readiness-fake-cli-'.bin2hex(random_bytes(4));
        file_put_contents($tmp, "#!/bin/sh\necho ok\n");
        chmod($tmp, 0755);
        config()->set('atlas.vox.executors.codex_cli.binary', $tmp);
        config()->set('atlas.vox.executors.claude_cli.binary', $tmp);

        $hardening = app(VoxV3HardeningAuditService::class);
        $out = $this->service($hardening)->probe();

        @unlink($tmp);

        $codex = $this->findCheck($out, 'codex_cli_configured_or_unavailable');
        $claude = $this->findCheck($out, 'claude_cli_configured_or_unavailable');
        $this->assertSame(VoxReadinessService::CHECK_PASSED, $codex['status']);
        $this->assertSame(VoxReadinessService::CHECK_PASSED, $claude['status']);

        // With both providers available and hardening clean, the readiness
        // probe should reach READY. Hardening audit on a clean test schema
        // ought to return pass; if it doesn't, the assertion fails honestly
        // (we'd rather catch drift than mask).
        $this->assertSame(VoxReadinessService::STATUS_READY, $out['status']);
        $this->assertTrue($out['capabilities']['governed_execute_provider_dispatch']);
    }

    /**
     * @param  array<string,mixed>  $out
     * @return array<string,mixed>
     */
    private function findCheck(array $out, string $code): array
    {
        foreach ($out['checks'] as $c) {
            if ((string) ($c['code'] ?? '') === $code) {
                return $c;
            }
        }
        $this->fail("check '{$code}' not present in probe output");
    }
}
