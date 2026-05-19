<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Vox;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Vox\Audit\VoxV3HardeningAuditService;
use App\Services\Ai\Vox\VoxSchema;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature coverage for `GET /ai/vox/audit/v3-hardening`.
 *
 * Anti-autoengano contract:
 *   - endpoint serves the canonical schema
 *   - raw_audio / confirmation_bypass / destructive-without-receipt
 *     each force the audit into status=fail when the ledger says so
 *   - missing instrumentation surfaces as `unknown` (never `pass`)
 *   - the endpoint never imports / mutates `app/Services/Ai/Voice/`
 */
final class VoxV3HardeningAuditEndpointTest extends TestCase
{
    /** @var array<string,string> */
    private array $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('atlas.token', 'test-token-with-enough-length-123');
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

    public function test_endpoint_returns_canonical_schema_and_twelve_named_checks(): void
    {
        $response = $this->getJson('/ai/vox/audit/v3-hardening', $this->headers)
            ->assertOk()
            ->assertJsonPath('schema', VoxV3HardeningAuditService::SCHEMA)
            ->assertJsonPath('read_only', true);

        $body = $response->json();
        $this->assertIsArray($body['checks']);
        $names = array_column($body['checks'], 'name');
        $this->assertSame(VoxV3HardeningAuditService::checkNames(), $names);
        $this->assertSame(12, $body['summary']['total']);
    }

    public function test_audit_fails_when_raw_audio_persisted_event_exists(): void
    {
        $this->seedTranscriptWithRawAudio();

        $body = $this->getJson('/ai/vox/audit/v3-hardening', $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'fail')
            ->json();

        $rawAudio = $this->checkByName($body, 'no_raw_audio_persisted');
        $this->assertSame('fail', $rawAudio['status']);
        $this->assertGreaterThanOrEqual(1, $rawAudio['observed']);
        $this->assertSame(0, $rawAudio['expected']);
    }

    public function test_audit_fails_when_confirmation_bypass_event_exists(): void
    {
        $this->seedActionBlocked('confirmation_bypass_attempted');

        $body = $this->getJson('/ai/vox/audit/v3-hardening', $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'fail')
            ->json();

        $check = $this->checkByName($body, 'no_confirmation_bypass');
        $this->assertSame('fail', $check['status']);
        $this->assertGreaterThanOrEqual(1, $check['observed']);
    }

    public function test_audit_fails_when_destructive_action_without_receipt_event_exists(): void
    {
        $this->seedActionBlocked('destructive_action_without_receipt');

        $body = $this->getJson('/ai/vox/audit/v3-hardening', $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'fail')
            ->json();

        $check = $this->checkByName($body, 'no_destructive_action_without_receipt');
        $this->assertSame('fail', $check['status']);
        $this->assertGreaterThanOrEqual(1, $check['observed']);
    }

    public function test_audit_warns_when_a_check_is_unknown_and_no_check_failed(): void
    {
        // mobile_untouched is always `unknown` (outside this repo) — so on
        // an otherwise clean baseline the overall status must be `warn`,
        // never `pass`.
        $body = $this->getJson('/ai/vox/audit/v3-hardening', $this->headers)
            ->assertOk()
            ->json();

        $this->assertSame('warn', $body['status'], 'unknown must promote overall status to warn');
        $mobile = $this->checkByName($body, 'mobile_untouched');
        $this->assertSame('unknown', $mobile['status']);
        $this->assertGreaterThanOrEqual(1, $body['summary']['unknown']);
    }

    public function test_audit_marks_ledger_checks_as_unknown_when_ledger_table_missing(): void
    {
        // Drop the ledger table to simulate fresh sandbox.
        Schema::dropIfExists('atlas_ledger_events');

        $body = $this->getJson('/ai/vox/audit/v3-hardening', $this->headers)
            ->assertOk()
            ->json();

        $this->assertNotSame('pass', $body['status'], 'missing ledger must never yield pass');
        foreach (
            ['no_raw_audio_persisted', 'no_confirmation_bypass', 'no_destructive_action_without_receipt',
                'confirmation_token_not_in_ledger', 'terminal_propose_command_executed_false']
            as $name
        ) {
            $this->assertSame(
                'unknown',
                $this->checkByName($body, $name)['status'],
                "Ledger-backed check `{$name}` should be 'unknown' when ledger table is missing — got: ".json_encode($this->checkByName($body, $name))
            );
        }
    }

    public function test_audit_fails_when_terminal_propose_evidence_marks_command_as_executed(): void
    {
        $this->seedTerminalProposeEvidence(commandExecuted: true);

        $body = $this->getJson('/ai/vox/audit/v3-hardening', $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'fail')
            ->json();

        $check = $this->checkByName($body, 'terminal_propose_command_executed_false');
        $this->assertSame('fail', $check['status']);
        $this->assertGreaterThanOrEqual(1, $check['observed']);
    }

    public function test_audit_passes_terminal_propose_check_when_every_event_is_propose_only(): void
    {
        $this->seedTerminalProposeEvidence(commandExecuted: false);
        $this->seedTerminalProposeEvidence(commandExecuted: false);

        $body = $this->getJson('/ai/vox/audit/v3-hardening', $this->headers)
            ->assertOk()
            ->json();

        $check = $this->checkByName($body, 'terminal_propose_command_executed_false');
        $this->assertSame('pass', $check['status']);
        $this->assertSame(2, $check['events_inspected']);
    }

