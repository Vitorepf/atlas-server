<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Vox;

use App\Services\Ai\Vox\Readiness\VoxReadinessService;
use App\Services\Ai\Vox\VoxSchema;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Wave 7.8 (Claude V) · feature contract for /ai/vox/readiness.
 *
 * Hits the route through the full Laravel stack so the test catches
 * binding / middleware / response-shape regressions, not just service
 * behaviour. The probe must never execute a provider CLI; we use temp
 * files + config overrides to assert "configured" without spawning.
 */
final class AtlasAiVoxReadinessControllerTest extends TestCase
{
    private array $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('atlas.token', 'test-token-with-enough-length-123');
        // Materialise ledger so the metrics-backed probes can run.
        Schema::dropIfExists('atlas_ledger_events');
        Schema::dropIfExists('atlas_vox_rivals_cases');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_05_20_010000_create_atlas_vox_rivals_cases_table.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_vox_rivals_cases');
        Schema::dropIfExists('atlas_ledger_events');
        parent::tearDown();
    }

    public function test_readiness_returns_canonical_schema_and_checks(): void
    {
        $response = $this->getJson('/ai/vox/readiness', $this->headers)
            ->assertOk()
            ->assertJsonPath('schema', VoxSchema::READINESS);

        $checks = $response->json('checks');
        $this->assertIsArray($checks);
        $codes = array_column($checks, 'code');
        foreach (VoxReadinessService::CHECK_NAMES as $required) {
            $this->assertContains($required, $codes, "missing required check: {$required}");
        }

        $this->assertIsString($response->json('summary'));
        $this->assertIsArray($response->json('next_actions'));
        $this->assertIsString($response->json('generated_at'));
    }

    public function test_terminal_execute_and_paid_api_are_always_false(): void
    {
        $this->getJson('/ai/vox/readiness', $this->headers)
            ->assertOk()
            ->assertJsonPath('capabilities.terminal_execute', false)
            ->assertJsonPath('capabilities.cloud_stt', false)
            ->assertJsonPath('capabilities.paid_api_required', false);
    }

    public function test_voice_realtime_check_passes(): void
    {
        $response = $this->getJson('/ai/vox/readiness', $this->headers)->assertOk()->json();
        $vr = $this->find($response['checks'], 'voice_realtime_paused');
        $this->assertSame(VoxReadinessService::CHECK_PASSED, $vr['status']);
        $this->assertFalse($vr['blocking']);
    }

    public function test_provider_cli_absent_is_warning_not_block_and_textual_modes_remain_supported(): void
    {
        config()->set('atlas.vox.executors.codex_cli.binary', null);
        config()->set('atlas.vox.executors.claude_cli.binary', null);

        $response = $this->getJson('/ai/vox/readiness', $this->headers)
            ->assertOk()
            ->assertJsonPath('capabilities.dictation', true)
            ->assertJsonPath('capabilities.prompt_polish', true)
            ->assertJsonPath('capabilities.intent_compile', true)
            ->assertJsonPath('capabilities.governed_execute', true)
            // Provider dispatch sub-capability degrades, but textual modes
            // and governed_execute (via terminal_propose / note_capture)
            // stay available.
            ->assertJsonPath('capabilities.governed_execute_provider_dispatch', false);

        $codex = $this->find($response->json('checks'), 'codex_cli_configured_or_unavailable');
        $this->assertSame(VoxReadinessService::CHECK_WARNING, $codex['status']);
        $this->assertFalse($codex['blocking']);

        // Overall status must NOT be 'blocked' just because provider CLI is missing.
        $this->assertNotSame(VoxReadinessService::STATUS_BLOCKED, $response->json('status'));
    }

    public function test_provider_cli_configured_via_config_is_detected_without_spawning(): void
    {
        $tmp = sys_get_temp_dir().'/vox-readiness-cli-'.bin2hex(random_bytes(4));
        file_put_contents($tmp, "#!/bin/sh\necho ok\n");
        chmod($tmp, 0755);
        config()->set('atlas.vox.executors.codex_cli.binary', $tmp);

        try {
            $response = $this->getJson('/ai/vox/readiness', $this->headers)->assertOk();
            $codex = $this->find($response->json('checks'), 'codex_cli_configured_or_unavailable');
            $this->assertSame(VoxReadinessService::CHECK_PASSED, $codex['status']);
            $this->assertSame('true', $codex['detail']['available']);
            $this->assertSame(
                'config + filesystem stat (no execution)',
                $codex['detail']['detection'],
            );
        } finally {
            @unlink($tmp);
        }
    }

    public function test_readiness_does_not_reach_into_voice_realtime_code(): void
    {
        // Smoke: the response body must not leak any reference to
        // Services/Ai/Voice/ or atlas-app/, even though it asserts the
        // boundary by name.
        $body = $this->getJson('/ai/vox/readiness', $this->headers)
            ->assertOk()
            ->getContent();
        $this->assertStringNotContainsString('Services/Ai/Voice', $body);
        $this->assertStringNotContainsString('atlas-app/', $body);
    }

    public function test_readiness_summary_is_human_readable_string(): void
    {
        $summary = $this->getJson('/ai/vox/readiness', $this->headers)
            ->assertOk()
            ->json('summary');
        $this->assertIsString($summary);
        $this->assertNotSame('', trim($summary));
        $this->assertMatchesRegularExpression('/\d+\/\d+/', $summary);
    }

    /**
     * @param  list<array<string,mixed>>  $checks
     * @return array<string,mixed>
     */
    private function find(array $checks, string $code): array
    {
        foreach ($checks as $c) {
            if ((string) ($c['code'] ?? '') === $code) {
                return $c;
            }
        }
        $this->fail("check '{$code}' not present in readiness response");
    }
}
