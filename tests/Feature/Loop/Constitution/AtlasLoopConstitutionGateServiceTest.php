<?php

declare(strict_types=1);

namespace Tests\Feature\Loop\Constitution;

use App\Services\Ai\AutonomousEvolution\Constitution\AtlasLoopConstitutionGateService;
use App\Services\Ai\AutonomousEvolution\Constitution\AtlasLoopConstitutionGateToken;
use Tests\TestCase;

/**
 * LOOP-OS · Fase 3 · Slice 4 — the ConstitutionGate service. The config surface is COMPLETE (a monotonic
 * candidate PASSES with a re-verifiable token; a gate-disabling candidate is REJECTED); the JUDGE surface is
 * FAIL-CLOSED until §3.6(ii) lands — it never returns a PASS it cannot back.
 */
final class AtlasLoopConstitutionGateServiceTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    private AtlasLoopConstitutionGateService $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gate = new AtlasLoopConstitutionGateService();
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function config(array $loop): string
    {
        $path = sys_get_temp_dir().'/atlas-gate-cfg-'.bin2hex(random_bytes(4)).'.php';
        $this->files[] = $path;
        file_put_contents($path, '<?php return '.var_export(['ai' => ['loop' => []], 'loop' => $loop], true).';');

        return $path;
    }

    public function test_a_monotonic_config_candidate_passes_with_a_verifiable_token(): void
    {
        $live = $this->config(['boot_smoke_guard' => true, 'value_gate_enabled' => true]);
        $candidate = $this->config(['boot_smoke_guard' => true, 'value_gate_enabled' => true, 'new_feature' => true]);

        $res = $this->gate->admitConfig($live, $candidate, 'treeSha1', 'batteryRoot1', 'nonce-1');

        $this->assertSame('PASS', $res['verdict']);
        $this->assertNotNull($res['token']);
        // the minted token re-verifies against the same tree + battery + nonce (the actuator's merge-time check)
        $v = (new AtlasLoopConstitutionGateToken())->verify($res['token'], 'treeSha1', 'batteryRoot1', 'nonce-1', []);
        $this->assertTrue($v['valid']);
    }

    public function test_a_config_candidate_that_disables_a_safety_gate_is_rejected(): void
    {
        $live = $this->config(['boot_smoke_guard' => true]);
        $candidate = $this->config(['boot_smoke_guard' => false]); // disabling the boot-smoke gate

        $res = $this->gate->admitConfig($live, $candidate, 'treeSha1', 'batteryRoot1', 'nonce-1');

        $this->assertSame('REJECT', $res['verdict']);
        $this->assertNull($res['token'], 'a rejected candidate mints no token ⇒ the actuator can never commit it');
        $this->assertStringContainsString('boot_smoke_guard', $res['violations'][0]);
    }

    public function test_the_judge_surface_is_fail_closed_never_a_false_pass(): void
    {
        // A judge self-edit must NOT pass until the §3.6(ii) judge-execution leg is dischargeable.
        $res = $this->gate->admitJudge('diff', base_path(), '/tmp/battery');
        $this->assertSame('REJECT', $res['verdict']);
        $this->assertNull($res['token']);
        $this->assertStringContainsString('§3.6(ii)', $res['reason']);
    }
}
