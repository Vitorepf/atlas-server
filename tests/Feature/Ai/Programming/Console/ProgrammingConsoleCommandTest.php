<?php

namespace Tests\Feature\Ai\Programming\Console;

use App\Models\AiForgeIntake;
use App\Services\Ai\Programming\Console\ProgrammingConsoleCanon;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CreatesForgeIntakeTables;
use Tests\TestCase;

class ProgrammingConsoleCommandTest extends TestCase
{
    use CreatesForgeIntakeTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createForgeIntakeTables();
    }

    protected function tearDown(): void
    {
        $this->dropForgeIntakeTables();
        parent::tearDown();
    }

    public function test_status_emits_canonical_envelope_with_required_keys(): void
    {
        $payload = $this->runConsole('status');

        $this->assertCanonicalEnvelope($payload);
        $this->assertSame(ProgrammingConsoleCanon::ACTION_STATUS, $payload['action']);
        $this->assertContains($payload['status'], ProgrammingConsoleCanon::STATUSES);
        $this->assertArrayHasKey('runtime_status', $payload['payload']);
    }

    public function test_dev_plan_with_prompt_returns_green_envelope_and_never_invokes_provider(): void
    {
        $payload = $this->runConsole('dev:plan', [
            '--prompt' => 'corrigir bug medio em servico de dominio com teste vermelho',
            '--flow' => 'atlas_dev',
        ]);

        $this->assertCanonicalEnvelope($payload);
        $this->assertSame(ProgrammingConsoleCanon::ACTION_DEV_PLAN, $payload['action']);
        $this->assertSame(ProgrammingConsoleCanon::STATUS_GREEN, $payload['status']);
        $this->assertSame(ProgrammingConsoleCanon::CORE_DEV, $payload['selected_core']);
        $this->assertSame('atlas_dev', $payload['flow']);
        $this->assertTrue($payload['payload']['plan_only']);
        $this->assertSame('preview', $payload['payload']['mode']);
        $this->assertStringNotContainsStringIgnoringCase('provider', $payload['payload']['normalized_intent'] ?? '');
        $this->assertSame([], $payload['blockers']);
        // Claim policy must always reflect benchmark_not_run = true.
        $this->assertTrue($payload['claim_policy']['benchmark_not_run']);
        $this->assertFalse($payload['claim_policy']['rivals_compared']);
        $this->assertFalse($payload['claim_policy']['rival_provider_invoked']);
    }

    public function test_dev_plan_with_empty_prompt_blocks(): void
    {
        $payload = $this->runConsole('dev:plan', ['--prompt' => '']);

        $this->assertSame(ProgrammingConsoleCanon::STATUS_BLOCKED, $payload['status']);
        $this->assertSame('dev_plan:empty_prompt', $payload['blockers'][0]['id']);
    }

    public function test_forge_intake_dry_run_does_not_create_row(): void
    {
        $before = AiForgeIntake::query()->count();

        $payload = $this->runConsole('forge:intake', [
            '--prompt' => 'Implementar refactor multi-modulo do provider router com sdd e multi-agent fallback.',
            '--dry-run' => true,
        ]);

        $this->assertSame(ProgrammingConsoleCanon::STATUS_GREEN, $payload['status']);
        $this->assertSame(ProgrammingConsoleCanon::ACTION_FORGE_INTAKE, $payload['action']);
        $this->assertTrue($payload['payload']['dry_run']);
        $this->assertFalse($payload['payload']['attempted']);
        $this->assertSame($before, AiForgeIntake::query()->count(), 'dry_run must not write any intake row');
    }

    public function test_forge_intake_creates_canonical_intake_row_and_returns_ids(): void
    {
        $payload = $this->runConsole('forge:intake', [
            '--prompt' => 'Implementar refactor multi-modulo do provider router com sdd e multi-agent fallback.',
            '--workspace-slug' => 'atlas-server',
        ]);

        $this->assertSame(ProgrammingConsoleCanon::ACTION_FORGE_INTAKE, $payload['action']);
        $this->assertSame(ProgrammingConsoleCanon::STATUS_GREEN, $payload['status']);
        $this->assertNotNull($payload['ids']['intake_id']);
        $this->assertNotNull($payload['ids']['intake_uuid']);
        $this->assertSame(64, strlen((string) $payload['ids']['intake_hash']));
        $this->assertGreaterThanOrEqual(1, $payload['payload']['work_packet_count']);
        $this->assertGreaterThanOrEqual(1, $payload['payload']['milestone_count']);

        // The intake row really exists.
        $this->assertTrue(
            AiForgeIntake::query()->where('uuid', $payload['ids']['intake_uuid'])->exists(),
        );

        // evidence_refs must carry the intake reference.
        $this->assertContains(
            'forge_intake:'.$payload['ids']['intake_id'],
            $payload['evidence_refs'],
        );

        // claim_policy invariants
        $this->assertTrue($payload['claim_policy']['benchmark_not_run']);
        $this->assertFalse($payload['claim_policy']['rival_provider_invoked']);
    }

    public function test_forge_intake_with_empty_prompt_blocks_and_records_blocker(): void
    {
        $payload = $this->runConsole('forge:intake', ['--prompt' => '']);

        $this->assertSame(ProgrammingConsoleCanon::STATUS_BLOCKED, $payload['status']);
        $this->assertSame('forge_intake:empty_prompt', $payload['blockers'][0]['id']);
        $this->assertSame(0, AiForgeIntake::query()->count());
    }

    public function test_blockers_action_emits_canonical_envelope(): void
    {
        $payload = $this->runConsole('blockers');

        $this->assertCanonicalEnvelope($payload);
        $this->assertSame(ProgrammingConsoleCanon::ACTION_BLOCKERS, $payload['action']);
        $this->assertContains($payload['status'], [
            ProgrammingConsoleCanon::STATUS_GREEN,
            ProgrammingConsoleCanon::STATUS_BLOCKED,
        ]);
        $this->assertArrayHasKey('count', $payload['payload']);
    }

    public function test_next_actions_action_emits_canonical_envelope(): void
    {
        $payload = $this->runConsole('next-actions');

        $this->assertCanonicalEnvelope($payload);
        $this->assertSame(ProgrammingConsoleCanon::ACTION_NEXT_ACTIONS, $payload['action']);
        $this->assertIsArray($payload['next_actions']);
    }

    public function test_evidence_action_emits_canonical_envelope(): void
    {
        $payload = $this->runConsole('evidence');

        $this->assertCanonicalEnvelope($payload);
        $this->assertSame(ProgrammingConsoleCanon::ACTION_EVIDENCE, $payload['action']);
        $this->assertContains($payload['certification_status'], ProgrammingConsoleCanon::CERTIFICATION_STATUSES);
    }

    public function test_certification_action_emits_canonical_envelope(): void
    {
        $payload = $this->runConsole('certification');

        $this->assertCanonicalEnvelope($payload);
        $this->assertSame(ProgrammingConsoleCanon::ACTION_CERTIFICATION, $payload['action']);
        $this->assertContains($payload['certification_status'], ProgrammingConsoleCanon::CERTIFICATION_STATUSES);
    }

    public function test_telemetry_action_returns_canonical_envelope_with_aggregate_payload(): void
    {
        $payload = $this->runConsole('telemetry');

        $this->assertCanonicalEnvelope($payload);
        $this->assertSame(ProgrammingConsoleCanon::ACTION_TELEMETRY, $payload['action']);
        $this->assertArrayHasKey('total_events', $payload['payload']);
        $this->assertArrayHasKey('distinct_runs', $payload['payload']);
    }

    public function test_smoke_action_runs_readiness_and_wraps_in_envelope(): void
    {
        $payload = $this->runConsole('smoke');

        $this->assertCanonicalEnvelope($payload);
        $this->assertSame(ProgrammingConsoleCanon::ACTION_SMOKE, $payload['action']);
        $this->assertArrayHasKey('readiness_status', $payload['payload']);
        $this->assertArrayHasKey('checks', $payload['payload']);
    }

    public function test_invalid_action_returns_failure(): void
    {
        $exit = Artisan::call('atlas:programming:console', ['action' => 'definitely-not-an-action']);
        $this->assertSame(1, $exit);
    }

    public function test_every_canonical_envelope_carries_benchmark_not_run_true(): void
    {
        foreach ([
            'status',
            'dev:summary',
            'forge:summary',
            'blockers',
            'next-actions',
            'evidence',
            'certification',
            'telemetry',
            'smoke',
        ] as $action) {
            $payload = $this->runConsole($action);
            $this->assertTrue(
                $payload['claim_policy']['benchmark_not_run'] ?? false,
                "claim_policy.benchmark_not_run must be true on action {$action}",
            );
            $this->assertFalse(
                $payload['claim_policy']['rivals_compared'] ?? true,
                "claim_policy.rivals_compared must be false on action {$action}",
            );
            $this->assertFalse(
                $payload['claim_policy']['rival_provider_invoked'] ?? true,
                "claim_policy.rival_provider_invoked must be false on action {$action}",
            );
        }
    }

    public function test_dev_plan_envelope_is_json_stable_for_identical_inputs(): void
    {
        $first = $this->runConsole('dev:plan', ['--prompt' => 'corrigir bug do router']);
        $second = $this->runConsole('dev:plan', ['--prompt' => 'corrigir bug do router']);

        // request_id and generated_at differ between runs — strip them.
        unset($first['ids']['request_id'], $first['ids']['generated_at']);
        unset($second['ids']['request_id'], $second['ids']['generated_at']);

        $this->assertSame($first, $second);
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function runConsole(string $action, array $options = []): array
    {
        $exit = Artisan::call('atlas:programming:console', array_merge(
            ['action' => $action, '--json' => true],
            $options,
        ));
        $output = Artisan::output();
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, "console action {$action} must emit JSON; got: {$output}");

        // Status BLOCKED returns exit 1, others return 0. Both are accepted —
        // the envelope itself is the source of truth.
        $this->assertContains($exit, [0, 1], "unexpected exit code {$exit} for action {$action}");

        return $decoded;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function assertCanonicalEnvelope(array $payload): void
    {
        foreach (ProgrammingConsoleCanon::ENVELOPE_KEYS as $key) {
            $this->assertArrayHasKey(
                $key,
                $payload,
                "envelope must declare key {$key}; got: ".json_encode(array_keys($payload)),
            );
        }
        $this->assertSame(ProgrammingConsoleCanon::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertContains($payload['status'], ProgrammingConsoleCanon::STATUSES);
        $this->assertContains($payload['selected_core'], ProgrammingConsoleCanon::CORES);
        $this->assertContains($payload['certification_status'], ProgrammingConsoleCanon::CERTIFICATION_STATUSES);

        $ids = $payload['ids'];
        $this->assertArrayHasKey('request_id', $ids);
        $this->assertArrayHasKey('generated_at', $ids);
        $this->assertStringStartsWith('apcsl_', (string) $ids['request_id']);

        $this->assertIsArray($payload['evidence_refs']);
        $this->assertIsArray($payload['blockers']);
        $this->assertIsArray($payload['next_actions']);
        $this->assertIsArray($payload['claim_policy']);
        $this->assertTrue($payload['claim_policy']['benchmark_not_run']);
    }
}
