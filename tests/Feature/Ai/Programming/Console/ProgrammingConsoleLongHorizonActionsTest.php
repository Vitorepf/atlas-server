<?php

namespace Tests\Feature\Ai\Programming\Console;

use App\Models\AtlasLongHorizonCompactionReceipt;
use App\Models\AtlasLongHorizonContinuationPack;
use App\Services\Ai\LongHorizon\AtlasLongHorizonCanon;
use App\Services\Ai\Programming\Console\ProgrammingConsoleCanon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TEOS-I1 console long-horizon actions.
 *
 * Hard contract for these tests:
 *  - 4 new actions are registered in the canon and dispatched by the command;
 *  - status / continue / certify are READ-ONLY;
 *  - compact defaults to dry_run=true (safe) and only writes when explicitly
 *    opted in via --no-dry-run;
 *  - every envelope carries claim_policy.benchmark_not_run=true.
 */
class ProgrammingConsoleLongHorizonActionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLongHorizonSchema();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_long_horizon_continuation_packs');
        Schema::dropIfExists('atlas_long_horizon_compaction_receipts');
        Schema::dropIfExists('atlas_programming_stage_receipts');
        parent::tearDown();
    }

    public function test_canon_registers_four_long_horizon_actions(): void
    {
        $expected = [
            ProgrammingConsoleCanon::ACTION_LONG_HORIZON_STATUS,
            ProgrammingConsoleCanon::ACTION_LONG_HORIZON_COMPACT,
            ProgrammingConsoleCanon::ACTION_LONG_HORIZON_CONTINUE,
            ProgrammingConsoleCanon::ACTION_LONG_HORIZON_CERTIFY,
        ];
        foreach ($expected as $action) {
            $this->assertContains($action, ProgrammingConsoleCanon::ACTIONS, "canon must register {$action}");
        }
        $this->assertSame('long-horizon:status', ProgrammingConsoleCanon::ACTION_LONG_HORIZON_STATUS);
        $this->assertSame('long-horizon:compact', ProgrammingConsoleCanon::ACTION_LONG_HORIZON_COMPACT);
        $this->assertSame('long-horizon:continue', ProgrammingConsoleCanon::ACTION_LONG_HORIZON_CONTINUE);
        $this->assertSame('long-horizon:certify', ProgrammingConsoleCanon::ACTION_LONG_HORIZON_CERTIFY);
    }

    public function test_long_horizon_status_emits_canonical_envelope_with_pack_inventory(): void
    {
        $this->seedContinuationPack('dev-run-status-1');

        $payload = $this->runConsole('long-horizon:status', [
            '--scope-type' => AtlasLongHorizonCanon::SCOPE_TYPE_DEV_RUN,
            '--scope-id' => 'dev-run-status-1',
        ]);

        $this->assertCanonicalEnvelope($payload);
        $this->assertSame(ProgrammingConsoleCanon::ACTION_LONG_HORIZON_STATUS, $payload['action']);
        $this->assertSame(ProgrammingConsoleCanon::STATUS_GREEN, $payload['status']);
        $this->assertTrue($payload['payload']['continuation_packs']['available']);
        $this->assertSame(1, $payload['payload']['continuation_packs']['count']);
        $this->assertSame(1, $payload['payload']['continuation_packs']['count_for_scope']);
        $this->assertCount(1, $payload['payload']['continuation_packs']['recent']);
        $this->assertFalse($payload['payload']['freshness']['gate_evaluated']);
        $this->assertSame('not_evaluated', $payload['payload']['freshness']['gate_status']);
    }

    public function test_long_horizon_status_reports_partial_when_no_packs_exist(): void
    {
        $payload = $this->runConsole('long-horizon:status');

        $this->assertCanonicalEnvelope($payload);
        $this->assertSame(ProgrammingConsoleCanon::STATUS_PARTIAL, $payload['status']);
        $this->assertTrue($payload['payload']['continuation_packs']['available']);
        $this->assertSame(0, $payload['payload']['continuation_packs']['count']);
    }

    public function test_long_horizon_compact_dry_run_returns_no_attempt_envelope(): void
    {
        $payload = $this->runConsole('long-horizon:compact', [
            '--scope-type' => AtlasLongHorizonCanon::SCOPE_TYPE_DEV_RUN,
            '--scope-id' => 'dev-run-dry-1',
        ]);

        $this->assertCanonicalEnvelope($payload);
        $this->assertSame(ProgrammingConsoleCanon::ACTION_LONG_HORIZON_COMPACT, $payload['action']);
        $this->assertSame(ProgrammingConsoleCanon::STATUS_GREEN, $payload['status']);
        $this->assertFalse($payload['payload']['attempted']);
        $this->assertTrue($payload['payload']['dry_run']);
        $this->assertSame(0, AtlasLongHorizonCompactionReceipt::query()->count(), 'dry_run must not create a receipt');
    }

    public function test_long_horizon_compact_without_scope_type_returns_blocker(): void
    {
        $payload = $this->runConsole('long-horizon:compact');

        $this->assertSame(ProgrammingConsoleCanon::STATUS_BLOCKED, $payload['status']);
        $this->assertNotEmpty($payload['blockers']);
        $this->assertSame('long_horizon_compact:missing_scope_type', $payload['blockers'][0]['id']);
        $this->assertSame(0, AtlasLongHorizonCompactionReceipt::query()->count());
    }

    public function test_long_horizon_continue_without_plan_id_returns_blocker(): void
    {
        $payload = $this->runConsole('long-horizon:continue');

        $this->assertSame(ProgrammingConsoleCanon::STATUS_BLOCKED, $payload['status']);
        $this->assertSame('long_horizon_continue:missing_plan_id', $payload['blockers'][0]['id']);
    }

    public function test_long_horizon_continue_returns_safe_resume_mode_execute_on_clean_state(): void
    {
        $this->bootStageReceiptTable();

        $payload = $this->runConsole('long-horizon:continue', [
            '--plan-id' => 'plan-continue-clean',
            '--scope-type' => AtlasLongHorizonCanon::SCOPE_TYPE_DEV_RUN,
            '--scope-id' => 'dev-run-continue-clean',
        ]);

        $this->assertCanonicalEnvelope($payload);
        $this->assertSame(ProgrammingConsoleCanon::ACTION_LONG_HORIZON_CONTINUE, $payload['action']);
        $this->assertSame(ProgrammingConsoleCanon::STATUS_GREEN, $payload['status']);
        $this->assertSame(
            AtlasLongHorizonCanon::SAFE_RESUME_EXECUTE,
            $payload['payload']['safe_resume_mode'],
        );
        $this->assertFalse($payload['payload']['writes']);
        $this->assertNotSame('', (string) ($payload['payload']['pack_hash'] ?? ''));
    }

    public function test_long_horizon_continue_surfaces_persisted_pack_when_available(): void
    {
        $this->bootStageReceiptTable();
        $existing = $this->seedContinuationPack('dev-run-continue-existing');

        $payload = $this->runConsole('long-horizon:continue', [
            '--plan-id' => 'plan-continue-existing',
            '--scope-type' => AtlasLongHorizonCanon::SCOPE_TYPE_DEV_RUN,
            '--scope-id' => 'dev-run-continue-existing',
        ]);

        $this->assertSame($existing->id, $payload['payload']['continuation_pack_id']);
        $this->assertSame($existing->pack_hash, $payload['payload']['pack_hash']);
        $this->assertSame('persisted', $payload['payload']['pack_source']);
    }

    public function test_long_horizon_certify_returns_structural_checks_and_service_not_shipped_blocker(): void
    {
        $payload = $this->runConsole('long-horizon:certify');

        $this->assertCanonicalEnvelope($payload);
        $this->assertSame(ProgrammingConsoleCanon::ACTION_LONG_HORIZON_CERTIFY, $payload['action']);
        $this->assertSame(ProgrammingConsoleCanon::STATUS_PARTIAL, $payload['status']);
        $this->assertIsArray($payload['payload']['checks']);
        $this->assertGreaterThanOrEqual(4, $payload['payload']['check_count']);
        $this->assertSame('not_shipped', $payload['payload']['continuity_certification_service']);
        $blockerIds = array_column($payload['blockers'], 'id');
        $this->assertContains('long_horizon_certify:service_not_shipped', $blockerIds);
    }

    public function test_every_long_horizon_envelope_carries_benchmark_not_run_true(): void
    {
        $this->bootStageReceiptTable();
        $cases = [
            ['action' => 'long-horizon:status'],
            ['action' => 'long-horizon:compact', '--scope-type' => AtlasLongHorizonCanon::SCOPE_TYPE_DEV_RUN, '--scope-id' => 'dev-run-flag'],
            ['action' => 'long-horizon:continue', '--plan-id' => 'plan-flag'],
            ['action' => 'long-horizon:certify'],
        ];
        foreach ($cases as $case) {
            $action = $case['action'];
            unset($case['action']);
            $payload = $this->runConsole($action, $case);
            $this->assertTrue($payload['claim_policy']['benchmark_not_run'] ?? false, "benchmark_not_run must hold on {$action}");
            $this->assertFalse($payload['claim_policy']['rivals_compared'] ?? true, "rivals_compared must be false on {$action}");
            $this->assertFalse($payload['claim_policy']['rival_provider_invoked'] ?? true, "rival_provider_invoked must be false on {$action}");
        }
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
        $this->assertContains($exit, [0, 1], "unexpected exit code {$exit} for action {$action}");

        return $decoded;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function assertCanonicalEnvelope(array $payload): void
    {
        foreach (ProgrammingConsoleCanon::ENVELOPE_KEYS as $key) {
            $this->assertArrayHasKey($key, $payload, "envelope must declare key {$key}");
        }
        $this->assertSame(ProgrammingConsoleCanon::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertContains($payload['status'], ProgrammingConsoleCanon::STATUSES);
        $this->assertContains($payload['certification_status'], ProgrammingConsoleCanon::CERTIFICATION_STATUSES);
        $this->assertTrue($payload['claim_policy']['benchmark_not_run']);
    }

    private function seedContinuationPack(string $scopeId): AtlasLongHorizonContinuationPack
    {
        $payload = [
            'scope_type' => AtlasLongHorizonCanon::SCOPE_TYPE_DEV_RUN,
            'scope_id' => $scopeId,
            'objective' => 'console long-horizon test pack',
            'state_summary' => 'pack for the console actions',
            'decisions' => [['key' => 'use_canon']],
            'superseded_decisions' => [],
            'open_tasks' => [],
            'completed_tasks' => [],
            'blockers' => [],
            'risks' => [],
            'evidence_refs' => ['receipt:'.$scopeId],
            'context_manifest' => ['files' => []],
            'context_pack_hash' => str_repeat('a', 64),
            'summary_hash' => str_repeat('b', 64),
            'source_receipts' => ['receipt:'.$scopeId],
            'stale_after' => CarbonImmutable::now()->addDay(),
            'safe_resume_mode' => AtlasLongHorizonCanon::SAFE_RESUME_EXECUTE,
            'next_safe_action' => 'resume_stage:plan',
            'human_decisions_required' => [],
            'confidence' => 0.9,
        ];
        $packHash = AtlasLongHorizonContinuationPack::canonicalPackHash($payload + [
            'schema_version' => AtlasLongHorizonCanon::CONTINUATION_PACK_SCHEMA_VERSION,
        ]);

        return AtlasLongHorizonContinuationPack::query()->create(array_merge($payload, [
            'uuid' => (string) Str::uuid(),
            'pack_hash' => $packHash,
        ]));
    }

    private function bootLongHorizonSchema(): void
    {
        Schema::dropIfExists('atlas_long_horizon_continuation_packs');
        Schema::dropIfExists('atlas_long_horizon_compaction_receipts');
        (require database_path('migrations/2026_05_19_040000_create_atlas_long_horizon_continuation_pack_and_compaction_receipt_tables.php'))->up();
    }

    private function bootStageReceiptTable(): void
    {
        Schema::dropIfExists('atlas_programming_stage_receipts');
        Schema::create('atlas_programming_stage_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('receipt_id', 64)->unique();
            $table->string('plan_id', 160)->index();
            $table->string('parent_plan_id', 160)->nullable()->index();
            $table->string('stage', 40)->index();
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->string('status', 32)->index();
            $table->string('input_hash', 64);
            $table->string('output_hash', 64);
            $table->json('evidence_refs_json')->default('[]');
            $table->json('payload_json')->default('{}');
            $table->json('validation_json')->default('{}');
            $table->timestamps();
        });
    }
}
