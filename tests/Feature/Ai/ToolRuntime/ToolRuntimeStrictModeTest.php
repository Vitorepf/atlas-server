<?php

namespace Tests\Feature\Ai\ToolRuntime;

use App\Models\AiToolInvocation;
use App\Models\AiToolReceipt;
use App\Services\Ai\Evidence\AuditEventService;
use App\Services\Ai\ToolRuntime\ToolDefinitionRegistryService;
use App\Services\Ai\ToolRuntime\ToolInvocationService;
use App\Services\Ai\ToolRuntime\ToolPolicyBridgeService;
use App\Services\Ai\ToolRuntime\ToolPolicyEnforcementException;
use App\Services\Ai\ToolRuntime\ToolReceiptEmissionException;
use App\Services\Ai\ToolRuntime\ToolReceiptService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesToolRuntimeTables;
use Tests\TestCase;

/**
 * Atlas Tool Runtime · Policy / Evidence strict-mode hardening.
 *
 * These tests prove the audit-report-driven contract:
 *
 *   - lenient mode (legacy default) still falls back so isolated dev
 *     environments work, BUT every fallback is observable: a structured
 *     `Log::warning` is emitted AND a `tool_policy_bridge_degraded` /
 *     `tool_receipt_bridge_degraded` audit event is recorded when the
 *     Evidence ledger is reachable. No more silent degradation.
 *
 *   - strict mode (production) throws `ToolPolicyEnforcementException` and
 *     `ToolReceiptEmissionException` so callers cannot accidentally close
 *     a tool execution as `succeeded` without an auditable policy decision
 *     and Evidence receipt.
 *
 *   - the strict flag flows from `config('atlas_ai.tool_runtime.strict_mode')`
 *     AND can be forced per-call via `evaluateStrict()` / `emitStrict()`.
 *
 *   - `ToolInvocationService` catches the strict exceptions and converts the
 *     invocation to `blocked` with `error_summary`, so a strict-mode failure
 *     in production never leaves an orphan `succeeded` invocation.
 *
 * Canon: docs/engineering-knowledge-base/atlas-architecture-critical-judgment-report.md
 */