    public function test_audit_fails_when_confirmation_token_leaks_into_ledger(): void
    {
        $sessionId = (string) Str::uuid();
        AtlasLedgerEvent::create([
            'event_id' => (string) Str::ulid(),
            'tenant_id' => 'default',
            'operator_id' => 'system',
            'envelope_id' => $sessionId,
            'correlation_id' => $sessionId,
            'event_type' => LedgerEventType::VoxConfirmationRequested->value,
            'emitter_stage' => 'atlas.kernel.vox.v0',
            'emitter_version' => 'v1',
            'payload' => [
                'session_id' => $sessionId,
                'confirmation_token' => 'hmac_sha256:leak',
            ],
            'payload_hash' => hash('sha256', 'leak'.$sessionId),
            'occurred_at' => CarbonImmutable::now('UTC'),
        ]);

        $body = $this->getJson('/ai/vox/audit/v3-hardening', $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'fail')
            ->json();

        $check = $this->checkByName($body, 'confirmation_token_not_in_ledger');
        $this->assertSame('fail', $check['status']);
        $this->assertGreaterThanOrEqual(1, $check['observed']);
    }

    public function test_audit_static_checks_pass_on_current_codebase(): void
    {
        $body = $this->getJson('/ai/vox/audit/v3-hardening', $this->headers)
            ->assertOk()
            ->json();

        foreach (
            ['terminal_execute_not_supported', 'voice_realtime_untouched',
                'provider_api_not_added', 'r4_literal_required',
                'governed_execute_requires_receipt', 'v4_not_started']
            as $name
        ) {
            $check = $this->checkByName($body, $name);
            $this->assertSame(
                'pass',
                $check['status'],
                "Static check `{$name}` should pass on the current tree, got: ".json_encode($check)
            );
        }
    }

    public function test_audit_does_not_touch_voice_realtime_directory(): void
    {
        $voiceDir = base_path('app/Services/Ai/Voice');
        if (! is_dir($voiceDir)) {
            $this->markTestSkipped('app/Services/Ai/Voice not present on this build');
        }
        $before = $this->snapshotMtimes($voiceDir);

        $this->getJson('/ai/vox/audit/v3-hardening', $this->headers)->assertOk();

        $after = $this->snapshotMtimes($voiceDir);
        $this->assertSame(
            $before,
            $after,
            'No file under app/Services/Ai/Voice may be mutated by the V3 hardening audit endpoint',
        );
    }

    /**
     * @param  array<string,mixed>  $body
     * @return array<string,mixed>
     */
    private function checkByName(array $body, string $name): array
    {
        foreach ((array) ($body['checks'] ?? []) as $check) {
            if (($check['name'] ?? null) === $name) {
                return $check;
            }
        }
        $this->fail("Audit response is missing check '{$name}'.");
    }

    /** @return array<string,int> */
    private function snapshotMtimes(string $dir): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            if ($f->isFile()) {
                $out[$f->getPathname()] = $f->getMTime();
            }
        }
        ksort($out);

        return $out;
    }

    private function seedTranscriptWithRawAudio(): void
    {
        $sessionId = (string) Str::uuid();
        AtlasLedgerEvent::create([
            'event_id' => (string) Str::ulid(),
            'tenant_id' => 'default',
            'operator_id' => 'system',
            'envelope_id' => $sessionId,
            'correlation_id' => $sessionId,
            'event_type' => LedgerEventType::VoxTranscriptReady->value,
            'emitter_stage' => 'atlas.kernel.vox.v0',
            'emitter_version' => 'v1',
            'payload' => [
                'session_id' => $sessionId,
                'transcript_id' => (string) Str::uuid(),
                'raw_pcm_persisted' => true,
            ],
            'payload_hash' => hash('sha256', 'raw'.$sessionId),
            'occurred_at' => CarbonImmutable::now('UTC'),
        ]);
    }

    private function seedActionBlocked(string $reasonCode): void
    {
        $sessionId = (string) Str::uuid();
        AtlasLedgerEvent::create([
            'event_id' => (string) Str::ulid(),
            'tenant_id' => 'default',
            'operator_id' => 'system',
            'envelope_id' => $sessionId,
            'correlation_id' => $sessionId,
            'event_type' => LedgerEventType::VoxActionBlocked->value,
            'emitter_stage' => 'atlas.kernel.vox.v0',
            'emitter_version' => 'v1',
            'payload' => [
                'session_id' => $sessionId,
                'reason_code' => $reasonCode,
            ],
            'payload_hash' => hash('sha256', $reasonCode.$sessionId),
            'occurred_at' => CarbonImmutable::now('UTC'),
        ]);
    }

    private function seedTerminalProposeEvidence(bool $commandExecuted): void
    {
        $sessionId = (string) Str::uuid();
        AtlasLedgerEvent::create([
            'event_id' => (string) Str::ulid(),
            'tenant_id' => 'default',
            'operator_id' => 'system',
            'envelope_id' => $sessionId,
            'correlation_id' => $sessionId,
            'event_type' => LedgerEventType::VoxEvidenceRecorded->value,
            'emitter_stage' => 'atlas.kernel.vox.v0',
            'emitter_version' => 'v1',
            'payload' => [
                'session_id' => $sessionId,
                'executor' => VoxSchema::EXECUTOR_TERMINAL_PROPOSE,
                'status' => 'completed',
                'metadata' => [
                    'command_executed' => $commandExecuted,
                ],
            ],
            'payload_hash' => hash('sha256', 'tp'.$sessionId.($commandExecuted ? 'y' : 'n')),
            'occurred_at' => CarbonImmutable::now('UTC'),
        ]);
    }
}