class ToolRuntimeStrictModeTest extends TestCase
{
    use CreatesToolRuntimeTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createToolRuntimeTables();
        $this->dropPolicyAndEvidenceTables();
        $this->createAuditEventsTable();
        config()->set('atlas_ai.tool_runtime.strict_mode', false);
    }

    protected function tearDown(): void
    {
        $this->dropPolicyAndEvidenceTables();
        Schema::dropIfExists('ai_audit_events');
        $this->dropToolRuntimeTables();
        parent::tearDown();
    }

    /* ---------------------------------------------------------------------
     * Policy bridge · lenient mode
     * ------------------------------------------------------------------- */

    public function test_lenient_policy_bridge_returns_fallback_with_degraded_flag_when_tables_absent(): void
    {
        Log::spy();
        $tool = app(ToolDefinitionRegistryService::class)->register(
            ToolRuntimeRegistryUniquenessTest::validToolAttributes('strict_lenient_table_missing'),
        );

        $decision = app(ToolPolicyBridgeService::class)->evaluate($tool);

        $this->assertSame('allow', $decision['decision']);
        $this->assertSame('tool_runtime_fallback', $decision['source']);
        $this->assertTrue($decision['degraded']);
        $this->assertSame('lenient', $decision['mode']);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context): bool => $message === 'atlas.tool_runtime.policy_bridge_degraded')
            ->atLeast()->once();

        $audit = \DB::table('ai_audit_events')
            ->where('event_type', AuditEventService::EVENT_TOOL_POLICY_BRIDGE_DEGRADED)
            ->first();
        $this->assertNotNull($audit, 'audit event for policy bridge degradation must be recorded in lenient mode too');
    }

    public function test_lenient_policy_bridge_returns_require_approval_for_high_risk_authority_when_tables_absent(): void
    {
        Log::spy();
        $attrs = ToolRuntimeRegistryUniquenessTest::validToolAttributes('strict_lenient_high_risk');
        $attrs['authority_group'] = 'external_action';
        $attrs['risk_level'] = 'high';
        $tool = app(ToolDefinitionRegistryService::class)->register($attrs);

        $decision = app(ToolPolicyBridgeService::class)->evaluate($tool);

        $this->assertSame('require_approval', $decision['decision']);
        $this->assertTrue($decision['degraded']);
    }

    /* ---------------------------------------------------------------------
     * Policy bridge · strict mode
     * ------------------------------------------------------------------- */

    public function test_strict_policy_bridge_throws_when_tables_absent(): void
    {
        config()->set('atlas_ai.tool_runtime.strict_mode', true);
        $tool = app(ToolDefinitionRegistryService::class)->register(
            ToolRuntimeRegistryUniquenessTest::validToolAttributes('strict_throw_missing'),
        );

        $this->expectException(ToolPolicyEnforcementException::class);
        $this->expectExceptionMessageMatches('/Strict mode refuses fallback decisions/i');
        app(ToolPolicyBridgeService::class)->evaluate($tool);
    }

    public function test_strict_policy_bridge_records_audit_event_before_throwing(): void
    {
        config()->set('atlas_ai.tool_runtime.strict_mode', true);
        $tool = app(ToolDefinitionRegistryService::class)->register(
            ToolRuntimeRegistryUniquenessTest::validToolAttributes('strict_audit_before_throw'),
        );

        try {
            app(ToolPolicyBridgeService::class)->evaluate($tool);
            $this->fail('expected ToolPolicyEnforcementException');
        } catch (ToolPolicyEnforcementException $e) {
            $this->assertSame('policy_runtime_unavailable', $e->reason);
        }

        $audit = \DB::table('ai_audit_events')
            ->where('event_type', AuditEventService::EVENT_TOOL_POLICY_BRIDGE_DEGRADED)
            ->first();
        $this->assertNotNull($audit);
        $this->assertStringContainsString('"mode":"strict"', (string) $audit->payload);
    }

    public function test_evaluate_strict_forces_throw_even_when_config_is_lenient(): void
    {
        config()->set('atlas_ai.tool_runtime.strict_mode', false);
        $tool = app(ToolDefinitionRegistryService::class)->register(
            ToolRuntimeRegistryUniquenessTest::validToolAttributes('force_strict_per_call'),
        );

        $this->expectException(ToolPolicyEnforcementException::class);
        app(ToolPolicyBridgeService::class)->evaluateStrict($tool);
    }

    /* ---------------------------------------------------------------------
     * Receipt bridge · lenient mode
     * ------------------------------------------------------------------- */

    public function test_lenient_receipt_bridge_emits_local_only_receipt_when_evidence_absent(): void
    {
        Log::spy();
        $tool = app(ToolDefinitionRegistryService::class)->register(
            ToolRuntimeRegistryUniquenessTest::validToolAttributes('strict_lenient_receipt'),
        );
        $invocation = $this->makeInvocation($tool, 'succeeded');

        $receipt = app(ToolReceiptService::class)->emit($invocation, 'succeeded', ['decision' => 'allow']);

        $this->assertInstanceOf(AiToolReceipt::class, $receipt);
        $refs = $receipt->evidence_refs ?? [];
        $this->assertIsArray($refs);
        $this->assertSame('tool_runtime_local_receipt', $refs[0]['kind']);
        $this->assertSame('evidence_runtime_unavailable', $refs[0]['reason']);
        $this->assertTrue($refs[0]['degraded']);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context): bool => $message === 'atlas.tool_runtime.receipt_bridge_degraded')
            ->atLeast()->once();
        $audit = \DB::table('ai_audit_events')
            ->where('event_type', AuditEventService::EVENT_TOOL_RECEIPT_BRIDGE_DEGRADED)
            ->first();
        $this->assertNotNull($audit);
    }

    public function test_lenient_receipt_bridge_returns_evidence_runtime_receipt_when_present(): void
    {
        $this->createEvidenceTablesMinimal();
        $tool = app(ToolDefinitionRegistryService::class)->register(
            ToolRuntimeRegistryUniquenessTest::validToolAttributes('strict_evidence_present'),
        );
        $invocation = $this->makeInvocation($tool, 'succeeded');

        $receipt = app(ToolReceiptService::class)->emit($invocation, 'succeeded', ['decision' => 'allow'], ['demo' => true]);

        $refs = $receipt->evidence_refs ?? [];
        $this->assertSame('evidence_runtime_receipt', $refs[0]['kind']);
        $this->assertFalse($refs[0]['degraded']);
        $this->assertSame(64, strlen((string) $refs[0]['receipt_hash']));
    }

    /* ---------------------------------------------------------------------
     * Receipt bridge · strict mode
     * ------------------------------------------------------------------- */

    public function test_strict_receipt_bridge_throws_when_evidence_table_absent(): void
    {
        config()->set('atlas_ai.tool_runtime.strict_mode', true);
        $tool = app(ToolDefinitionRegistryService::class)->register(
            ToolRuntimeRegistryUniquenessTest::validToolAttributes('strict_receipt_missing'),
        );
        $invocation = $this->makeInvocation($tool, 'succeeded');

        $this->expectException(ToolReceiptEmissionException::class);
        $this->expectExceptionMessageMatches('/Strict mode refuses local-only receipts/i');
        app(ToolReceiptService::class)->emit($invocation, 'succeeded', ['decision' => 'allow']);
    }

    public function test_strict_receipt_bridge_records_audit_event_before_throwing(): void
    {
        config()->set('atlas_ai.tool_runtime.strict_mode', true);
        $tool = app(ToolDefinitionRegistryService::class)->register(
            ToolRuntimeRegistryUniquenessTest::validToolAttributes('strict_receipt_audit'),
        );
        $invocation = $this->makeInvocation($tool, 'succeeded');

        try {
            app(ToolReceiptService::class)->emit($invocation, 'succeeded', ['decision' => 'allow']);
            $this->fail('expected ToolReceiptEmissionException');
        } catch (ToolReceiptEmissionException $e) {
            $this->assertSame('evidence_runtime_unavailable', $e->reason);
        }

        $audit = \DB::table('ai_audit_events')
            ->where('event_type', AuditEventService::EVENT_TOOL_RECEIPT_BRIDGE_DEGRADED)
            ->first();
        $this->assertNotNull($audit);
    }

    public function test_emit_strict_forces_throw_even_when_config_is_lenient(): void
    {
        config()->set('atlas_ai.tool_runtime.strict_mode', false);
        $tool = app(ToolDefinitionRegistryService::class)->register(
            ToolRuntimeRegistryUniquenessTest::validToolAttributes('force_strict_receipt'),
        );
        $invocation = $this->makeInvocation($tool, 'succeeded');

        $this->expectException(ToolReceiptEmissionException::class);
        app(ToolReceiptService::class)->emitStrict($invocation, 'succeeded', ['decision' => 'allow']);
    }

    /* ---------------------------------------------------------------------
     * ToolInvocationService integration · strict mode
     * ------------------------------------------------------------------- */

    public function test_invocation_in_strict_mode_marks_blocked_when_policy_bridge_unavailable(): void
    {
        config()->set('atlas_ai.tool_runtime.strict_mode', true);
        $tool = app(ToolDefinitionRegistryService::class)->register(
            ToolRuntimeRegistryUniquenessTest::validToolAttributes('strict_invoke_policy_missing'),
        );

        $invocation = app(ToolInvocationService::class)->invoke($tool, ['payload' => 'demo']);

        $this->assertSame('blocked', $invocation->invocation_status);
        $this->assertNotNull($invocation->error_summary);
        $this->assertStringContainsString('policy_enforcement_failed', (string) $invocation->error_summary);
        $this->assertStringContainsString('policy_runtime_unavailable', (string) $invocation->error_summary);
    }

    public function test_invocation_in_strict_mode_marks_blocked_when_receipt_bridge_unavailable(): void
    {
        // Policy is reachable; Evidence is missing. We make the Policy bridge
        // succeed by stubbing only the minimal tables it asserts on.
        $this->createPolicyTablesMinimal();
        config()->set('atlas_ai.tool_runtime.strict_mode', true);

        $tool = app(ToolDefinitionRegistryService::class)->register(
            ToolRuntimeRegistryUniquenessTest::validToolAttributes('strict_invoke_receipt_missing'),
        );

        $invocation = app(ToolInvocationService::class)->invoke($tool, ['payload' => 'demo']);

        $this->assertSame('blocked', $invocation->invocation_status);
        $this->assertNotNull($invocation->error_summary);
        $this->assertStringContainsString('receipt_emission_failed', (string) $invocation->error_summary);
    }

    /* ---------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------- */

    private function makeInvocation($tool, string $status): AiToolInvocation
    {
        return AiToolInvocation::query()->create([
            'uuid' => (string) Str::uuid(),
            'tool_definition_id' => $tool->id,
            'capability_id' => null,
            'mission_id' => null,
            'work_order_id' => null,
            'invocation_status' => $status,
            'input_hash' => str_repeat('a', 64),
            'output_hash' => str_repeat('b', 64),
            'policy_decision_ref' => null,
            'evidence_refs' => null,
            'error_summary' => null,
        ]);
    }

    private function createAuditEventsTable(): void
    {
        Schema::dropIfExists('ai_audit_events');
        Schema::create('ai_audit_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.ai.audit_event.v1');
            $table->string('uuid', 64)->unique();
            $table->string('event_type', 80)->index();
            $table->string('target_type', 80)->nullable()->index();
            $table->string('target_id', 64)->nullable()->index();
            $table->string('actor_type', 80)->default('system');
            $table->json('payload');
            $table->string('event_hash', 64)->index();
            $table->uuid('mission_id')->nullable()->index();
            $table->timestamps();
        });
    }

    private function createPolicyTablesMinimal(): void
    {
        Schema::dropIfExists('ai_permission_gates');
        Schema::dropIfExists('ai_policy_profiles');

        Schema::create('ai_policy_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.ai.policy_profile.v1');
            $table->string('uuid', 64)->unique();
            $table->string('scope', 40);
            $table->string('scope_ref', 200);
            $table->string('autonomy', 40);
            $table->string('risk_tolerance', 40);
            $table->json('approval_rules');
            $table->json('budget_envelope_ref')->nullable();
            $table->json('forbidden_actions');
            $table->string('profile_hash', 64)->unique();
            $table->timestamps();
        });

        Schema::create('ai_permission_gates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.ai.permission_gate.v1');
            $table->string('uuid', 64)->unique();
            $table->uuid('mission_id')->nullable()->index();
            $table->uuid('work_order_id')->nullable()->index();
            $table->string('gate_type', 40)->index();
            $table->string('requested_action', 200);
            $table->string('actor_type', 80)->default('system');
            $table->string('decision', 40)->index();
            $table->json('reasons');
            $table->uuid('policy_profile_id')->nullable()->index();
            $table->string('receipt_hash', 64)->unique();
            $table->timestamps();
        });
    }

    private function createEvidenceTablesMinimal(): void
    {
        Schema::dropIfExists('ai_receipts');
        Schema::create('ai_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.ai.receipt.v1');
            $table->string('uuid', 64)->unique();
            $table->string('receipt_type', 40)->index();
            $table->string('target_type', 80)->nullable()->index();
            $table->string('target_id', 64)->nullable()->index();
            $table->uuid('mission_id')->nullable()->index();
            $table->uuid('work_order_id')->nullable()->index();
            $table->string('actor_type', 80)->default('system');
            $table->string('action', 200);
            $table->string('input_hash', 64)->nullable();
            $table->string('output_hash', 64)->nullable();
            $table->json('evidence_refs')->nullable();
            $table->string('status', 40)->default('ok')->index();
            $table->string('receipt_hash', 64)->unique();
            $table->timestamps();
        });
    }

    private function dropPolicyAndEvidenceTables(): void
    {
        Schema::dropIfExists('ai_permission_gates');
        Schema::dropIfExists('ai_policy_profiles');
        Schema::dropIfExists('ai_receipts');
    }
}
